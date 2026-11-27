<?php

namespace Tests\Feature;

use App\Services\ManticoreSearchService;
use Mockery;
use Tests\TestCase;

class HouseLandingRoutesTest extends TestCase
{
    private function emptyResult(int $total = 0): array
    {
        return ['items' => [], 'info' => [['Variable_name' => 'total_found', 'Value' => (string) $total]], 'operation' => [], 'type' => [], 'state' => [], 'city' => []];
    }

    public function test_generated_landing_lists_exactly_its_search_and_links_related_landings(): void
    {
        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('getHouseLandingBySlug')->with('mx', 'departamentos-en-venta-en-miguel-hidalgo')->andReturn([
            'id' => 30, 'slug' => 'departamentos-en-venta-en-miguel-hidalgo', 'title' => 'Departamentos en venta en Miguel Hidalgo',
            'content' => '{"generated":{"operation":1,"type":2,"city":15,"created":"2026-11-27"}}',
            'id_house_operation' => 1, 'id_house_type' => 2, 'id_state' => 9, 'id_city' => 15,
        ]);
        $manticore->shouldReceive('houseSearch')->once()->with('house_mx', Mockery::on(fn ($o) => $o['operation'] === 1 && $o['type'] === 2 && $o['city'] === 15 && $o['state'] === '' && $o['q'] === ''))
            ->andReturn($this->emptyResult(3));
        $manticore->shouldReceive('houseLandings')->andReturn([
            ['id' => 12, 'slug' => 'departamentos-en-venta-en-ciudad-de-mexico', 'title' => 'Departamentos en venta en Ciudad de México', 'content' => '{"generated":{"operation":1,"type":2,"state":9}}', 'id_house_operation' => 1, 'id_house_type' => 2, 'id_state' => 9, 'id_city' => 0],
            ['id' => 30, 'slug' => 'departamentos-en-venta-en-miguel-hidalgo', 'title' => 'Departamentos en venta en Miguel Hidalgo', 'content' => '{"generated":{"operation":1,"type":2,"city":15}}', 'id_house_operation' => 1, 'id_house_type' => 2, 'id_state' => 9, 'id_city' => 15],
        ]);
        $this->app->instance(ManticoreSearchService::class, $manticore);

        $this->get('http://casas.roodos.mx/departamentos-en-venta-en-miguel-hidalgo')
            ->assertOk()
            ->assertSee('<title>Departamentos en venta en Miguel Hidalgo - Roodos Casas', false)
            ->assertSee('<meta name="robots" content="index,follow">', false)
            ->assertSee('Búsquedas relacionadas')
            ->assertSee('href="http://casas.roodos.mx/departamentos-en-venta-en-ciudad-de-mexico"', false)
            ->assertDontSee('hover:underline">Departamentos en venta en Miguel Hidalgo</a>', false);
    }

    public function test_legacy_landing_keeps_its_text_search(): void
    {
        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('getHouseLandingBySlug')->andReturn(['id' => 87, 'slug' => 'arriendo-casas-leon', 'title' => 'Arriendo Casas León', 'content' => '']);
        $manticore->shouldReceive('houseSearch')->once()->with('house_mx', Mockery::on(fn ($o) => $o['q'] === 'Arriendo Casas León' && $o['operation'] === ''))
            ->andReturn($this->emptyResult());
        $manticore->shouldNotReceive('houseLandings');
        $this->app->instance(ManticoreSearchService::class, $manticore);

        $this->get('http://casas.roodos.mx/arriendo-casas-leon')->assertOk()->assertDontSee('Búsquedas relacionadas');
    }

    public function test_a_path_that_is_not_an_active_landing_returns_410_without_searching(): void
    {
        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('getHouseLandingBySlug')->with('mx', 'casa-ciudad-inventada')->andReturn(null);
        $manticore->shouldNotReceive('houseSearch');
        $this->app->instance(ManticoreSearchService::class, $manticore);

        $this->get('http://casas.roodos.mx/casa-ciudad-inventada')->assertStatus(410);
    }

    public function test_home_links_existing_landings_and_searches_for_the_rest(): void
    {
        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('houseSearch')->andReturn($this->emptyResult(120));
        $manticore->shouldReceive('houseLandings')->andReturn([]);
        $manticore->shouldReceive('existingHouseLandingSlugs')->once()
            ->with('ec', Mockery::on(fn ($slugs) => in_array('casa', $slugs, true) && in_array('casa-quito', $slugs, true)))
            ->andReturn(['casa', 'casa-guayaquil']);
        $this->app->instance(ManticoreSearchService::class, $manticore);

        $this->get('http://casas.roodos.ec/')
            ->assertOk()
            ->assertSee('href="http://casas.roodos.ec/casa"', false)
            ->assertSee('href="http://casas.roodos.ec/casa-guayaquil"', false)
            ->assertSee('href="http://casas.roodos.ec/search?q=Departamento"', false)
            ->assertSee('href="http://casas.roodos.ec/search?q=Casas%20en%20Quito"', false)
            ->assertDontSee('href="/casa-quito"', false);
    }

    public function test_robots_declares_the_house_sitemap(): void
    {
        $this->get('http://casas.roodos.mx/robots.txt')->assertOk()->assertSee('Sitemap: https://casas.roodos.mx/sitemap.xml');
    }

    public function test_a_landing_without_ads_is_kept_out_of_the_index(): void
    {
        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('getHouseLandingBySlug')->andReturn(['id' => 87, 'slug' => 'venta-casas-lima', 'title' => 'Venta Casas Lima', 'content' => '']);
        $manticore->shouldReceive('houseSearch')->andReturn($this->emptyResult());
        $this->app->instance(ManticoreSearchService::class, $manticore);

        $this->get('http://casas.roodos.pe/venta-casas-lima')->assertOk()->assertSee('<meta name="robots" content="noindex,follow">', false);
    }
}
