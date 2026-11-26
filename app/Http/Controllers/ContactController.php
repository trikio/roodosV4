<?php

namespace App\Http\Controllers;

use App\Services\ContactSpamFilter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Formulario de "Contacta con nosotros".
 *
 * A Telegram (services.contact) solo llegan mensajes reales. Filtros, sin captcha:
 * - bots: un campo trampa oculto que solo llenan ellos y un tiempo mínimo para llenar el formulario;
 * - spam escrito: ContactSpamFilter (ofertas de SEO, cripto, otro alfabeto, inglés, links, repetidos);
 * - un límite de envíos por IP en la ruta (throttle) y por email (MAX_PER_EMAIL al día);
 * - un tope global de avisos a Telegram (MAX_TELEGRAM_PER_DAY): aunque un bot con muchas IPs pase todo
 *   lo anterior, una noche mala manda como mucho ese número y un único aviso de "tope alcanzado".
 *
 * Todo queda guardado: los reales en storage/app/contact/AAAA-MM.jsonl y los descartados, con la
 * razón, en storage/app/contact/descartados-AAAA-MM.jsonl. A quien envía siempre se le muestra
 * "recibido", para no darle pistas a los spammers.
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

    /** Mensajes reales del mismo email por día; los siguientes se guardan como descartados. */
    private const MAX_PER_EMAIL = 3;

    /** Avisos a Telegram por día, sumando todos los dominios. Los demás quedan solo en el archivo. */
    public const MAX_TELEGRAM_PER_DAY = 10;

    public function show(Request $request): View
    {
        return view('pages.contact', [
            'subjects' => self::SUBJECTS,
            'formToken' => Crypt::encryptString((string) time()),
            'countryCode' => $this->countryCode($request),
        ]);
    }

    public function send(Request $request, ContactSpamFilter $spamFilter): RedirectResponse
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

        if ($request->filled('company_url') || !$this->filledByHuman($request->input('form_token'))) {
            $reasons = ['bot'];
        } else {
            $reasons = $spamFilter->reasons($message, fn (string $fp) => Cache::has('contact:' . $fp));
            if (!$reasons && $this->countToday('email:' . sha1(mb_strtolower($message['email']))) > self::MAX_PER_EMAIL) {
                $reasons = ['más de ' . self::MAX_PER_EMAIL . ' mensajes del mismo email hoy'];
            }
        }

        if ($reasons) {
            $this->store('descartados-', $message + ['reasons' => $reasons]);
        } else {
            $this->store('', $message);
            Cache::put('contact:' . $spamFilter->fingerprint($message['message']), true, now()->addDays(30));
            $sentToday = $this->countToday('telegram');
            if ($sentToday <= self::MAX_TELEGRAM_PER_DAY) {
                $this->notifyTelegram($this->telegramText($message));
            } elseif ($sentToday === self::MAX_TELEGRAM_PER_DAY + 1) {
                $this->notifyTelegram('⚠️ Contacto Roodos: se llegó al tope de ' . self::MAX_TELEGRAM_PER_DAY
                    . ' mensajes hoy. Los que lleguen hasta mañana quedan guardados en storage/app/contact sin avisar.');
            }
        }

        return back()->with('contact_sent', true);
    }

    private function store(string $prefix, array $message): void
    {
        Storage::disk('local')->append(
            'contact/' . $prefix . now()->format('Y-m') . '.jsonl',
            json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
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

    private function telegramText(array $m): string
    {
        return "✉️ Contacto Roodos " . strtoupper($m['country']) . "\n"
            . "Motivo: " . (self::SUBJECTS[$m['subject']] ?? $m['subject']) . "\n"
            . "De: {$m['name']} <{$m['email']}>\n"
            . ($m['website'] ? "Sitio: {$m['website']}\n" : '')
            . "\n" . $m['message'];
    }

    /** Suma uno al contador de hoy y devuelve el valor nuevo. */
    private function countToday(string $name): int
    {
        $key = 'contact:count:' . $name . ':' . now()->format('Y-m-d');
        Cache::add($key, 0, now()->addDays(2));

        return (int) Cache::increment($key);
    }

    private function notifyTelegram(string $text): void
    {
        $token = config('services.contact.telegram_bot_token');
        $chat = config('services.contact.telegram_chat_id');
        if (!$token || !$chat) {
            return;
        }

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
