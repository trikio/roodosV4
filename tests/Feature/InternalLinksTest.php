<?php

namespace Tests\Feature;

use App\Services\ManticoreSearchService;
use Mockery;
use Tests\TestCase;

class InternalLinksTest extends TestCase
{
    private Mockery\MockInterface $manticore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manticore = Mockery::mock(ManticoreSearchService::class);
        $this->manticore->shouldReceive('similarCarLandings')->andReturn([])->byDefault();
        $this->manticore->shouldReceive('carLandingTitles')->andReturn([])->byDefault();
        $this->manticore->shouldReceive('existingCarLandingSlugs')->andReturn([])->byDefault();
        $this->app->instance(ManticoreSearchService::class, $this->manticore);
    }

    private function searchResult(array $models = []): array
    {
        return [
            'items' => [],
            'info' => [['Variable_name' => 'total_found', 'Value' => '50']],
            'make' => [], 'model' => $models, 'state' => [], 'city' => [],
            'fuel' => [], 'transmission' => [], 'body' => [],
        ];
    }

    private function row(int $id, string $slug, string $title): array
    {
        return ['id' => (string) $id, 'slug' => $slug, 'title' => $title];
    }

    public function test_landing_links_to_related_landings_once_per_search_and_without_junk(): void
    {
        $this->manticore->shouldReceive('getLandingBySlug')->with('pe', 'venta-autos-usados-toyota-rav4-huancayo')
            ->andReturn(['id' => '10', 'title' => 'venta Autos usados Toyota RAV4 Huancayo', 'redirect' => '', 'content' => '']);
        $this->manticore->shouldReceive('carSearch')->andReturn($this->searchResult());
        $this->manticore->shouldReceive('similarCarLandings')
            ->withArgs(fn ($cc, $terms, $all, $limit, $exclude) => $cc === 'pe' && $terms === 'toyota rav4 huancayo' && !$all && $exclude === [10])
            ->andReturn([
                $this->row(11, 'autos-toyota-rav4-usados-huancayo', 'Autos Toyota RAV4 usados Huancayo'),
                $this->row(12, 'venta-autos-usados-toyota-rav4-arequipa', 'venta Autos usados Toyota RAV4 Arequipa'),
                $this->row(13, 'rav4-arequipa-toyota', 'RAV4 Arequipa Toyota'),
                $this->row(14, 'toyota-rav4-0987654321', 'Toyota RAV4 0987654321'),
                $this->row(15, 'venta-autos-usados-toyota-corolla-huancayo', 'venta Autos usados Toyota Corolla Huancayo'),
            ]);

        $response = $this->get('http://autos.roodos.pe/venta-autos-usados-toyota-rav4-huancayo')->assertOk();

        $response->assertSee('Búsquedas relacionadas')
            ->assertSee('href="http://autos.roodos.pe/venta-autos-usados-toyota-rav4-arequipa"', false)
            ->assertSee('href="http://autos.roodos.pe/venta-autos-usados-toyota-corolla-huancayo"', false)
            // Same search as the page itself, a duplicate of an earlier link, and a phone number.
            ->assertDontSee('autos-toyota-rav4-usados-huancayo', false)
            ->assertDontSee('rav4-arequipa-toyota', false)
            ->assertDontSee('0987654321', false);
    }

    public function test_make_page_links_to_its_models_and_landings(): void
    {
        $this->manticore->shouldReceive('getCarMakeBySlug')->with('toyota')->andReturn(['id' => '5', 'name' => 'Toyota', 'slug' => 'toyota']);
        $this->manticore->shouldReceive('carSearch')->andReturn($this->searchResult([
            ['id_car_model' => '70', 'name' => 'RAV4', 'total' => '30'],
            ['id_car_model' => '71', 'name' => 'Hilux', 'total' => '20'],
        ]));
        $this->manticore->shouldReceive('carModelsByIds')->with(['70', '71'])
            ->andReturn([['id' => '70', 'slug' => 'toyota-rav4', 'model_name' => 'RAV4'], ['id' => '71', 'slug' => 'toyota-hilux', 'model_name' => 'Hilux']]);
        $this->manticore->shouldReceive('similarCarLandings')
            ->withArgs(fn ($cc, $terms, $all) => $terms === 'toyota' && $all)
            ->andReturn([$this->row(1, 'venta-autos-usados-toyota-rav4-huancayo', 'venta Autos usados Toyota RAV4 Huancayo')]);

        $this->get('http://autos.roodos.pe/marca/toyota')
            ->assertOk()
            ->assertSee('Modelos de Toyota')
            ->assertSee('href="http://autos.roodos.pe/modelo/toyota-rav4"', false)
            ->assertSee('href="http://autos.roodos.pe/modelo/toyota-hilux"', false)
            ->assertSee('Búsquedas de Toyota')
            ->assertSee('href="http://autos.roodos.pe/venta-autos-usados-toyota-rav4-huancayo"', false);
    }

    public function test_model_page_links_to_its_make_and_landings(): void
    {
        $this->manticore->shouldReceive('getCarModelBySlug')->with('toyota-rav4')
            ->andReturn(['id' => '70', 'id_car_make' => '5', 'make_name' => 'Toyota', 'model_name' => 'RAV4', 'make_model' => 'Toyota RAV4']);
        $this->manticore->shouldReceive('getCarMakeById')->with(5)->andReturn(['id' => '5', 'slug' => 'toyota']);
        $this->manticore->shouldReceive('carSearch')->andReturn($this->searchResult());
        $this->manticore->shouldReceive('similarCarLandings')
            ->withArgs(fn ($cc, $terms, $all) => $terms === 'toyota rav4' && $all)
            ->andReturn([$this->row(1, 'venta-autos-usados-toyota-rav4-huancayo', 'venta Autos usados Toyota RAV4 Huancayo')]);

        $this->get('http://autos.roodos.pe/modelo/toyota-rav4')
            ->assertOk()
            ->assertSee('href="http://autos.roodos.pe/marca/toyota"', false)
            ->assertSee('Búsquedas de Toyota RAV4')
            ->assertSee('href="http://autos.roodos.pe/venta-autos-usados-toyota-rav4-huancayo"', false);
    }

    public function test_home_links_to_the_curated_landings_that_still_exist(): void
    {
        config(['landing_links.home.PE' => ['venta-autos-usados-mazda-3-lima', 'ya-no-existe']]);
        $this->manticore->shouldReceive('carSearch')->andReturn($this->searchResult());
        $this->manticore->shouldReceive('carLandingTitles')->with('pe', ['venta-autos-usados-mazda-3-lima', 'ya-no-existe'])
            ->andReturn(['venta-autos-usados-mazda-3-lima' => 'venta Autos usados Mazda 3 Lima']);

        $this->get('http://autos.roodos.pe/')
            ->assertOk()
            ->assertSee('Búsquedas destacadas')
            ->assertSee('href="http://autos.roodos.pe/venta-autos-usados-mazda-3-lima"', false)
            ->assertSee('Venta Autos usados Mazda 3 Lima')
            ->assertDontSee('ya-no-existe', false);
    }

    public function test_home_without_curated_list_links_landings_of_its_cities(): void
    {
        config(['landing_links.home.MX' => []]);
        $this->manticore->shouldReceive('carSearch')->andReturn($this->searchResult());
        $this->manticore->shouldReceive('similarCarLandings')
            ->withArgs(fn ($cc, $terms, $all) => $cc === 'mx' && $terms === 'guadalajara' && $all)
            ->andReturn([$this->row(1, 'venta-autos-usados-nissan-versa-guadalajara', 'venta Autos usados Nissan Versa Guadalajara')]);

        $this->get('http://autos.roodos.mx/')
            ->assertOk()
            ->assertSee('href="http://autos.roodos.mx/venta-autos-usados-nissan-versa-guadalajara"', false);
    }
}
