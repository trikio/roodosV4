<?php

namespace Tests\Feature;

use App\Services\ManticoreSearchService;
use Mockery;
use Tests\TestCase;

class HouseLandingCandidatesCommandTest extends TestCase
{
    private function group(int $op, string $opName, int $type, string $typeName, int $state, string $stateName, int $city, string $cityName, int $total): array
    {
        return [
            'id_house_operation' => $op, 'house_operation_name' => $opName, 'id_house_type' => $type, 'house_type_name' => $typeName,
            'id_state' => $state, 'state_name' => $stateName, 'id_city' => $city, 'city_name' => $cityName, 'total' => $total,
        ];
    }

    public function test_proposes_country_region_and_city_landings_that_narrow_the_search(): void
    {
        $output = sys_get_temp_dir() . '/house-landings-candidates-test';
        array_map('unlink', glob($output . '/*') ?: []);

        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('houseCountsByPlace')->with('house_mx', 'country', 8)->andReturn([
            $this->group(1, 'Venta', 2, 'Departamento', 9, 'Distrito Federal', 15, 'Miguel Hidalgo', 1000),
            $this->group(2, 'Renta', 1, 'Casa', 9, 'Distrito Federal', 15, 'Miguel Hidalgo', 200),
        ]);
        $manticore->shouldReceive('houseCountsByPlace')->with('house_mx', 'state', 8)->andReturn([
            $this->group(1, 'Venta', 2, 'Departamento', 9, 'Distrito Federal', 15, 'Miguel Hidalgo', 400),
            $this->group(1, 'Venta', 2, 'Departamento', 22, 'Estado de Querétaro de Arteaga', 50, 'Querétaro', 100),
            $this->group(2, 'Renta', 1, 'Casa', 23, 'Estado de Quintana Roo', 60, 'Benito Juárez', 30),
        ]);
        $manticore->shouldReceive('houseCountsByPlace')->with('house_mx', 'city', 8)->andReturn([
            $this->group(1, 'Venta', 2, 'Departamento', 9, 'Distrito Federal', 15, 'Miguel Hidalgo', 120),
            // Almost all of its region: the region's landing is the same page.
            $this->group(1, 'Venta', 2, 'Departamento', 22, 'Estado de Querétaro de Arteaga', 50, 'Querétaro', 95),
            $this->group(1, 'Venta', 2, 'Departamento', 9, 'Distrito Federal', 16, 'Benito Juárez', 40),
            $this->group(2, 'Renta', 1, 'Casa', 23, 'Estado de Quintana Roo', 60, 'Benito Juárez', 12),
            // Only the region was named: there is no city called Nayarit.
            $this->group(1, 'Venta', 2, 'Departamento', 18, 'Estado de Nayarit', 70, 'Nayarit', 20),
        ]);
        $manticore->shouldReceive('iterateHouseLandings')->with('mx')->andReturnUsing(fn () => (function () {
            yield ['slug' => 'arriendo-casas-benito-juarez', 'title' => 'Arriendo Casas Benito Juárez, Quintana Roo'];
        })());
        $this->app->instance(ManticoreSearchService::class, $manticore);

        $this->artisan('house-landings:candidates', ['country' => 'mx', '--output' => $output, '--sql' => true])->assertSuccessful();

        $rows = array_map('str_getcsv', file(glob($output . '/house_landings_candidates_mx_*.csv')[0], FILE_IGNORE_NEW_LINES));
        $this->assertSame([
            ['pais', 'departamentos-en-venta', 'nueva'],
            ['pais', 'casas-en-renta', 'nueva'],
            ['region', 'departamentos-en-venta-en-ciudad-de-mexico', 'nueva'],
            ['region', 'departamentos-en-venta-en-queretaro', 'nueva'],
            ['region', 'casas-en-renta-en-quintana-roo', 'nueva'],
            ['ciudad', 'departamentos-en-venta-en-miguel-hidalgo', 'nueva'],
            ['ciudad', 'departamentos-en-venta-en-la-ciudad-de-queretaro', 'no_acota'],
            ['ciudad', 'departamentos-en-venta-en-benito-juarez-ciudad-de-mexico', 'nueva'],
            // A legacy landing of the same search ("arriendo" is "renta").
            ['ciudad', 'casas-en-renta-en-benito-juarez-quintana-roo', 'existe'],
        ], array_map(fn ($row) => [$row[0], $row[5], $row[7]], array_slice($rows, 1)));

        $sql = file_get_contents(glob($output . '/house_landings_candidates_mx_*.sql')[0]);
        $this->assertSame(7, substr_count($sql, 'INSERT INTO house_landings'));
        $this->assertStringContainsString("'Departamentos en venta en Miguel Hidalgo', 'Departamentos en venta en Miguel Hidalgo', 'Departamentos en venta en Miguel Hidalgo, México'", $sql);
        $this->assertStringContainsString('{"generated":{"operation":1,"type":2,"city":15,"created":"' . date('Y-m-d') . '"}}\', 1, 2, 9, 15, 1, 120)', $sql);
        $this->assertStringContainsString("'Departamentos en venta en México'", $sql);
        $this->assertLessThan(strpos($sql, 'departamentos-en-venta-en-miguel-hidalgo'), strpos($sql, "'departamentos-en-venta'"));
    }
}
