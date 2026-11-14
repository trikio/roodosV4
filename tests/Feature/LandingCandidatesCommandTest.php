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
        array_map('unlink', glob($output . '/*.csv') ?: []);

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
        $manticore->shouldReceive('getCarMakeById')->with(176)->andReturn(['id' => 176, 'name' => 'Toyota']);
        $manticore->shouldReceive('iterateCarLandings')->with('ec')->andReturn((function () {
            yield ['slug' => 'toyota-hilux-2016', 'title' => 'Toyota Hilux 2016', 'redirect' => ''];
            yield ['slug' => 'camionetas-toyota-hilux-2018', 'title' => 'Camionetas Toyota Hilux 2018', 'redirect' => ''];
            yield ['slug' => 'toyota-yaris-2024-old', 'title' => 'Toyota Yaris 2024', 'redirect' => '/toyota-yaris'];
        })());
        $this->app->instance(ManticoreSearchService::class, $manticore);

        $this->artisan('landings:candidates', ['country' => 'ec', '--output' => $output])->assertSuccessful();

        $rows = array_map('str_getcsv', file(glob($output . '/landings_candidates_ec_*.csv')[0], FILE_IGNORE_NEW_LINES));
        $this->assertSame(['marca', 'modelo', 'año', 'avisos', 'slug_propuesto', 'titulo', 'estado', 'landing_existente'], $rows[0]);
        $this->assertSame([
            ['Toyota', 'Hilux', '2016', '30', 'toyota-hilux-2016', 'Toyota Hilux 2016 usados', 'existe', 'toyota-hilux-2016'],
            // A redirecting landing does not count.
            ['Toyota', 'Yaris', '2024', '12', 'toyota-yaris-2024', 'Toyota Yaris 2024 usados', 'nueva', ''],
            ['Toyota', 'Hilux', '2018', '9', 'toyota-hilux-2018', 'Toyota Hilux 2018 usados', 'otro_tipo', 'camionetas-toyota-hilux-2018'],
        ], array_slice($rows, 1));
    }
}
