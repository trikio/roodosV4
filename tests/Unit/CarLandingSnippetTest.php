<?php

namespace Tests\Unit;

use App\Support\CarLandingSnippet;
use PHPUnit\Framework\TestCase;

class CarLandingSnippetTest extends TestCase
{
    public function test_tidies_old_titles(): void
    {
        $this->assertSame('Autos usados Nissan AD Station Wagon Lima', CarLandingSnippet::tidy('venta Autos usados Nissan Nissan AD Station Wagon Lima'));
        $this->assertSame('Etios Color Negro', CarLandingSnippet::tidy('Etios  Color Negro'));
        $this->assertSame('Ventas de autos Kia', CarLandingSnippet::tidy('ventas de autos Kia'));
    }

    public function test_terms_skip_words_no_ad_contains(): void
    {
        $this->assertSame('etios negro', CarLandingSnippet::terms('Etios Color Negro'));
        $this->assertSame('chevrolet camaro quito', CarLandingSnippet::terms('venta Autos usados Chevrolet Camaro Quito'));
    }

    public function test_figures_ignore_junk_prices_and_outlying_years(): void
    {
        $rows = [];
        foreach ([1, 18900, 21000, 25000, 32000, 35000, 41000, 45000, 52000, 60000, 79300] as $i => $price) {
            $rows[] = ['price' => $price, 'year_int' => $i === 0 ? 1973 : 2010 + $i];
        }
        $stats = CarLandingSnippet::stats(16, $rows, 1000);

        $this->assertSame(16, $stats['total']);
        $this->assertSame(21000, $stats['from']);
        $this->assertSame(41000, $stats['median']);
        $this->assertSame(2011, $stats['year_min']);
        $this->assertSame(2020, $stats['year_max']);
        $this->assertSame('Autos usados Chevrolet Camaro Quito: 16 avisos desde $21.000', CarLandingSnippet::title('Autos usados Chevrolet Camaro Quito', $stats, '$'));
        $this->assertSame(
            '16 avisos de Autos usados Chevrolet Camaro Quito desde $21.000 (precio mediano $41.000), años 2011 a 2020. Compara precios de automotoras y particulares y contacta directo al vendedor en Roodos.',
            CarLandingSnippet::description('Autos usados Chevrolet Camaro Quito', $stats, '$')
        );
    }

    public function test_without_comparable_prices_gives_count_and_years(): void
    {
        $rows = array_map(fn ($y) => ['price' => 9000, 'year_int' => $y], [2005, 2008, 2010, 2012, 2014]);
        $stats = CarLandingSnippet::stats(5, $rows, null);

        $this->assertNull($stats['from']);
        $this->assertSame('Grand Suzuki Nomade: 5 avisos, años 2005–2014', CarLandingSnippet::title('Grand Suzuki Nomade', $stats, 'S/.'));
    }

    public function test_too_few_cars_give_no_figures(): void
    {
        $this->assertNull(CarLandingSnippet::stats(2, [], 1000));
        $this->assertSame('Toyota Probox', CarLandingSnippet::title('Toyota Probox', null, 'S/.'));
    }
}
