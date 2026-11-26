<?php

namespace Tests\Unit;

use App\Support\LandingNames;
use PHPUnit\Framework\TestCase;

class LandingNamesTest extends TestCase
{
    public function test_regions_get_the_name_people_search_with(): void
    {
        $this->assertSame([
            'Santiago', 'Biobío', 'Los Lagos', 'La Araucanía', "O'Higgins", 'La Libertad', 'Lima', 'Guayas',
            'Ciudad de México', 'Querétaro', 'Veracruz', 'Nuevo León', 'Estado de México', 'Callao',
        ], array_map([LandingNames::class, 'place'], [
            'Región Metropolitana', 'Región del Biobío', 'Región de Los Lagos', 'Región de la Araucanía',
            "Región del Libertador General Bernardo O'Higgins", 'Región de La Libertad', 'Región de Lima', 'Provincia del Guayas',
            'Distrito Federal', 'Estado de Querétaro de Arteaga', 'Estado de Veracruz de Ignacio de la Llave', 'Estado de Nuevo León',
            'Estado de México', 'Provincia Constitucional del Callao',
        ]));
    }

    public function test_transmissions_and_fuels(): void
    {
        $this->assertSame(['automático', 'manual', null], [LandingNames::transmission('Automático'), LandingNames::transmission('Manual'), LandingNames::transmission('')]);
        $this->assertSame(['diésel', 'híbrido', 'eléctrico', 'a gas'], array_map([LandingNames::class, 'fuel'], ['Diesel', 'Híbrido', 'Eléctrico', 'Gas']));
    }
}
