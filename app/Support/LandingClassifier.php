<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Reads what a car landing is about from its title and slug. Landings are legacy data built from
 * ad titles: ids are guessed, titles carry phone numbers, and many landings ask for the same cars
 * with different words. Shared by the landing pages and the landings:clean command.
 */
class LandingClassifier
{
    /** Vehicle type words. A title without one is about "autos". */
    const TYPE_WORDS = [
        'autos' => 'autos', 'auto' => 'autos', 'carros' => 'autos', 'carro' => 'autos', 'vehiculos' => 'autos', 'vehiculo' => 'autos',
        'camionetas' => 'camionetas', 'camioneta' => 'camionetas',
        'motos' => 'motos', 'moto' => 'motos',
        '4x4' => '4x4',
        // Singular "jeep" is the make, so only the plural is a type.
        'jeeps' => 'jeeps',
        'camiones' => 'camiones', 'camion' => 'camiones',
        'buses' => 'buses', 'bus' => 'buses',
        'clasicos' => 'clasicos', 'clasico' => 'clasicos',
        'furgonetas' => 'furgonetas', 'furgoneta' => 'furgonetas',
    ];

    /** Condition words. A title without one is about used cars, which is all the site lists. */
    const CONDITION_WORDS = [
        'usados' => 'usados', 'usado' => 'usados', 'usadas' => 'usados', 'usada' => 'usados',
        'nuevos' => 'nuevos', 'nuevo' => 'nuevos', 'nuevas' => 'nuevos', 'nueva' => 'nuevos',
        'seminuevos' => 'seminuevos', 'seminuevo' => 'seminuevos', 'seminuevas' => 'seminuevos', 'seminueva' => 'seminuevos',
    ];

    /** Words that do not narrow down which cars a landing is about, including scraped ad labels. */
    const GENERIC_WORDS = [
        'venta', 'ventas', 'en', 'de', 'del', 'la', 'el', 'los', 'las', 'y', 'a', 'para', 'por', 'con',
        'marca', 'modelo', 'version', 'motor', 'ano', 'tipo', 'coleccion',
        'ecuador', 'peru', 'chile', 'mexico', 'laboratorio',
    ];

    public static function words(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', Str::lower(Str::ascii($text)), -1, PREG_SPLIT_NO_EMPTY);
    }

    public static function slugTokens(string $slug): array
    {
        return preg_split('/[^a-z0-9]+/', strtolower($slug), -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * @return array{type: string, condition: string, terms: string[]}
     */
    public static function classify(string $title): array
    {
        $type = null;
        $condition = null;
        $terms = [];

        foreach (self::words($title) as $word) {
            if (isset(self::TYPE_WORDS[$word])) {
                $type ??= self::TYPE_WORDS[$word];
            } elseif (isset(self::CONDITION_WORDS[$word])) {
                $condition ??= self::CONDITION_WORDS[$word];
            } elseif (!in_array($word, self::GENERIC_WORDS, true)) {
                $terms[] = $word;
            }
        }

        $terms = array_values(array_unique($terms));

        return ['type' => $type ?? 'autos', 'condition' => $condition ?? 'usados', 'terms' => $terms];
    }

    /**
     * Landings with the same key list the same cars: "autos-honda", "honda-ecuador",
     * "venta-autos-usados-honda" and "autos-honda-usados" all share "autos|usados|honda".
     */
    public static function groupKey(string $title): string
    {
        $info = self::classify($title);
        $terms = $info['terms'];
        sort($terms);

        return $info['type'] . '|' . $info['condition'] . '|' . implode(' ', $terms);
    }

    public static function isAutosUsados(string $title): bool
    {
        $info = self::classify($title);

        return $info['type'] === 'autos' && $info['condition'] === 'usados';
    }

    /**
     * Why a title is not worth a landing (contact data or a price copied from an ad), or null.
     */
    public static function junkReason(string $title): ?string
    {
        if (preg_match('/\d{7,}/', $title) || preg_match('/\b0\d{1,2}[\s.\-]\d{3}[\s.\-]\d{3,4}\b/', $title)) {
            return 'telefono';
        }
        if (preg_match('/(https?:|www\.|\.com\b|@|hotmail|gmail)/i', $title)) {
            return 'email_url';
        }
        if (preg_match('/u\$s|\$\s?\d|\bs\/\.?\s?\d/i', $title)) {
            return 'precio';
        }

        return null;
    }

    /**
     * Whether the slug really names the breadcrumb make and model. Indexed ids were guessed from ad
     * titles ("$100 telefono..." became Audi 100), so they are trusted only when the slug mentions
     * the make, or a model name that is not just a number.
     *
     * @return array{make: bool, model: bool}
     */
    public static function confirmedNames(string $slug, string $makeSlug, string $modelSlug): array
    {
        $slugTokens = self::slugTokens($slug);
        $makeTokens = self::slugTokens($makeSlug);
        $modelTokens = array_values(array_diff(self::slugTokens($modelSlug), $makeTokens));

        $make = $makeTokens !== [] && array_diff($makeTokens, $slugTokens) === [];
        $model = $modelTokens !== []
            && array_diff($modelTokens, $slugTokens) === []
            && ($make || array_filter($modelTokens, fn ($token) => !ctype_digit($token)) !== []);

        return ['make' => $make, 'model' => $model];
    }

    /**
     * Make/model slugs of the landing breadcrumb, stored as JSON in its content.
     *
     * @return array{make: string, model: string}
     */
    public static function breadcrumbSlugs(mixed $content): array
    {
        if (is_string($content)) {
            $decoded = json_decode(trim($content), true);
            // Some rows hold the JSON double encoded.
            $content = is_string($decoded) ? json_decode($decoded, true) : $decoded;
        }

        $breadcrumb = is_array($content) ? ($content['breadcrumb'] ?? null) : null;
        if (!is_array($breadcrumb)) {
            return ['make' => '', 'model' => ''];
        }

        // Older rows store the breadcrumb as a list of {make}/{model} entries.
        if (!array_key_exists('make', $breadcrumb) && !array_key_exists('model', $breadcrumb)) {
            $entries = $breadcrumb;
            $breadcrumb = [];
            foreach ($entries as $entry) {
                foreach (['make', 'model'] as $key) {
                    if (empty($breadcrumb[$key]) && is_array($entry) && !empty($entry[$key])) {
                        $breadcrumb[$key] = $entry[$key];
                    }
                }
            }
        }

        return [
            'make' => trim((string) ($breadcrumb['make'] ?? '')),
            'model' => trim((string) ($breadcrumb['model'] ?? '')),
        ];
    }
}
