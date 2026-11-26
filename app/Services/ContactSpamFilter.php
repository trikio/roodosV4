<?php

namespace App\Services;

/**
 * Decide si un mensaje del formulario de contacto parece spam escrito por personas o por bots que
 * pasan el campo trampa: ofertas de SEO y marketing, cripto, casinos, textos en otro alfabeto o en
 * inglés (el sitio es para Chile, Perú, Ecuador y México), muchos links, emails desechables o el
 * mismo texto repetido.
 *
 * Devuelve las razones; sin razones, el mensaje es real. Los descartados no se pierden: el
 * controlador los guarda aparte (storage/app/contact/descartados-AAAA-MM.jsonl).
 */
class ContactSpamFilter
{
    private const KEYWORDS = [
        // SEO / marketing en frío
        'seo', 'backlink', 'backlinks', 'guest post', 'link building', 'linkbuilding', 'domain authority',
        'first page of google', 'primera pagina de google', 'primera página de google', 'posicionamiento web',
        'posicionamiento seo', 'rank your website', 'website traffic', 'trafico a tu sitio', 'tráfico a tu sitio',
        'web design services', 'diseño web profesional', 'app development', 'redesign your website',
        'lead generation', 'generacion de leads', 'generación de leads', 'email list', 'base de datos de emails',
        'outsourcing', 'virtual assistant',
        // dinero fácil
        'crypto', 'bitcoin', 'btc', 'usdt', 'forex', 'binance', 'trading bot', 'investment opportunity',
        'casino', 'apuestas', 'betting', 'loan', 'prestamo inmediato', 'préstamo inmediato', 'credito rapido',
        'crédito rápido', 'gana dinero', 'make money',
        // farmacia / adultos
        'viagra', 'cialis', 'pharmacy', 'xxx', 'porn', 'dating', 'escort',
    ];

    private const DISPOSABLE_DOMAINS = [
        'mailinator.com', 'guerrillamail.com', 'sharklasers.com', 'yopmail.com', '10minutemail.com',
        'tempmail.com', 'temp-mail.org', 'trashmail.com', 'getnada.com', 'dispostable.com', 'maildrop.cc',
        'throwawaymail.com', 'fakeinbox.com', 'mohmal.com', 'emailondeck.com',
    ];

    private const ENGLISH = ['the', 'and', 'you', 'your', 'for', 'with', 'are', 'this', 'that', 'we', 'our',
        'have', 'will', 'can', 'would', 'please', 'hello', 'hi', 'website', 'business', 'offer'];

    private const SPANISH = ['el', 'la', 'los', 'las', 'de', 'del', 'que', 'en', 'por', 'para', 'con', 'una',
        'un', 'es', 'mi', 'su', 'hola', 'gracias', 'auto', 'autos', 'quiero', 'tengo', 'nuestro', 'nuestra'];

    /** @param array{name:string,email:string,message:string,website:?string} $m */
    public function reasons(array $m, ?callable $seenBefore = null): array
    {
        $reasons = [];
        $text = ' ' . mb_strtolower($m['name'] . ' ' . ($m['website'] ?? '') . ' ' . $m['message']) . ' ';
        $plain = ' ' . preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) . ' ';

        foreach (self::KEYWORDS as $word) {
            if (str_contains($plain, ' ' . $word . ' ')) {
                $reasons[] = "palabra: {$word}";
                break;
            }
        }

        $links = preg_match_all('~https?://|www\.~i', $m['message']);
        if ($links > 2) {
            $reasons[] = "{$links} links";
        }

        if (preg_match('~https?://|www\.|\.(com|net|ru|xyz)\b~i', $m['name'])) {
            $reasons[] = 'link en el nombre';
        }

        $letters = preg_match_all('/\p{L}/u', $m['message']);
        $foreign = preg_match_all('/[\p{Cyrillic}\p{Han}\p{Arabic}\p{Hangul}\p{Thai}\p{Hiragana}\p{Katakana}]/u', $m['message']);
        if ($letters > 0 && $foreign / $letters > 0.2) {
            $reasons[] = 'otro alfabeto';
        }

        $words = preg_split('/\s+/', trim($plain)) ?: [];
        $english = count(array_intersect($words, self::ENGLISH));
        $spanish = count(array_intersect($words, self::SPANISH));
        if ($english >= 3 && $english > 2 * $spanish) {
            $reasons[] = 'en inglés';
        }

        $domain = mb_strtolower(substr(strrchr($m['email'], '@') ?: '', 1));
        if (in_array($domain, self::DISPOSABLE_DOMAINS, true)) {
            $reasons[] = "email desechable ({$domain})";
        }

        if ($seenBefore && $seenBefore($this->fingerprint($m['message']))) {
            $reasons[] = 'mensaje repetido';
        }

        return $reasons;
    }

    /** Huella del texto sin mayúsculas, espacios ni signos, para detectar el mismo mensaje reenviado. */
    public function fingerprint(string $message): string
    {
        return sha1(preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($message)));
    }
}
