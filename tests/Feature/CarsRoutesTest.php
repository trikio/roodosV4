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

    public function test_make_page_redirects_to_its_landing(): void
    {
        $this->mockManticore()
            ->shouldReceive('getLandingBySlug')
            ->with('cl', 'autos-honda-usados')
            ->andReturn(['title' => 'Autos Honda usados', 'redirect' => '']);

        $this->get('http://autos.roodos.cl/marca/honda')
            ->assertStatus(301)
            ->assertRedirect('http://autos.roodos.cl/autos-honda-usados');
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
}
