<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    private const URL = 'http://autos.roodos.cl/contacta-con-nosotros';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config([
            'services.contact.telegram_bot_token' => 'TEST',
            'services.contact.telegram_chat_id' => '123',
        ]);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function valid(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ana Pérez',
            'email' => 'ana@example.com',
            'subject' => 'publicar',
            'website' => 'https://automotora.example',
            'message' => 'Queremos publicar nuestro inventario de 120 autos.',
            'form_token' => Crypt::encryptString((string) (time() - 30)),
        ], $overrides);
    }

    private function storedLines(): array
    {
        $file = 'contact/' . now()->format('Y-m') . '.jsonl';

        return Storage::disk('local')->exists($file)
            ? array_values(array_filter(explode("\n", Storage::disk('local')->get($file))))
            : [];
    }

    public function test_page_shows_the_form_in_every_domain(): void
    {
        foreach (['http://autos.roodos.cl', 'http://casas.roodos.mx', 'http://roodos.com'] as $host) {
            $this->get($host . '/contacta-con-nosotros')
                ->assertOk()
                ->assertSee('Contacta con nosotros')
                ->assertSee('name="form_token"', false)
                ->assertSee('Enviar mensaje');
        }
    }

    public function test_valid_message_is_stored_and_sent_to_telegram(): void
    {
        $this->from(self::URL)->post(self::URL, $this->valid())
            ->assertRedirect(self::URL)
            ->assertSessionHas('contact_sent', true);

        $lines = $this->storedLines();
        $this->assertCount(1, $lines);
        $saved = json_decode($lines[0], true);
        $this->assertSame('cl', $saved['country']);
        $this->assertSame('ana@example.com', $saved['email']);
        $this->assertSame('publicar', $saved['subject']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/botTEST/sendMessage')
            && $request['chat_id'] === '123'
            && str_contains($request['text'], 'Contacto Roodos CL')
            && str_contains($request['text'], 'ana@example.com')
            && str_contains($request['text'], 'Queremos publicar'));
    }

    public function test_message_is_kept_when_telegram_fails(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);

        $this->from(self::URL)->post(self::URL, $this->valid())
            ->assertSessionHas('contact_sent', true);

        $this->assertCount(1, $this->storedLines());
    }

    public function test_invalid_input_returns_errors_in_spanish(): void
    {
        $this->from(self::URL)->post(self::URL, $this->valid(['email' => 'no-es-email', 'message' => 'hola']))
            ->assertRedirect(self::URL)
            ->assertSessionHasErrors(['email', 'message']);

        $this->assertSame('Ese email no parece válido.', session('errors')->first('email'));
        $this->assertCount(0, $this->storedLines());
        Http::assertNothingSent();
    }

    public function test_honeypot_and_too_fast_submissions_are_dropped_silently(): void
    {
        $this->from(self::URL)->post(self::URL, $this->valid(['company_url' => 'http://spam.example']))
            ->assertSessionHas('contact_sent', true);
        $this->from(self::URL)->post(self::URL, $this->valid(['form_token' => Crypt::encryptString((string) time())]))
            ->assertSessionHas('contact_sent', true);
        $this->from(self::URL)->post(self::URL, $this->valid(['form_token' => 'basura']))
            ->assertSessionHas('contact_sent', true);

        $this->assertCount(0, $this->storedLines());
        Http::assertNothingSent();
    }

    public function test_submissions_are_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->from(self::URL)->post(self::URL, $this->valid())->assertRedirect(self::URL);
        }
        $this->from(self::URL)->post(self::URL, $this->valid())->assertStatus(429);
    }
}
