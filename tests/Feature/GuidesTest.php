<?php

namespace Tests\Feature;

use App\Services\ManticoreSearchService;
use Mockery;
use Tests\TestCase;

class GuidesTest extends TestCase
{
    private const GUIDES = [
        'mx' => ['como-usan-la-tecnologia-las-agencias-de-seminuevos', 'https://www.autoraptor.com/'],
        'cl' => ['como-calcula-una-automotora-el-pie-y-las-cuotas', 'https://www.autoraptor.com/automotive-desking-software'],
        'pe' => ['como-manejan-el-stock-los-concesionarios-de-autos-usados', 'https://www.autoraptor.com/dealership-inventory-management'],
        'ec' => ['que-pasa-cuando-le-escribes-a-una-concesionaria', 'https://www.autoraptor.com/dealership-lead-management'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // After every guide's publication date.
        $this->travelTo('2026-12-31');

        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('existingCarLandingSlugs')->andReturn(['autos-toyota-usados']);
        $this->app->instance(ManticoreSearchService::class, $manticore);
    }

    public function test_index_lists_only_the_country_guides(): void
    {
        foreach (self::GUIDES as $country => [$slug]) {
            $response = $this->get("http://autos.roodos.{$country}/guias")->assertOk();

            $response->assertSee('href="http://autos.roodos.' . $country . '/guias/' . $slug . '"', false)
                ->assertSee('<html lang="es-' . strtoupper($country) . '">', false)
                ->assertSee('<link rel="canonical" href="http://autos.roodos.' . $country . '/guias">', false)
                ->assertDontSee('noindex', false);

            foreach (self::GUIDES as $other => [$otherSlug]) {
                if ($other !== $country) {
                    $response->assertDontSee($otherSlug, false);
                }
            }
        }
    }

    public function test_article_has_its_seo_tags(): void
    {
        $response = $this->get('http://autos.roodos.cl/guias/como-calcula-una-automotora-el-pie-y-las-cuotas')->assertOk();

        $response->assertSee('<html lang="es-CL">', false)
            ->assertSee('<title>Cómo calcula una automotora el pie y las cuotas de un auto usado | Roodos</title>', false)
            ->assertSee('<meta name="description" content="Qué variables mueve una automotora cuando arma tu crédito automotriz, cómo leer la CAE y el costo total, y qué preguntar antes de firmar.">', false)
            ->assertSee('<link rel="canonical" href="http://autos.roodos.cl/guias/como-calcula-una-automotora-el-pie-y-las-cuotas">', false)
            ->assertSee('<meta name="robots" content="index,follow">', false)
            ->assertSee('"@type":"Article","headline":"Cómo calcula una automotora el pie y las cuotas de un auto usado"', false)
            ->assertSee('"datePublished":"2026-11-11","dateModified":"2026-11-11"', false)
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertSee('<meta property="og:title" content="Cómo calcula una automotora el pie y las cuotas de un auto usado | Roodos">', false)
            ->assertSee('<h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight">Cómo calcula una automotora el pie y las cuotas de un auto usado</h1>', false)
            ->assertSee('<h2>Las piezas de la cuota</h2>', false)
            ->assertDontSee('noindex', false)
            ->assertDontSee('hreflang', false);
    }

    public function test_autoraptor_link_is_a_plain_followed_link(): void
    {
        foreach (self::GUIDES as $country => [$slug, $autoraptorUrl]) {
            $html = $this->get("http://autos.roodos.{$country}/guias/{$slug}")->assertOk()->getContent();

            preg_match_all('/<a [^>]*href="https:\/\/www\.autoraptor\.com[^"]*"[^>]*>/', $html, $links);
            $this->assertSame(['<a href="' . $autoraptorUrl . '">'], $links[0], $country);
        }
    }

    public function test_article_ends_with_internal_search_links(): void
    {
        $this->get('http://autos.roodos.pe/guias/como-manejan-el-stock-los-concesionarios-de-autos-usados')
            ->assertOk()
            ->assertSee('href="http://autos.roodos.pe/search"', false)
            ->assertSee('href="http://autos.roodos.pe/autos-toyota-usados"', false)
            ->assertSee('href="http://autos.roodos.pe/marca/honda"', false);
    }

    public function test_guide_of_another_country_is_not_found(): void
    {
        $this->get('http://autos.roodos.mx/guias/como-calcula-una-automotora-el-pie-y-las-cuotas')->assertNotFound();
        $this->get('http://autos.roodos.cl/guias/no-existe')->assertNotFound();
        $this->get('http://autos.roodos.lab/guias')->assertNotFound();
    }

    public function test_guides_stay_hidden_until_their_publication_date(): void
    {
        $this->travelTo('2026-11-17 23:59');

        $this->get('http://autos.roodos.cl/guias')->assertOk()
            ->assertSee('<a href="http://autos.roodos.cl/guias" class="hover:text-gray-900">Guías</a>', false);
        $this->get('http://autos.roodos.mx/guias')->assertNotFound();
        $this->get('http://autos.roodos.mx/guias/como-usan-la-tecnologia-las-agencias-de-seminuevos')->assertNotFound();
        $this->app->make(ManticoreSearchService::class)->shouldReceive('carSearch')->andReturn([
            'items' => [],
            'info' => [['Variable_name' => 'total_found', 'Value' => '0']],
            'make' => [], 'model' => [], 'state' => [], 'city' => [],
            'fuel' => [], 'transmission' => [], 'body' => [],
        ]);
        $this->get('http://autos.roodos.mx/search')->assertOk()->assertDontSee('/guias', false);

        $this->travelTo('2026-11-18');

        $this->get('http://autos.roodos.mx/guias/como-usan-la-tecnologia-las-agencias-de-seminuevos')->assertOk();
        $this->get('http://autos.roodos.pe/guias')->assertNotFound();
    }

    public function test_footer_links_to_the_guides(): void
    {
        $this->get('http://autos.roodos.ec/guias/que-pasa-cuando-le-escribes-a-una-concesionaria')
            ->assertOk()
            ->assertSee('<a href="http://autos.roodos.ec/guias" class="hover:text-gray-900">Guías</a>', false);
    }

    public function test_price_table_comes_from_the_country_index(): void
    {
        $manticore = $this->app->make(ManticoreSearchService::class);
        $manticore->shouldReceive('getCarModelBySlug')->with('chevrolet-aveo')
            ->andReturn(['id' => 262, 'model_name' => 'Aveo', 'id_car_make' => 35]);
        $manticore->shouldReceive('getCarMakeById')->with(35)->andReturn(['id' => 35, 'name' => 'Chevrolet']);
        $manticore->shouldReceive('carModelPricesByYear')->once()->with('car_ec', 262, 1000)->andReturn([
            ['year' => 2015, 'total' => 7, 'avg' => 9450, 'min' => 7900, 'max' => 11500],
        ]);

        $this->get('http://autos.roodos.ec/guias/cuanto-cuesta-un-chevrolet-aveo-usado')
            ->assertOk()
            ->assertSee('<td>2015</td>', false)
            ->assertSee('$ 9.450')
            ->assertSee('$ 7.900 – $ 11.500')
            ->assertSee('href="http://autos.roodos.ec/modelo/chevrolet-aveo"', false)
            ->assertDontSee('[[precios', false);
    }

    public function test_price_table_says_so_when_there_are_too_few_ads(): void
    {
        $manticore = $this->app->make(ManticoreSearchService::class);
        $manticore->shouldReceive('getCarModelBySlug')->andReturn(['id' => 1758, 'model_name' => 'Swift', 'id_car_make' => 167]);
        $manticore->shouldReceive('getCarMakeById')->andReturn(['id' => 167, 'name' => 'Suzuki']);
        $manticore->shouldReceive('carModelPricesByYear')->with('car_cl', 1758, 500000)->andReturn([]);

        $this->get('http://autos.roodos.cl/guias/cuanto-cuesta-un-suzuki-swift-usado')
            ->assertOk()
            ->assertSee('Hoy no hay suficientes avisos de Suzuki Swift')
            ->assertDontSee('<table>', false);
    }

    public function test_price_tables_are_off_where_ads_mix_currencies(): void
    {
        $html = $this->app->make(\App\Services\GuidePriceTables::class)->render('pe', '<p>[[precios:toyota-yaris]]</p>');

        $this->assertSame('', $html);
    }

    public function test_links_between_guides_point_to_guides_already_published(): void
    {
        foreach (['cl', 'mx', 'pe', 'ec'] as $country) {
            foreach (glob(base_path("content/guias/{$country}/*.md")) as $file) {
                $text = file_get_contents($file);
                preg_match('/^published_at: (\S+)$/m', $text, $date);
                preg_match_all('#\]\(/guias/([a-z0-9-]+)\)#', $text, $links);

                foreach ($links[1] as $slug) {
                    $target = base_path("content/guias/{$country}/{$slug}.md");
                    $this->assertFileExists($target, basename($file));
                    preg_match('/^published_at: (\S+)$/m', file_get_contents($target), $targetDate);
                    $this->assertLessThanOrEqual($date[1], $targetDate[1], basename($file) . ' -> ' . $slug);
                }
            }
        }
    }
}
