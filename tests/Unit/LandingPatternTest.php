<?php

namespace Tests\Unit;

use App\Services\ManticoreSearchService;
use App\Support\LandingPattern;
use App\Support\LandingVocabulary;
use Mockery;
use Tests\TestCase;

class LandingPatternTest extends TestCase
{
    private function pattern(string $country = 'cl'): LandingPattern
    {
        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('carVocabulary')->andReturn([
            'makes' => ['hyundai', 'mazda', 'mercedes-benz', 'renault', 'suzuki', 'bmw', 'fiat', 'peugeot', 'kia'],
            'models' => ['hyundai-sonata', 'mazda-3', 'mercedes-benz-clase-c', 'renault-megane', 'suzuki-grand-vitara',
                'bmw-serie-3', 'fiat-bravo', 'kia-cerato'],
            'places' => ['Puente Alto', 'Santiago', 'Viña del Mar'],
        ]);

        return new LandingPattern(LandingVocabulary::forCountry($manticore, $country), $country);
    }

    public function test_titles_with_the_generator_shapes(): void
    {
        $pattern = $this->pattern();

        $this->assertSame('venta <tipo> <condicion> <marca> <modelo> <ciudad>', $pattern->shape('venta Autos usados Hyundai Sonata Puente Alto'));
        $this->assertSame('<marca> <modelo> <color> <ano>', $pattern->shape('Suzuki Grand Vitara gris oscuro 2014'));
        $this->assertSame('<pais> <tipo> <ciudad> <condicion>', $pattern->shape('Chile Autos Viña del Mar usados'));
        $this->assertSame('venta de <tipo> <condicion> en <ciudad>', $pattern->shape('venta de Camiones usados en Santiago'));
        $this->assertSame('compra y venta de <tipo>', $pattern->shape('compra y venta de Jeeps'));
        $this->assertSame('<tipo> <modelo> <condicion> <ciudad>', $pattern->shape('Clasicos de Coleccion Megane usados Santiago'));
    }

    public function test_older_variants_repeated_makes_and_versions(): void
    {
        $pattern = $this->pattern();

        $this->assertSame('<marca> <modelo> <ano> <color>', $pattern->shape('renault megane 2004 rojo'));
        $this->assertSame('<marca> <modelo> <ciudad>', $pattern->shape('kia cerato santiago'));
        $this->assertSame('<modelo> <marca> <ano>', $pattern->shape('cerato Kia 2007'));
        $this->assertSame('venta <tipo> <condicion> <marca> <modelo> <ciudad>', $pattern->shape('venta Autos usados Mazda Mazda 3 Santiago'));
        $this->assertSame('<marca> <modelo> <ano>', $pattern->shape('BMW Serie 3 320i 2003'));
        $this->assertSame('<marca> <modelo>', $pattern->shape('Mercedes-Benz Clase C'));
        $this->assertSame('<tipo> <ano> <condicion> <ciudad>', $pattern->shape('Autos 2005 usados Santiago'));
        $this->assertSame('<tipo> <modelo> <condicion> <ciudad>', $pattern->shape('Autos Megane usados Puente Alto Santiago'));
    }

    public function test_crossed_words_have_no_shape(): void
    {
        $pattern = $this->pattern();

        $this->assertNull($pattern->shape('Marca 185000 Recorrido Renault'));
        $this->assertNull($pattern->shape('Haice Diesel del Tipo vehículo'));
        $this->assertNull($pattern->shape('precio espejo kia'));
        // A model of another make.
        $this->assertNull($pattern->shape('Peugeot Bravo 2012'));
        // Another country's name.
        $this->assertNull($pattern->shape('Autos usados Ecuador'));
        $this->assertSame('<tipo> <condicion> <pais>', $this->pattern('ec')->shape('Autos usados Ecuador'));
        // A year is never a version: "Megane 2011 2.2" is not "<modelo>".
        $this->assertNull($pattern->shape('megane 2011 2.2'));
        $this->assertNull($pattern->shape(''));
    }
}
