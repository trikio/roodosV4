<?php

namespace App\Support;

/**
 * How the landings created from the inventory name a transmission, a fuel or a region: the words
 * people search with ("automático", "diésel", "Santiago"), not the catalog names ("Región
 * Metropolitana", "Estado de Querétaro de Arteaga").
 */
class LandingNames
{
    /** Regions known by another name, or whose catalog name is too long to search for. */
    private const PLACES = [
        'Región Metropolitana' => 'Santiago',
        "Región del Libertador General Bernardo O'Higgins" => "O'Higgins",
        'Región Aisén del General Carlos Ibáñez del Campo' => 'Aysén',
        'Región de Magallanes y de la Antártica Chilena' => 'Magallanes',
        'Distrito Federal' => 'Ciudad de México',
        'Estado de México' => 'Estado de México',
        'Estado de Veracruz-Llave' => 'Veracruz',
        'Provincia Constitucional del Callao' => 'Callao',
    ];

    /**
     * Regions with no city of their name: an ad placed in "Nayarit, Nayarit" only named its region
     * (the location normalizer matched a village called the same), so no city landing is made.
     */
    private const REGIONS_WITHOUT_SUCH_CITY = [
        'mx' => ['baja-california', 'baja-california-sur', 'chiapas', 'coahuila', 'estado-de-mexico', 'guerrero', 'hidalgo',
            'jalisco', 'mexico', 'michoacan', 'morelos', 'nayarit', 'nuevo-leon', 'quintana-roo', 'sinaloa', 'sonora',
            'tabasco', 'tamaulipas', 'yucatan'],
    ];

    private const TRANSMISSIONS = ['automatico' => 'automático', 'manual' => 'manual'];

    private const FUELS = ['diesel' => 'diésel', 'hibrido' => 'híbrido', 'electrico' => 'eléctrico', 'gasolina' => 'gasolina', 'gas' => 'a gas'];

    public static function place(string $stateName): string
    {
        $name = trim($stateName);
        if (isset(self::PLACES[$name])) {
            return self::PLACES[$name];
        }

        // "Región de Los Lagos" is Los Lagos and "Región de La Libertad" La Libertad: only "de"/"del" go.
        $name = preg_replace('/^(Región|Estado|Provincia)\s+(del\s+|de\s+)?/iu', '', $name);
        $name = preg_replace('/\s+de\s+(Ignacio de la Llave|Ocampo|Zaragoza|Arteaga)$/u', '', $name);

        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    /** Whether a region of this slug has a real city named like it (Querétaro does, Nayarit does not). */
    public static function regionHasCityOfItsName(string $country, string $regionSlug): bool
    {
        return !in_array($regionSlug, self::REGIONS_WITHOUT_SUCH_CITY[strtolower($country)] ?? [], true);
    }

    public static function transmission(string $name): ?string
    {
        return self::TRANSMISSIONS[self::key($name)] ?? null;
    }

    public static function fuel(string $name): ?string
    {
        return self::FUELS[self::key($name)] ?? null;
    }

    private static function key(string $name): string
    {
        return implode(' ', LandingClassifier::words($name));
    }
}
