<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * What Google shows of a car landing: its <title> and meta description, written from the cars the
 * landing is really about. Search Console showed pages in positions 4-15 with a 0.5-1.5% CTR whose
 * snippet read "venta Autos usados Nissan Nissan AD Station Wagon Lima - Roodos Autos Peru" and
 * "7197 anuncios para ..." (the count of a broad text search). Now: "Autos usados Nissan AD Station
 * Wagon Lima: 14 avisos, años 1991–2001", with prices where the country's prices are comparable.
 */
class CarLandingSnippet
{
    /** Fewer matching cars than this and no figures are given: they would mislead. */
    public const MIN_CARS = 5;

    private const MAX_TITLE = 65;

    /** Words of old titles that no ad contains: matching every term with them would find nothing. */
    private const NOT_IN_ADS = ['color', 'colores', 'modelos', 'versiones', 'precio', 'precios', 'vendo', 'oferta', 'ofertas', 'baratos', 'baratas', 'economicos', 'economicas'];

    /** "venta Autos usados Nissan Nissan AD Lima" -> "Autos usados Nissan AD Lima". */
    public static function tidy(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title));
        $title = preg_replace('/^venta\s+(?=autos|camionetas|motos|camiones|jeeps|furgones|clasicos|clásicos)/iu', '', $title);

        $words = [];
        foreach (explode(' ', $title) as $word) {
            if ($words !== [] && mb_strtolower(end($words)) === mb_strtolower($word)) {
                continue;
            }
            $words[] = $word;
        }

        return Str::ucfirst(implode(' ', $words));
    }

    /** Words every matching car must contain: the title's specific terms, without the ones no ad has. */
    public static function terms(string $title): string
    {
        $terms = array_filter(
            LandingClassifier::classify($title)['terms'],
            fn ($term) => !in_array($term, self::NOT_IN_ADS, true)
        );

        return implode(' ', $terms);
    }

    /**
     * Figures from a sample of the matching cars ([['price' => .., 'year_int' => ..]]). Prices only
     * where they share one currency (config countries.guide_price_floor; Peru mixes soles and
     * dollars), from the floor up; "from" is the 10th percentile, so one junk price is not the
     * headline.
     *
     * @return array{total: int, from: ?int, median: ?int, year_min: ?int, year_max: ?int}|null
     */
    public static function stats(int $total, array $rows, ?int $priceFloor): ?array
    {
        if ($total < self::MIN_CARS) {
            return null;
        }

        $prices = [];
        $years = [];
        foreach ($rows as $row) {
            $price = (int) ($row['price'] ?? 0);
            if ($priceFloor !== null && $price >= $priceFloor) {
                $prices[] = $price;
            }
            $year = (int) ($row['year_int'] ?? 0);
            if ($year >= 1950 && $year <= (int) date('Y') + 1) {
                $years[] = $year;
            }
        }
        sort($prices);
        sort($years);
        $hasPrices = count($prices) >= self::MIN_CARS;
        $hasYears = count($years) >= self::MIN_CARS;

        return [
            'total' => $total,
            'from' => $hasPrices ? self::percentile($prices, 0.10) : null,
            'median' => $hasPrices ? self::percentile($prices, 0.50) : null,
            // The 5th-95th percentiles: a 1973 classic among 2010s cars is not the range people see.
            'year_min' => $hasYears ? self::percentile($years, 0.05) : null,
            'year_max' => $hasYears ? self::percentile($years, 0.95) : null,
        ];
    }

    /** "Autos usados Chevrolet Camaro Quito: 16 avisos desde $18.900", as long as it fits. */
    public static function title(string $name, ?array $stats, string $currency): string
    {
        if ($stats === null) {
            return $name;
        }

        $count = self::count($stats['total']);
        $candidates = [];
        if ($stats['from'] !== null) {
            $candidates[] = "{$name}: {$count} desde " . self::money($stats['from'], $currency);
        }
        if ($stats['year_min'] !== null && $stats['year_min'] !== $stats['year_max']) {
            $candidates[] = "{$name}: {$count}, años {$stats['year_min']}–{$stats['year_max']}";
        }
        $candidates[] = "{$name}: {$count}";

        foreach ($candidates as $title) {
            if (mb_strlen($title) <= self::MAX_TITLE) {
                return $title;
            }
        }

        return $name;
    }

    /** "16 avisos de Autos usados Chevrolet Camaro Quito desde $18.900 (precio mediano $32.000), años 2010 a 2024. ..." */
    public static function description(string $name, ?array $stats, string $currency): string
    {
        $closing = 'Compara precios de automotoras y particulares y contacta directo al vendedor en Roodos.';
        if ($stats === null) {
            return "{$name}: {$closing}";
        }

        $text = self::count($stats['total']) . ' de ' . $name;
        if ($stats['from'] !== null) {
            $text .= ' desde ' . self::money($stats['from'], $currency) . ' (precio mediano ' . self::money($stats['median'], $currency) . ')';
        }
        if ($stats['year_min'] !== null) {
            $text .= $stats['year_min'] === $stats['year_max']
                ? ", año {$stats['year_min']}"
                : ", años {$stats['year_min']} a {$stats['year_max']}";
        }

        return "{$text}. {$closing}";
    }

    private static function count(int $total): string
    {
        return number_format($total, 0, ',', '.') . ($total === 1 ? ' aviso' : ' avisos');
    }

    private static function money(int $amount, string $currency): string
    {
        return trim($currency) . number_format($amount, 0, ',', '.');
    }

    private static function percentile(array $sorted, float $p): int
    {
        $index = (int) round($p * (count($sorted) - 1));

        return (int) $sorted[$index];
    }
}
