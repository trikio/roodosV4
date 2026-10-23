<?php

namespace Tests\Unit;

use App\Support\LandingClassifier;
use PHPUnit\Framework\TestCase;

class LandingClassifierTest extends TestCase
{
    public function test_same_cars_with_different_words_share_a_group(): void
    {
        $key = LandingClassifier::groupKey('Autos Honda usados');

        $this->assertSame('autos|usados|honda', $key);
        foreach (['Autos Honda', 'Honda Ecuador', 'venta Autos usados Honda', 'Honda Honda', 'Marca Honda'] as $title) {
            $this->assertSame($key, LandingClassifier::groupKey($title), $title);
        }
    }

    public function test_vehicle_type_and_condition_split_groups(): void
    {
        $this->assertSame('camionetas|usados|honda', LandingClassifier::groupKey('Camionetas Honda usadas'));
        $this->assertSame('motos|usados|honda', LandingClassifier::groupKey('Motos Honda'));
        $this->assertSame('autos|nuevos|honda', LandingClassifier::groupKey('Autos Honda nuevos'));
    }

    public function test_singular_jeep_is_the_make(): void
    {
        $this->assertSame('autos|usados|jeep', LandingClassifier::groupKey('Autos Jeep usados'));
        $this->assertSame('jeeps|usados|toyota', LandingClassifier::groupKey('Jeeps Toyota'));
    }

    public function test_scraped_labels_and_word_order_do_not_matter(): void
    {
        $this->assertSame(
            LandingClassifier::groupKey('Chevrolet Aveo'),
            LandingClassifier::groupKey('Modelo Aveo Marca Chevrolet')
        );
    }

    public function test_junk_titles(): void
    {
        $this->assertSame('telefono', LandingClassifier::junkReason('$100 TELEFONO 0986446351 Quito'));
        $this->assertSame('telefono', LandingClassifier::junkReason('Llamar al 098 644 6351'));
        $this->assertSame('email_url', LandingClassifier::junkReason('Fiat delbarco.palacios@live.com'));
        $this->assertSame('precio', LandingClassifier::junkReason('Toyota Hilux U$S 16.400'));
        $this->assertNull(LandingClassifier::junkReason('Chevrolet Z28 año 1974-1980'));
        $this->assertNull(LandingClassifier::junkReason('Autos Honda Civic usados'));
    }

    public function test_ids_are_confirmed_only_when_the_slug_names_them(): void
    {
        $this->assertSame(['make' => true, 'model' => false], LandingClassifier::confirmedNames('autos-honda-usados', 'honda', ''));
        $this->assertSame(['make' => true, 'model' => true], LandingClassifier::confirmedNames('autos-honda-civic-usados', 'honda', 'honda-civic'));
        // A named model is enough, a bare number is not.
        $this->assertSame(['make' => false, 'model' => true], LandingClassifier::confirmedNames('10000-aveo-activo', 'chevrolet', 'chevrolet-aveo'));
        $this->assertSame(['make' => false, 'model' => false], LandingClassifier::confirmedNames('100-telefono-quito', 'audi', 'audi-100'));
        $this->assertSame(['make' => true, 'model' => true], LandingClassifier::confirmedNames('autos-mazda-3-usados', 'mazda', 'mazda-3'));
    }

    public function test_breadcrumb_formats(): void
    {
        $expected = ['make' => 'honda', 'model' => 'honda-civic'];

        $this->assertSame($expected, LandingClassifier::breadcrumbSlugs('{"breadcrumb": {"make": "honda", "model": "honda-civic"}}'));
        $this->assertSame($expected, LandingClassifier::breadcrumbSlugs(json_encode('{"breadcrumb": {"make": "honda", "model": "honda-civic"}}')));
        $this->assertSame($expected, LandingClassifier::breadcrumbSlugs('{"breadcrumb": [{"make": "honda"}, {"model": "honda-civic"}]}'));
        $this->assertSame(['make' => '', 'model' => ''], LandingClassifier::breadcrumbSlugs(''));
    }
}
