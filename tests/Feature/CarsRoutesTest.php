<?php

namespace Tests\Feature;

use App\Services\ManticoreSearchService;
use Mockery;
use Tests\TestCase;

class CarsRoutesTest extends TestCase
{
    private function emptySearchResult(int $total = 0): array
    {
        return [
            'items' => [],
            'info' => [['Variable_name' => 'total_found', 'Value' => (string) $total]],
            'make' => [], 'model' => [], 'state' => [], 'city' => [],
            'fuel' => [], 'transmission' => [], 'body' => [],
        ];
    }

    private function mockManticore(): Mockery\MockInterface
    {
        $mock = Mockery::mock(ManticoreSearchService::class);
        $mock->shouldReceive('similarCarLandings')->andReturn([])->byDefault();
        $mock->shouldReceive('carLandingTitles')->andReturn([])->byDefault();
        $this->app->instance(ManticoreSearchService::class, $mock);

        return $mock;
    }

    public function test_www_redirects_to_autos_keeping_path_and_query(): void
    {
        $this->get('http://www.roodos.cl/toyota?page=2')
            ->assertStatus(301)
            ->assertRedirect('https://autos.roodos.cl/toyota?page=2');
    }

    public function test_cars_home_queries_country_index(): void
    {
        $manticore = $this->mockManticore();
        $manticore->shouldReceive('carSearch')
            ->once()
            ->with('car_cl', ['page' => 1])
            ->andReturn($this->emptySearchResult(1234));
        $manticore->shouldReceive('existingCarLandingSlugs')->andReturn([]);

        $this->get('http://autos.roodos.cl/')->assertOk();
    }

    public function test_home_links_go_straight_to_existing_landings(): void
    {
        $manticore = $this->mockManticore();
        $manticore->shouldReceive('carSearch')->andReturn($this->emptySearchResult());
        $manticore->shouldReceive('existingCarLandingSlugs')
            ->with('cl', Mockery::on(fn ($slugs) => in_array('autos-vina-del-mar', $slugs, true)))
            ->andReturn(['autos-honda-usados', 'autos-toyota-corolla-usados', 'autos-vina-del-mar', 'autos-usados-valparaiso']);

        $this->get('http://autos.roodos.cl/')
            ->assertOk()
            ->assertSee('href="http://autos.roodos.cl/autos-honda-usados"', false)
            ->assertSee('href="http://autos.roodos.cl/autos-toyota-corolla-usados"', false)
            ->assertSee('href="http://autos.roodos.cl/autos-vina-del-mar"', false)
            ->assertSee('href="http://autos.roodos.cl/autos-usados-valparaiso"', false)
            // No landing for this make: its make page, never a URL that redirects.
            ->assertSee('href="http://autos.roodos.cl/marca/audi"', false)
            ->assertDontSee('href="/honda"', false)
            // Cities without a landing are not linked.
            ->assertDontSee('autos-santiago', false);
    }

    public function test_old_index_html_urls_redirect_to_the_landing(): void
    {
        $this->get('http://autos.roodos.pe/venta-autos-usados-toyota-tercel-trujillo/index.html')
            ->assertStatus(301)
            ->assertRedirect('http://autos.roodos.pe/venta-autos-usados-toyota-tercel-trujillo');
    }

    public function test_robots_declares_the_country_sitemap(): void
    {
        $this->get('http://autos.roodos.pe/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap: https://autos.roodos.pe/sitemap.xml');
    }

    public function test_search_passes_raw_query_to_service(): void
    {
        $this->mockManticore()
            ->shouldReceive('carSearch')
            ->once()
            ->withArgs(fn ($index, $options) => $index === 'car_cl' && $options['q'] === 'toyota "corolla')
            ->andReturn($this->emptySearchResult());

        $this->get('http://autos.roodos.cl/search?q=' . urlencode('toyota "corolla'))->assertOk();
    }

    public function test_search_queues_mixpanel_search_event(): void
    {
        $this->mockManticore()->shouldReceive('carSearch')->andReturn($this->emptySearchResult(7));

        $this->get('http://autos.roodos.cl/search?q=toyota&min_year=2015')
            ->assertOk()
            ->assertSee('push([\'Search\', {"vertical":"autos","country":"cl","query":"toyota","results_count":7,"page":1,"order":null,"min_year":"2015"}]);', false)
            ->assertDontSee("'Landing Viewed'", false);
    }

    public function test_removed_landing_returns_410(): void
    {
        $this->mockManticore()
            ->shouldReceive('getLandingBySlug')
            ->with('cl', 'no-existe')
            ->andReturn(null);

        $this->get('http://autos.roodos.cl/no-existe')->assertStatus(410);
    }

    public function test_landing_with_redirect_returns_301(): void
    {
        $this->mockManticore()
            ->shouldReceive('getLandingBySlug')
            ->with('cl', 'toyota-corolla')
            ->andReturn(['title' => 'Toyota Corolla', 'redirect' => '/modelo/toyota-corolla']);

        $this->get('http://autos.roodos.cl/toyota-corolla')
            ->assertStatus(301)
            ->assertRedirect('http://autos.roodos.cl/modelo/toyota-corolla');
    }

