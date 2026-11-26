<?php

namespace Tests\Feature;

use App\Services\LandingLinks;
use App\Services\ManticoreSearchService;
use Mockery;
use Tests\TestCase;

class CarLandingLinksTest extends TestCase
{
    public function test_car_detail_links_the_years_nearest_to_the_car_and_no_classics(): void
    {
        $rows = [['id' => 1, 'slug' => 'clasicos-de-coleccion-chevrolet-cruze-usados', 'title' => 'Clasicos de Coleccion Chevrolet Cruze usados']];
        foreach (range(2010, 2019) as $i => $year) {
            $rows[] = ['id' => 10 + $i, 'slug' => "chevrolet-cruze-{$year}", 'title' => "Chevrolet Cruze {$year}"];
        }
        $rows[] = ['id' => 99, 'slug' => 'autos-chevrolet-cruze-usados', 'title' => 'Autos Chevrolet Cruze usados'];

        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('carLandingsByIds')->andReturn([]);
        $manticore->shouldReceive('similarCarLandings')->with('pe', 'chevrolet cruze', true, 500)->andReturn($rows);
        $manticore->shouldReceive('similarCarLandings')->andReturn([]);

        $links = (new LandingLinks($manticore))->forCar('pe', [
            'make_id' => 0, 'model_id' => 0, 'city_id' => 0,
            'make' => 'Chevrolet', 'model' => 'Cruze', 'city' => '', 'year' => 2017,
        ]);

        $this->assertSame([
            'autos-chevrolet-cruze-usados',
            'chevrolet-cruze-2017', 'chevrolet-cruze-2018', 'chevrolet-cruze-2016',
            'chevrolet-cruze-2019', 'chevrolet-cruze-2015', 'chevrolet-cruze-2014', 'chevrolet-cruze-2013',
        ], array_map(fn ($link) => basename($link['url']), $links));
    }

    public function test_car_detail_links_the_landings_of_its_transmission_fuel_and_region(): void
    {
        $manticore = Mockery::mock(ManticoreSearchService::class);
        $manticore->shouldReceive('carLandingsByIds')->andReturn([]);
        $manticore->shouldReceive('similarCarLandings')->with('cl', 'toyota hilux', true, 500)->andReturn([
            ['id' => 1, 'slug' => 'toyota-hilux-manual-usados', 'title' => 'Toyota Hilux manual usados'],
            ['id' => 2, 'slug' => 'toyota-hilux-automatico-usados', 'title' => 'Toyota Hilux automático usados'],
            ['id' => 3, 'slug' => 'toyota-hilux-diesel-usados', 'title' => 'Toyota Hilux diésel usados'],
            ['id' => 4, 'slug' => 'toyota-hilux-usados-en-santiago', 'title' => 'Toyota Hilux usados en Santiago'],
            ['id' => 5, 'slug' => 'toyota-hilux-usados-en-biobio', 'title' => 'Toyota Hilux usados en Biobío'],
        ]);
        $manticore->shouldReceive('similarCarLandings')->andReturn([]);

        $links = (new LandingLinks($manticore))->forCar('cl', [
            'make_id' => 0, 'model_id' => 0, 'city_id' => 0, 'make' => 'Toyota', 'model' => 'Hilux', 'city' => '',
            'year' => 2019, 'transmission' => 'Automático', 'fuel' => 'Diesel', 'state' => 'Región Metropolitana',
        ]);

        $this->assertSame(
            ['toyota-hilux-automatico-usados', 'toyota-hilux-diesel-usados', 'toyota-hilux-usados-en-santiago'],
            array_map(fn ($link) => basename($link['url']), $links)
        );
    }
}
