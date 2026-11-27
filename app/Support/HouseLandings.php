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
}
