<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Names and filters of the house landings created from the inventory (house-landings:candidates):
 * "Departamentos en venta en Coyoacán" at /departamentos-en-venta-en-coyoacan, listing exactly the
 * ads of that operation, type and place.
 */
class HouseLandings
{
    /** "Casa" -> "casas", "Nave Industrial" -> "naves industriales", "Local" -> "locales". */
    public static function plural(string $type): string
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($type)), -1, PREG_SPLIT_NO_EMPTY);

        return implode(' ', array_map(fn ($word) => match (true) {
            (bool) preg_match('/[aeiouáéó]$/u', $word) => $word . 's',
            str_ends_with($word, 'z') => mb_substr($word, 0, -1) . 'ces',
            str_ends_with($word, 's') => $word,
            default => $word . 'es',
        }, $words));
    }

    /** "Departamentos en venta", "Casas en renta en Mérida". */
    public static function title(string $operation, string $type, ?string $place = null): string
    {
        $title = Str::ucfirst(self::plural($type)) . ' en ' . mb_strtolower(trim($operation));

        return $place !== null && $place !== '' ? $title . ' en ' . $place : $title;
    }

    /** A city as written in the catalog, with its first letter in upper case. */
    public static function city(string $name): string
    {
        return Str::ucfirst(trim($name));
    }

    /**
     * The exact search of a landing created from the inventory, as house search options
     * (operation, type, and state or city), or null for any other landing.
     */
    public static function generatedFilters(?string $content): ?array
    {
        $generated = json_decode((string) $content, true)['generated'] ?? null;
        if (!is_array($generated) || empty($generated['operation']) || empty($generated['type'])) {
            return null;
        }

        return [
            'operation' => (int) $generated['operation'],
            'type' => (int) $generated['type'],
            'state' => (int) ($generated['state'] ?? 0) ?: '',
            'city' => (int) ($generated['city'] ?? 0) ?: '',
        ];
    }

    /**
     * Search result title of a landing made from the inventory: "Departamentos en venta en Miguel
     * Hidalgo: 3.095 avisos".
     */
    public static function snippetTitle(string $title, int $total): string
    {
        return $total > 0 ? $title . ': ' . number_format($total, 0, ',', '.') . ($total === 1 ? ' aviso' : ' avisos') : $title;
    }

    /**
     * "3.095 departamentos en venta en Miguel Hidalgo. Precio mediano $8.500.000; $95.000 por m²
     * (la mitad entre $70.000 y $120.000); superficie mediana 110 m². ..."
     * Per m² only when prices are larger than sizes (Chile's UF prices are not).
     */
    public static function snippetDescription(string $title, int $total, ?array $stats, string $currency): string
    {
        $money = fn (int $amount) => trim($currency) . number_format($amount, 0, ',', '.');
        $text = $total > 0 ? number_format($total, 0, ',', '.') . ' ' . mb_strtolower(mb_substr($title, 0, 1)) . mb_substr($title, 1) . '.' : $title . '.';
        if ($stats !== null) {
            $text .= ' Precio mediano ' . $money($stats['price']);
            if ($stats['per_m2'] !== null && $stats['size'] !== null && $stats['price'] >= $stats['size']) {
                $text .= '; ' . $money($stats['per_m2']['median']) . ' por m² (la mitad entre ' . $money($stats['per_m2']['low'])
                    . ' y ' . $money($stats['per_m2']['high']) . ')';
            }
            if ($stats['size'] !== null) {
                $text .= '; superficie mediana ' . number_format($stats['size'], 0, ',', '.') . ' m²';
            }
            $text .= '.';
        }

        return $text . ' Compara avisos de inmobiliarias y particulares en Roodos.';
    }
}
