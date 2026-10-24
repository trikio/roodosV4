<?php

namespace Tests\Feature;

use App\Services\ManticoreSearchService;
use Mockery;
use Tests\TestCase;

class CarsLandingTest extends TestCase
{
    private Mockery\MockInterface $manticore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manticore = Mockery::mock(ManticoreSearchService::class);
        $this->app->instance(ManticoreSearchService::class, $this->manticore);
        config(['services.landings.widening' => true]);
    }

    public function test_by_default_landings_keep_their_canonical_and_title_search(): void
    {
        config(['services.landings.widening' => false]);
        $this->givenLanding($this->landing([
            'slug' => 'venta-autos-usados-volkswagen-escarabajo-lima-los-olivos',
            'title' => 'venta Autos usados Volkswagen Escarabajo Lima Los Olivos',
            'content' => json_encode(['breadcrumb' => ['make' => 'volkswagen', 'model' => 'volkswagen-escarabajo']]),
        ]));
        $this->manticore->shouldNotReceive('countCars');
        $this->expectSearch(fn ($o) => $o['q'] === 'venta Autos usados Volkswagen Escarabajo Lima Los Olivos' && $o['make'] === '');

        $response = $this->get('http://autos.roodos.pe/venta-autos-usados-volkswagen-escarabajo-lima-los-olivos');

        $this->assertCanonical($response, '/venta-autos-usados-volkswagen-escarabajo-lima-los-olivos');
        $response->assertDontSee('mostramos resultados similares');
    }

    private function landing(array $attributes = []): array
    {
        return array_merge([
            'slug' => 'autos-honda-usados',
            'title' => 'Autos Honda usados',
            'meta_description' => '',
            'content' => json_encode(['breadcrumb' => ['make' => 'honda']]),
            'redirect' => '',
            'id_car_make' => 76,
            'id_car_model' => 0,
            'id_state' => 0,
            'id_city' => 0,
        ], $attributes);
    }

    private function givenLanding(array $landing): void
    {
        $this->manticore->shouldReceive('getLandingBySlug')
            ->with('pe', $landing['slug'])
            ->andReturn($landing);
    }

    private function givenNoLanding(string $slug): void
    {
        $this->manticore->shouldReceive('getLandingBySlug')->with('pe', $slug)->andReturn(null);
    }

    /** Count of cars matching every specific term of the landing title. */
    private function givenSpecificCount(string $terms, int $total): void
    {
        $this->manticore->shouldReceive('countCars')
            ->withArgs(fn ($index, $o, $allTerms = false) => $allTerms && $o['q'] === $terms)
            ->andReturn($total);
    }

    private function givenParentCount(array $filters, int $total): void
    {
        $this->manticore->shouldReceive('countCars')
            ->withArgs(fn ($index, $o, $allTerms = false) => !$allTerms && $o === $filters)
            ->andReturn($total);
    }

    private function expectSearch(callable $matchesOptions, int $total = 50): void
    {
        $this->manticore->shouldReceive('carSearch')
            ->once()
            ->withArgs(fn ($index, $options) => $index === 'car_pe' && $matchesOptions($options))
            ->andReturn([
                'items' => [],
                'info' => [['Variable_name' => 'total_found', 'Value' => (string) $total]],
                'make' => [], 'model' => [], 'state' => [], 'city' => [],
                'fuel' => [], 'transmission' => [], 'body' => [],
            ]);
    }

    private function assertCanonical($response, string $path): void
    {
        $response->assertOk()->assertSee('<link rel="canonical" href="http://autos.roodos.pe' . $path . '">', false);
    }

    public function test_make_landing_shows_only_that_make_and_is_its_own_canonical(): void
    {
        $this->givenLanding($this->landing());
        $this->givenSpecificCount('honda', 131);
        $this->expectSearch(fn ($o) => $o['q'] === 'Autos Honda usados'
            && $o['make'] === 76 && $o['model'] === '' && $o['state'] === '' && $o['city'] === '');

        $response = $this->get('http://autos.roodos.pe/autos-honda-usados');

        $this->assertCanonical($response, '/autos-honda-usados');
        $response->assertSee('<meta name="robots" content="index,follow">', false)
            ->assertDontSee('mostramos resultados similares');
    }

    public function test_page_links_carry_only_the_page(): void
    {
        $this->givenLanding($this->landing());
        $this->expectSearch(fn ($o) => true, 100);

        $this->get('http://autos.roodos.pe/autos-honda-usados')
            ->assertOk()
            ->assertSee('href="http://autos.roodos.pe/autos-honda-usados?page=2"', false)
            ->assertDontSee('autos-honda-usados?q=', false);
    }

    public function test_query_string_filters_do_not_leak_into_landing(): void
    {
        $this->givenLanding($this->landing());
        $this->givenSpecificCount('honda', 131);
        $this->expectSearch(fn ($o) => $o['make'] === 76 && $o['city'] === '');

        $this->get('http://autos.roodos.pe/autos-honda-usados?city=99&brand=1')->assertOk();
    }

    public function test_specific_landing_with_enough_results_keeps_all_filters(): void
    {
        $this->givenLanding($this->landing([
            'slug' => 'autos-honda-civic-usados-lima',
            'title' => 'Autos Honda Civic usados Lima',
            'content' => json_encode(['breadcrumb' => ['make' => 'honda', 'model' => 'honda-civic']]),
            'id_car_model' => 761, 'id_state' => 15, 'id_city' => 150,
        ]));
        $this->givenSpecificCount('honda civic lima', 25);
        $this->expectSearch(fn ($o) => $o['make'] === 76 && $o['model'] === 761 && $o['state'] === 15 && $o['city'] === 150);

        $this->assertCanonical($this->get('http://autos.roodos.pe/autos-honda-civic-usados-lima'), '/autos-honda-civic-usados-lima');
    }

    public function test_landing_with_few_results_widens_to_model_landing(): void
    {
        $this->givenLanding($this->landing([
            'slug' => 'autos-honda-civic-usados-lima',
            'title' => 'Autos Honda Civic usados Lima',
            'content' => json_encode(['breadcrumb' => ['make' => 'honda', 'model' => 'honda-civic']]),
            'id_car_model' => 761, 'id_state' => 15, 'id_city' => 150,
        ]));
        $this->givenSpecificCount('honda civic lima', 3);
        $this->givenParentCount(['make' => 76, 'model' => 761], 40);
        $this->givenLanding($this->landing(['slug' => 'autos-honda-civic-usados', 'title' => 'Autos Honda Civic usados']));
        $this->expectSearch(fn ($o) => $o['make'] === 76 && $o['model'] === 761 && $o['state'] === '' && $o['city'] === '');

        $response = $this->get('http://autos.roodos.pe/autos-honda-civic-usados-lima');

        $this->assertCanonical($response, '/autos-honda-civic-usados');
        $response->assertSee('mostramos resultados similares');
    }

    public function test_model_with_few_results_widens_to_make_landing(): void
    {
        $this->givenLanding($this->landing([
            'slug' => 'autos-honda-civic-hibrido-usados',
            'title' => 'Autos Honda Civic hibrido usados',
            'content' => json_encode(['breadcrumb' => ['make' => 'honda', 'model' => 'honda-civic']]),
            'id_car_model' => 761,
        ]));
        $this->givenSpecificCount('honda civic hibrido', 0);
        $this->givenParentCount(['make' => 76, 'model' => 761], 4);
        $this->givenLanding($this->landing());
        $this->expectSearch(fn ($o) => $o['make'] === 76 && $o['model'] === '');

        $this->assertCanonical($this->get('http://autos.roodos.pe/autos-honda-civic-hibrido-usados'), '/autos-honda-usados');
    }

    public function test_year_landing_without_results_points_to_make_landing(): void
    {
        $this->givenLanding($this->landing(['slug' => 'autos-honda-1980', 'title' => 'Autos Honda 1980']));
        $this->givenSpecificCount('honda 1980', 0);
        $this->givenLanding($this->landing());
        $this->expectSearch(fn ($o) => $o['make'] === 76);

        $this->assertCanonical($this->get('http://autos.roodos.pe/autos-honda-1980'), '/autos-honda-usados');
    }

    public function test_landing_that_only_names_the_make_points_to_make_landing(): void
    {
        $this->givenLanding($this->landing(['slug' => 'autos-honda', 'title' => 'Autos Honda']));
        $this->givenLanding($this->landing());
        $this->manticore->shouldNotReceive('countCars');
        $this->expectSearch(fn ($o) => $o['make'] === 76);

        $response = $this->get('http://autos.roodos.pe/autos-honda');

        $this->assertCanonical($response, '/autos-honda-usados');
        $response->assertDontSee('mostramos resultados similares');
    }

    public function test_model_landing_with_few_results_widens_to_make_landing(): void
    {
        $this->givenLanding($this->landing([
            'slug' => 'autos-honda-civic-usados',
            'title' => 'Autos Honda Civic usados',
            'content' => json_encode(['breadcrumb' => ['make' => 'honda', 'model' => 'honda-civic']]),
            'id_car_model' => 761,
        ]));
        $this->givenParentCount(['make' => 76, 'model' => 761], 2);
        $this->givenSpecificCount('honda civic', 2);
        $this->givenLanding($this->landing());
        $this->expectSearch(fn ($o) => $o['make'] === 76 && $o['model'] === '');

        $response = $this->get('http://autos.roodos.pe/autos-honda-civic-usados');

        $this->assertCanonical($response, '/autos-honda-usados');
        $response->assertSee('mostramos resultados similares');
    }

    public function test_widened_results_include_cars_whose_text_lacks_the_title_words(): void
    {
        // "Acadian Civic" was tagged as Honda Civic: the title has no "honda", so the parent
        // names are added or the quorum match would drop most Honda cars.
        $this->givenLanding($this->landing([
            'slug' => 'autos-acadian-civic-usados',
            'title' => 'Autos Acadian Civic usados',
            'content' => json_encode(['breadcrumb' => ['make' => 'honda', 'model' => 'honda-civic']]),
            'id_car_model' => 761,
        ]));
        $this->givenSpecificCount('acadian civic', 0);
        $this->givenParentCount(['make' => 76, 'model' => 761], 4);
        $this->givenLanding($this->landing());
        $this->expectSearch(fn ($o) => $o['q'] === 'Autos Acadian Civic usados honda' && $o['make'] === 76 && $o['model'] === '');

        $this->assertCanonical($this->get('http://autos.roodos.pe/autos-acadian-civic-usados'), '/autos-honda-usados');
    }

    public function test_missing_parent_landing_falls_back_to_model_page(): void
    {
        $this->givenLanding($this->landing([
            'slug' => 'autos-honda-civic-si-usados',
            'title' => 'Autos Honda Civic si usados',
            'content' => json_encode(['breadcrumb' => ['make' => 'honda', 'model' => 'honda-civic']]),
            'id_car_model' => 761,
        ]));
        $this->givenSpecificCount('honda civic si', 2);
        $this->givenParentCount(['make' => 76, 'model' => 761], 30);
        $this->givenNoLanding('autos-honda-civic-usados');
        $this->expectSearch(fn ($o) => $o['model'] === 761);

        $this->assertCanonical($this->get('http://autos.roodos.pe/autos-honda-civic-si-usados'), '/modelo/honda-civic');
    }

    public function test_make_landing_with_few_results_keeps_its_own_canonical(): void
    {
        $this->givenLanding($this->landing());
        $this->givenSpecificCount('honda', 3);
        $this->expectSearch(fn ($o) => $o['make'] === 76, 3);

        $response = $this->get('http://autos.roodos.pe/autos-honda-usados');

        $this->assertCanonical($response, '/autos-honda-usados');
        $response->assertDontSee('mostramos resultados similares');
    }

    public function test_misdetected_ids_are_ignored(): void
    {
        // Real case: "100" in the title made the indexer tag it as Audi 100.
        $this->givenLanding($this->landing([
            'slug' => '$100-telefono-0986446351-quito',
            'title' => '$100 TELEFONO 0986446351 Quito',
            'content' => json_encode(['breadcrumb' => ['make' => 'audi', 'model' => 'audi-100']]),
            'id_car_make' => 10, 'id_car_model' => 75, 'id_state' => 89, 'id_city' => 29027,
        ]));
        $this->manticore->shouldNotReceive('countCars');
        $this->expectSearch(fn ($o) => $o['make'] === '' && $o['model'] === '' && $o['city'] === '');

        $this->get('http://autos.roodos.pe/' . rawurlencode('$100-telefono-0986446351-quito'))->assertOk();
    }

    public function test_named_model_confirms_landing_even_without_make_in_slug(): void
    {
        $this->givenLanding($this->landing([
            'slug' => 'autos-acadian-civic-usados',
            'title' => 'Autos Acadian Civic usados',
            'content' => json_encode(['breadcrumb' => ['make' => 'honda', 'model' => 'honda-civic']]),
            'id_car_model' => 761,
        ]));
        $this->givenSpecificCount('acadian civic', 0);
        $this->givenParentCount(['make' => 76, 'model' => 761], 30);
        $this->givenLanding($this->landing(['slug' => 'autos-honda-civic-usados', 'title' => 'Autos Honda Civic usados']));
        $this->expectSearch(fn ($o) => $o['make'] === 76 && $o['model'] === 761);

        $this->assertCanonical($this->get('http://autos.roodos.pe/autos-acadian-civic-usados'), '/autos-honda-civic-usados');
    }

    public function test_landing_without_ids_resolves_make_from_breadcrumb(): void
    {
        $this->givenLanding($this->landing(['id_car_make' => 0]));
        $this->manticore->shouldReceive('getCarMakeBySlug')->with('honda')->andReturn(['id' => 76, 'name' => 'Honda']);
        $this->givenSpecificCount('honda', 131);
        $this->expectSearch(fn ($o) => $o['make'] === 76);

        $this->get('http://autos.roodos.pe/autos-honda-usados')->assertOk();
    }

    public function test_text_only_landing_keeps_searching_by_title(): void
    {
        $this->givenLanding($this->landing([
            'slug' => 'autos-baratos',
            'title' => 'Autos baratos',
            'content' => '',
            'id_car_make' => 0,
        ]));
        $this->manticore->shouldNotReceive('countCars');
        $this->expectSearch(fn ($o) => $o['q'] === 'Autos baratos' && $o['make'] === '');

        $this->assertCanonical($this->get('http://autos.roodos.pe/autos-baratos'), '/autos-baratos');
    }

    public function test_landing_meta_description_is_used_when_present(): void
    {
        $this->givenLanding($this->landing(['meta_description' => 'Encuentra autos Honda usados en Perú.']));
        $this->givenSpecificCount('honda', 131);
        $this->expectSearch(fn ($o) => true);

        $this->get('http://autos.roodos.pe/autos-honda-usados')
            ->assertSee('<meta name="description" content="Encuentra autos Honda usados en Perú.">', false);
    }
}
