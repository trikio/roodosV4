<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Formulario de "Contacta con nosotros".
 *
 * Cada mensaje se guarda en storage/app/contact/AAAA-MM.jsonl (no se pierde aunque falle el envío)
 * y se manda al canal de Telegram de services.contact. Contra el spam, sin captcha: un campo trampa
 * oculto que solo llenan los bots, un tiempo mínimo para llenar el formulario y un límite por IP en
 * la ruta (throttle).
 */
class ContactController extends Controller
{
    public const SUBJECTS = [
        'publicar' => 'Quiero publicar mi inventario (feed XML, JSON, API)',
        'aviso' => 'Reportar un aviso (vendido, precio o dato incorrecto)',
        'publicidad' => 'Publicidad',
        'prensa' => 'Prensa',
        'sugerencia' => 'Sugerencia o problema con el sitio',
        'otro' => 'Otro',
    ];

    /** Segundos mínimos entre que se muestra el formulario y se envía (un humano tarda más). */
    private const MIN_SECONDS = 3;

    public function show(Request $request): View
    {
        return view('pages.contact', [
            'subjects' => self::SUBJECTS,
            'formToken' => Crypt::encryptString((string) time()),
            'countryCode' => $this->countryCode($request),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'subject' => ['required', 'in:' . implode(',', array_keys(self::SUBJECTS))],
            'website' => ['nullable', 'string', 'max:200'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ], [
            'name.required' => 'Escribe tu nombre.',
            'email.required' => 'Escribe tu email.',
            'email.email' => 'Ese email no parece válido.',
            'subject.*' => 'Elige un motivo.',
            'message.required' => 'Escribe tu mensaje.',
            'message.min' => 'El mensaje es muy corto.',
            'message.max' => 'El mensaje es muy largo (máximo 5.000 caracteres).',
        ]);

        // Bots: llenan el campo oculto o envían más rápido de lo que alguien alcanza a escribir.
        // Se responde como si se hubiera enviado, para no darles pistas.
        if ($request->filled('company_url') || !$this->filledByHuman($request->input('form_token'))) {
            Log::info('contacto descartado como spam', ['ip' => $request->ip()]);

            return back()->with('contact_sent', true);
        }

        $message = [
            'at' => now()->toIso8601String(),
            'country' => $this->countryCode($request),
            'host' => $request->getHost(),
            'subject' => $data['subject'],
            'name' => $data['name'],
            'email' => $data['email'],
            'website' => $data['website'] ?? null,
            'message' => $data['message'],
            'ip' => $request->ip(),
            'referer' => $request->headers->get('referer'),
        ];

        Storage::disk('local')->append(
            'contact/' . now()->format('Y-m') . '.jsonl',
            json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->notifyTelegram($message);

        return back()->with('contact_sent', true);
    }

    private function filledByHuman(?string $token): bool
    {
        if (!$token) {
            return false;
        }
        try {
            $shownAt = (int) Crypt::decryptString($token);
        } catch (DecryptException) {
            return false;
        }

        return time() - $shownAt >= self::MIN_SECONDS;
    }

    private function notifyTelegram(array $m): void
    {
        $token = config('services.contact.telegram_bot_token');
        $chat = config('services.contact.telegram_chat_id');
        if (!$token || !$chat) {
            return;
        }

        $text = "✉️ Contacto Roodos " . strtoupper($m['country']) . "\n"
            . "Motivo: " . (self::SUBJECTS[$m['subject']] ?? $m['subject']) . "\n"
            . "De: {$m['name']} <{$m['email']}>\n"
            . ($m['website'] ? "Sitio: {$m['website']}\n" : '')
            . "\n" . $m['message'];

        try {
            Http::timeout(10)->asForm()->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chat,
                'text' => mb_substr($text, 0, 4000),
                'disable_web_page_preview' => 'true',
            ])->throw();
        } catch (\Throwable $e) {
            // El mensaje ya quedó guardado en storage/app/contact.
            Log::warning('contacto: no se pudo avisar por Telegram', ['error' => $e->getMessage()]);
        }
    }

    private function countryCode(Request $request): string
    {
        if (preg_match('/^(?:autos|casas)\.roodos\.([a-z]+)$/', $request->getHost(), $m)) {
            return $m[1];
        }

        return 'com';
    }
}
