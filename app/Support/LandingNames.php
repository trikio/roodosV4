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
        'Provincia Constitucional del Callao' => 'Callao',
    ];

    private const TRANSMISSIONS = ['automatico' => 'automático', 'manual' => 'manual'];

    private const FUELS = ['diesel' => 'diésel', 'hibrido' => 'híbrido', 'electrico' => 'eléctrico', 'gasolina' => 'gasolina', 'gas' => 'a gas'];

    public static function place(string $stateName): string
    {
        $name = trim($stateName);
        if (isset(self::PLACES[$name])) {
            return self::PLACES[$name];
        }

        $name = preg_replace('/^(Región|Estado|Provincia)\s+(de\s+la\s+|de\s+los\s+|de\s+las\s+|del\s+|de\s+)?/iu', '', $name);

        return preg_replace('/\s+de\s+(Ignacio de la Llave|Ocampo|Zaragoza|Arteaga)$/u', '', $name);
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
