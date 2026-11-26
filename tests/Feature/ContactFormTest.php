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

    private function postWithoutIpLimit(array $data)
    {
        return $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)
            ->from(self::URL)->post(self::URL, $data);
    }

    private function discardedLines(): array
    {
        $file = 'contact/descartados-' . now()->format('Y-m') . '.jsonl';

        return Storage::disk('local')->exists($file)
            ? array_values(array_filter(explode("\n", Storage::disk('local')->get($file))))
            : [];
    }

    public static function spamMessages(): array
    {
        return [
            'oferta SEO en inglés' => ['Hello, we can get your website on the first page of Google with our SEO services. Please reply.'],
            'oferta SEO en español' => ['Hola, ofrecemos posicionamiento web y backlinks para su sitio a muy buen precio.'],
            'cripto' => ['Gana dinero con bitcoin desde tu casa, escríbeme por WhatsApp ya mismo.'],
            'otro alfabeto' => ['Здравствуйте, хотим предложить вам сотрудничество по продвижению сайта.'],
            'muchos links' => ['Mira https://a.example https://b.example https://c.example y www.d.example ahora.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('spamMessages')]
    public function test_written_spam_is_kept_apart_and_not_sent(string $text): void
    {
        $this->postWithoutIpLimit($this->valid(['message' => $text]))->assertSessionHas('contact_sent', true);

        $this->assertCount(0, $this->storedLines());
        $this->assertCount(1, $this->discardedLines());
        $this->assertNotEmpty(json_decode($this->discardedLines()[0], true)['reasons']);
        Http::assertNothingSent();
    }

    public function test_disposable_email_is_discarded(): void
    {
        $this->postWithoutIpLimit($this->valid(['email' => 'x@mailinator.com']));

        $this->assertCount(0, $this->storedLines());
        Http::assertNothingSent();
    }

    public function test_real_messages_in_spanish_pass_the_filter(): void
    {
        foreach ([
            'Hola, el Toyota Corolla del link ya se vendió, por favor sáquenlo.',
            'Somos una automotora de Quito con 80 autos, ¿cómo publicamos el inventario?',
            'El precio del aviso está mal, dice 10 millones y son 1 millón.',
        ] as $i => $text) {
            $this->postWithoutIpLimit($this->valid(['message' => $text, 'email' => "persona{$i}@example.com"]));
        }

        $this->assertCount(3, $this->storedLines());
        Http::assertSentCount(3);
    }

    public function test_the_same_text_is_sent_only_once(): void
    {
        $this->postWithoutIpLimit($this->valid(['email' => 'a@example.com']));
        $this->postWithoutIpLimit($this->valid(['email' => 'b@example.com']));

        $this->assertCount(1, $this->storedLines());
        $this->assertSame(['mensaje repetido'], json_decode($this->discardedLines()[0], true)['reasons']);
        Http::assertSentCount(1);
    }

    public function test_more_than_three_messages_per_email_per_day_are_discarded(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postWithoutIpLimit($this->valid(['message' => "Consulta número {$i} sobre un auto publicado."]));
        }

        $this->assertCount(3, $this->storedLines());
        $this->assertCount(2, $this->discardedLines());
        Http::assertSentCount(3);
    }

    public function test_telegram_gets_at_most_the_daily_cap_plus_one_notice(): void
    {
        $cap = \App\Http\Controllers\ContactController::MAX_TELEGRAM_PER_DAY;
        for ($i = 1; $i <= $cap + 15; $i++) {
            $this->postWithoutIpLimit($this->valid([
                'email' => "persona{$i}@example.com",
                'message' => "Consulta {$i}: quiero saber si el auto {$i} sigue disponible.",
            ]));
        }

        $this->assertCount($cap + 15, $this->storedLines()); // ninguno se pierde
        Http::assertSentCount($cap + 1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'se llegó al tope'));
    }
}
