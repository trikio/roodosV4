<?php

namespace Tests\Feature;

use App\Services\ManticoreSearchService;
use Mockery;
use Tests\TestCase;

class LandingCandidatesCommandTest extends TestCase
{
    public function test_reports_which_model_year_landings_are_new(): void
    {
        $output = sys_get_temp_dir() . '/landings-candidates-test';
        array_map('unlink', glob($output . '/*') ?: []);

        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('carCountsByModelAndYear')->with('car_ec', 2005, 8)->andReturn([
            ['id_car_model' => '2217', 'year_int' => '2024', 'total' => '12'],
            ['id_car_model' => '1842', 'year_int' => '2016', 'total' => '30'],
            ['id_car_model' => '1842', 'year_int' => '2018', 'total' => '9'],
        ]);
        $manticore->shouldReceive('carModelsByIds')->andReturn([
            ['id' => '2217', 'slug' => 'toyota-yaris', 'model_name' => 'Yaris', 'id_car_make' => '176'],
            ['id' => '1842', 'slug' => 'toyota-hilux', 'model_name' => 'Hilux', 'id_car_make' => '176'],
        ]);
        $manticore->shouldReceive('getCarMakeById')->with(176)->andReturn(['id' => 176, 'name' => 'Toyota', 'slug' => 'toyota']);
        $manticore->shouldReceive('iterateCarLandings')->with('ec')->andReturn((function () {
            yield ['slug' => 'toyota-hilux-2016', 'title' => 'Toyota Hilux 2016', 'redirect' => ''];
            yield ['slug' => 'camionetas-toyota-hilux-2018', 'title' => 'Camionetas Toyota Hilux 2018', 'redirect' => ''];
            yield ['slug' => 'toyota-yaris-2024-old', 'title' => 'Toyota Yaris 2024', 'redirect' => '/toyota-yaris'];
        })());
        $this->app->instance(ManticoreSearchService::class, $manticore);

        $this->artisan('landings:candidates', ['country' => 'ec', '--output' => $output, '--sql' => true])->assertSuccessful();

        $rows = array_map('str_getcsv', file(glob($output . '/landings_candidates_ec_*.csv')[0], FILE_IGNORE_NEW_LINES));
        $this->assertSame(['marca', 'modelo', 'año', 'avisos', 'slug_propuesto', 'titulo', 'estado', 'landing_existente'], $rows[0]);
        $this->assertSame([
            ['Toyota', 'Hilux', '2016', '30', 'toyota-hilux-2016', 'Toyota Hilux 2016 usados', 'existe', 'toyota-hilux-2016'],
            // A redirecting landing does not count.
            ['Toyota', 'Yaris', '2024', '12', 'toyota-yaris-2024', 'Toyota Yaris 2024 usados', 'nueva', ''],
            ['Toyota', 'Hilux', '2018', '9', 'toyota-hilux-2018', 'Toyota Hilux 2018 usados', 'otro_tipo', 'camionetas-toyota-hilux-2018'],
        ], array_slice($rows, 1));

        // Only the new one is written, as an update of a removed row with its slug or an insert.
        $sql = file_get_contents(glob($output . '/landings_candidates_ec_*.sql')[0]);
        $this->assertSame(1, substr_count($sql, 'INSERT INTO car_landings'));
        $content = '{"breadcrumb":{"make":"toyota","model":"toyota-yaris"},"generated":{"make":176,"model":2217,"year":2024,"created":"' . date('Y-m-d') . '"}}';
        $this->assertStringContainsString("UPDATE car_landings SET title = 'Toyota Yaris 2024 usados', meta_description = 'Toyota Yaris 2024 usados en Ecuador: compara precios, kilometraje y avisos de concesionarios y particulares en Roodos.', content = '{$content}', id_car_make = 176, id_car_model = 2217, id_state = 0, id_city = 0, redirect = '', activo = 1 WHERE country_id = 'ec' AND slug = 'toyota-yaris-2024';", $sql);
        $this->assertStringContainsString("FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM car_landings WHERE country_id = 'ec' AND slug = 'toyota-yaris-2024');", $sql);

        $summary = json_decode(file_get_contents(glob($output . '/landings_candidates_ec_*.json')[0]), true);
        $this->assertSame([1, 3, ['Toyota Yaris 2024 usados']], [$summary['create'], $summary['candidates'], $summary['titles']]);
    }
}