    private function carRow(int $id, int $price, array $overrides = []): array
    {
        return array_merge([
            'id' => $id, 'title' => 'Toyota Hilux 4x4', 'make_name' => 'Toyota', 'model_name' => 'Hilux',
            'price' => $price, 'year_int' => 2007, 'km' => 199928, 'state_name' => 'Pichincha', 'city_name' => 'Quito',
            'transmission_name' => 'Manual', 'fuel_name' => '', 'body_name' => '',
            'image' => 'https://img.roodos.com/x.jpg', 'url' => 'https://fuente.example/aviso-' . $id,
            'nexo_id' => 'Patiotuerca', 'id_car_make' => 176, 'id_car_model' => 1842, 'id_city' => 12,
        ], $overrides);
    }

    public function test_car_detail_is_a_page_with_a_link_to_the_source_instead_of_a_redirect(): void
    {
        $manticore = $this->mockManticore();
        $manticore->shouldReceive('getCarById')->with('car_ec', 38282)->andReturn($this->carRow(38282, 14900));
        $manticore->shouldReceive('getCarMakeById')->with(176)->andReturn(['id' => 176, 'slug' => 'toyota']);
        $manticore->shouldReceive('carModelsByIds')->with([1842])->andReturn([['id' => 1842, 'slug' => 'toyota-hilux']]);
        $manticore->shouldReceive('similarCars')->with('car_ec', 38282, 176, 1842, 14900)
            ->andReturn([$this->carRow(45982, 13990, ['title' => '2009 Toyota Hilux'])]);
        $manticore->shouldReceive('carModelPriceStats')->with('car_ec', 1842, 2007)
            ->andReturn(['total' => 11, 'avg' => 17000, 'min' => 8900, 'max' => 19900]);

        // Model + city and model by id; the make level falls back to its name (no make landings by id).
        $manticore->shouldReceive('carLandingsByIds')->with('ec', ['id_car_model' => 1842, 'id_city' => 12], 150)
            ->andReturn([['id' => 1, 'slug' => 'toyota-hilux-quito', 'title' => 'Toyota Hilux en Quito']]);
        $manticore->shouldReceive('carLandingsByIds')->with('ec', ['id_car_model' => 1842, 'id_city' => 0], 150)
            ->andReturn([
                ['id' => 1, 'slug' => 'toyota-hilux-quito', 'title' => 'Toyota Hilux en Quito'],
                ['id' => 2, 'slug' => 'autos-toyota-hilux-usados', 'title' => 'Autos Toyota Hilux usados'],
            ]);
        $manticore->shouldReceive('carLandingsByIds')->with('ec', ['id_car_make' => 176, 'id_car_model' => 0, 'id_city' => 0], 150)->andReturn([]);
        $manticore->shouldReceive('similarCarLandings')->with('ec', 'toyota hilux quito', true, 150)->andReturn([]);
        $manticore->shouldReceive('similarCarLandings')->with('ec', 'toyota hilux', true, 150)->andReturn([]);
        $manticore->shouldReceive('similarCarLandings')->with('ec', 'toyota', true, 150)
            ->andReturn([['id' => 3, 'slug' => 'autos-toyota-usados', 'title' => 'Autos Toyota usados']]);

        $html = $this->get('http://autos.roodos.ec/auto/38282')->getContent();
        preg_match_all('#<li><a href="http://autos\.roodos\.ec/([a-z-]+)"#', $html, $landings);
        $this->assertSame(['toyota-hilux-quito', 'autos-toyota-hilux-usados', 'autos-toyota-usados'], $landings[1]);

        $this->get('http://autos.roodos.ec/auto/38282')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, follow')
            ->assertSee('<meta name="robots" content="noindex,follow">', false)
            ->assertSee('href="https://fuente.example/aviso-38282"', false)
            ->assertSee('Ver aviso en Patiotuerca')
            ->assertSee('$ 14.900')
            ->assertSee('Bajo el promedio')
            ->assertSee('Según 11 avisos')
            ->assertSee('href="/auto/45982"', false)
            ->assertSee('href="http://autos.roodos.ec/modelo/toyota-hilux"', false)
            ->assertSee('href="http://autos.roodos.ec/marca/toyota"', false)
            ->assertSee("push(['Ad Detail Viewed'", false)
            ->assertDontSee('window.location.href', false);
    }

    public function test_car_detail_without_make_model_or_enough_prices(): void
    {
        $manticore = $this->mockManticore();
        $manticore->shouldReceive('getCarById')->andReturn($this->carRow(1, 5600, [
            'make_name' => '', 'model_name' => '', 'id_car_make' => 0, 'id_car_model' => 0, 'nexo_id' => '',
        ]));
        $manticore->shouldReceive('carLandingsByIds')->never();
        $manticore->shouldReceive('getCarMakeById')->never();
        $manticore->shouldReceive('carModelsByIds')->never();
        $manticore->shouldReceive('similarCars')->andReturn([]);
        $manticore->shouldReceive('carModelPriceStats')->andReturn(null);

        $this->get('http://autos.roodos.ec/auto/1')
            ->assertOk()
            ->assertSee('Ver aviso en fuente externa')
            ->assertDontSee('Búsquedas relacionadas')
            ->assertDontSee('similares')
            ->assertDontSee('/marca/', false);
    }

    public function test_missing_car_returns_404(): void
    {
        $this->mockManticore()->shouldReceive('getCarById')->andReturn(null);

        $this->get('http://autos.roodos.ec/auto/999')->assertNotFound();
    }
}
