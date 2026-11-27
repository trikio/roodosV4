<?php

namespace Tests\Feature;

use App\Services\ManticoreSearchService;
use Mockery;
use Tests\TestCase;

class HouseDetailTest extends TestCase
{
    private function house(array $overrides = []): array
    {
        return $overrides + [
            'id' => 77, 'title' => 'Departamento en Polanco', 'house_operation_name' => 'Venta', 'house_type_name' => 'Departamento',
            'state_name' => 'Ciudad de México', 'city_name' => 'Miguel Hidalgo', 'details' => 'Luminoso, con balcón.',
            'url' => 'https://departamento.mercadolibre.com.mx/MLM-123-depto', 'image' => 'https://img.roodos.com/x.jpg',
            'id_house_operation' => 1, 'id_house_type' => 2, 'id_state' => 9, 'id_city' => 15,
            'price' => 4000000, 'rooms' => 2, 'bath' => 2, 'size' => 100,
        ];
    }

    public function test_detail_compares_with_its_city_and_lists_similar_properties(): void
    {
        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('getHouseById')->with('house_mx', 77)->andReturn($this->house());
        $sample = array_map(fn ($i) => ['price' => 5000000 + $i * 100000, 'size' => 100], range(0, 10));
        $manticore->shouldReceive('housePriceSample')->with('house_mx', 1, 2, 'id_city', 15)->andReturn($sample);
        // Median 5.5 M: only prices between a tenth and ten times that.
        $manticore->shouldReceive('similarHouses')->with('house_mx', 77, 1, 2, 'id_city', 15, 4000000, 550000, 55000000)->andReturn([
            $this->house(['id' => 78, 'title' => 'Depto vecino', 'price' => 4100000]),
            $this->house(['id' => 79, 'title' => 'DEPTO VECINO preventa', 'price' => 4100000]),
            $this->house(['id' => 80, 'title' => 'Otro depto', 'price' => 4300000, 'size' => 90]),
        ]);
        $this->app->instance(ManticoreSearchService::class, $manticore);

        $this->get('http://casas.roodos.mx/casa/77')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, follow')
            ->assertSee('Precios de departamentos en venta en Miguel Hidalgo')
            ->assertSee('Bajo lo típico de la zona')
            ->assertSee('Ver aviso en mercadolibre.com.mx')
            ->assertSee('Depto vecino')
            ->assertSee('Otro depto')
            ->assertDontSee('DEPTO VECINO preventa')
            ->assertSee('/search?operation=1&amp;type=2&amp;city=15', false);
    }

    public function test_city_with_few_ads_falls_back_to_its_region(): void
    {
        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('getHouseById')->andReturn($this->house());
        $manticore->shouldReceive('housePriceSample')->with('house_mx', 1, 2, 'id_city', 15)->andReturn([['price' => 1, 'size' => 50]]);
        $manticore->shouldReceive('housePriceSample')->with('house_mx', 1, 2, 'id_state', 9)
            ->andReturn(array_fill(0, 12, ['price' => 4000000, 'size' => 100]));
        $manticore->shouldReceive('similarHouses')->with('house_mx', 77, 1, 2, 'id_state', 9, 4000000, 400000, 40000000)->andReturn([]);
        $this->app->instance(ManticoreSearchService::class, $manticore);

        $this->get('http://casas.roodos.mx/casa/77')
            ->assertOk()
            ->assertSee('en Ciudad de México')
            ->assertSee('En lo típico de la zona');
    }

    public function test_ad_without_operation_type_or_place_shows_only_its_data(): void
    {
        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('getHouseById')->andReturn($this->house([
            'id_house_operation' => 0, 'id_house_type' => 0, 'id_state' => 0, 'id_city' => 0,
            'house_operation_name' => '', 'house_type_name' => '', 'state_name' => '', 'city_name' => '',
            'url' => 'https://www.doomos.cl/de/1031655_las-palmeras.html', 'price' => 22, 'size' => 4000,
        ]));
        $manticore->shouldNotReceive('housePriceSample');
        $manticore->shouldNotReceive('similarHouses');
        $this->app->instance(ManticoreSearchService::class, $manticore);

        $this->get('http://casas.roodos.cl/casa/77')
            ->assertOk()
            ->assertSee('Ver aviso en doomos.cl')
            ->assertDontSee('Precio por m²')
            ->assertDontSee('Precios de');
    }

    public function test_missing_house_returns_410(): void
    {
        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('getHouseById')->andReturn(null);
        $this->app->instance(ManticoreSearchService::class, $manticore);

        $this->get('http://casas.roodos.mx/casa/99')->assertStatus(410);
    }
}
