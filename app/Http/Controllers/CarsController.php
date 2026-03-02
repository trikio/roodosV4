<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class CarsController extends Controller
{
    public function home($type = null, $country = null)
    {
        return view('cars.home', compact('type', 'country'));
    }

    public function index(Request $request)
    {
        // Datos mock para diseño
        $mockCars = collect([
            (object)[
                'id' => 1,
                'title' => 'Honda CR-V 2023 Automático',
                'brand' => 'Honda',
                'model' => 'CR-V',
                'price' => 18500000,
                'year' => 2023,
                'kilometers' => 15000,
                'location' => 'Región Metropolitana',
                'city' => 'Vitacura',
                'transmission' => 'Automático',
                'condition' => 'usado',
                'image_url' => 'https://via.placeholder.com/150'
            ],
            (object)[
                'id' => 2,
                'title' => 'Honda Pilot 2022 4x4',
                'brand' => 'Honda',
                'model' => 'Pilot',
                'price' => 25000000,
                'year' => 2022,
                'kilometers' => 25000,
                'location' => 'Región Metropolitana',
                'city' => 'Las Condes',
                'transmission' => 'Automático',
                'condition' => 'usado',
                'image_url' => 'https://via.placeholder.com/150'
            ],
            (object)[
                'id' => 3,
                'title' => 'Honda Civic Ferio 2020',
                'brand' => 'Honda',
                'model' => 'Civic Ferio',
                'price' => 12000000,
                'year' => 2020,
                'kilometers' => 45000,
                'location' => 'Región Metropolitana',
                'city' => 'Santiago',
                'transmission' => 'Manual',
                'condition' => 'usado',
                'image_url' => 'https://via.placeholder.com/150'
            ],
        ]);

        // Simular paginación
        $perPage = 12;
        $currentPage = $request->get('page', 1);
        $cars = new LengthAwarePaginator(
            $mockCars,
            $mockCars->count(),
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Datos mock para filtros
        $brands = collect(['Honda', 'Toyota', 'Mazda', 'Nissan', 'Chevrolet']);
        $models = collect(['CR-V', 'Pilot', 'Civic Ferio', 'HR-V', 'Accord', 'City', 'Ridgeline']);
        $locations = collect(['Región Metropolitana', 'Valparaíso', 'Biobío']);
        $cities = collect(['Vitacura', 'Las Condes', 'Rancagua', 'La Cisterna', 'Providencia', 'Ñuñoa', 'La Florida', 'Los Álamos', 'Santiago', 'Viña del Mar', 'Punta Arenas']);

        // Conteos mock
        $brandCounts = [
            'Honda' => 10,
            'Toyota' => 8,
            'Mazda' => 5,
            'Nissan' => 6,
            'Chevrolet' => 4
        ];

        $modelCounts = [
            'CR-V' => 5,
            'Pilot' => 3,
            'Civic Ferio' => 4,
            'HR-V' => 2,
            'Accord' => 3,
            'City' => 2,
            'Ridgeline' => 1
        ];

        $locationCounts = [
            'Región Metropolitana' => 20,
            'Valparaíso' => 8,
            'Biobío' => 5
        ];

        $cityCounts = [
            'Vitacura' => 10,
            'Las Condes' => 4,
            'Rancagua' => 2,
            'La Cisterna' => 2,
            'Providencia' => 2,
            'Ñuñoa' => 1,
            'La Florida' => 1,
            'Los Álamos' => 1,
            'Santiago' => 1,
            'Viña del Mar' => 1,
            'Punta Arenas' => 1
        ];

        return view('cars.index', compact('cars', 'brands', 'models', 'locations', 'cities', 'brandCounts', 'modelCounts', 'locationCounts', 'cityCounts'));
    }

    public function landing(Request $request, $type = null, $country = null, $slug = null)
    {
        // Parse slug para extraer información (chevrolet-aveo-quito)
        $slugData = $this->parseSlug($slug);

        // Datos mock para diseño
        $mockCars = collect([
            (object)[
                'id' => 1,
                'title' => 'Honda CR-V 2023 Automático',
                'brand' => 'Honda',
                'model' => 'CR-V',
                'price' => 18500000,
                'year' => 2023,
                'kilometers' => 15000,
                'location' => 'Región Metropolitana',
                'city' => 'Vitacura',
                'transmission' => 'Automático',
                'condition' => 'usado',
                'image_url' => 'https://via.placeholder.com/150'
            ],
            (object)[
                'id' => 2,
                'title' => 'Honda Pilot 2022 4x4',
                'brand' => 'Honda',
                'model' => 'Pilot',
                'price' => 25000000,
                'year' => 2022,
                'kilometers' => 25000,
                'location' => 'Región Metropolitana',
                'city' => 'Las Condes',
                'transmission' => 'Automático',
                'condition' => 'usado',
                'image_url' => 'https://via.placeholder.com/150'
            ],
            (object)[
                'id' => 3,
                'title' => 'Honda Civic Ferio 2020',
                'brand' => 'Honda',
                'model' => 'Civic Ferio',
                'price' => 12000000,
                'year' => 2020,
                'kilometers' => 45000,
                'location' => 'Región Metropolitana',
                'city' => 'Santiago',
                'transmission' => 'Manual',
                'condition' => 'usado',
                'image_url' => 'https://via.placeholder.com/150'
            ],
        ]);

        // Simular paginación
        $perPage = 12;
        $currentPage = $request->get('page', 1);
        $cars = new LengthAwarePaginator(
            $mockCars,
            $mockCars->count(),
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Datos mock para filtros
        $brands = collect(['Honda', 'Toyota', 'Mazda', 'Nissan', 'Chevrolet']);
        $models = collect(['CR-V', 'Pilot', 'Civic Ferio', 'HR-V', 'Accord', 'City', 'Ridgeline']);
        $locations = collect(['Región Metropolitana', 'Valparaíso', 'Biobío']);
        $cities = collect(['Vitacura', 'Las Condes', 'Rancagua', 'La Cisterna', 'Providencia', 'Ñuñoa', 'La Florida', 'Los Álamos', 'Santiago', 'Viña del Mar', 'Punta Arenas']);

        // Conteos mock
        $brandCounts = [
            'Honda' => 10,
            'Toyota' => 8,
            'Mazda' => 5,
            'Nissan' => 6,
            'Chevrolet' => 4
        ];

        $modelCounts = [
            'CR-V' => 5,
            'Pilot' => 3,
            'Civic Ferio' => 4,
            'HR-V' => 2,
            'Accord' => 3,
            'City' => 2,
            'Ridgeline' => 1
        ];

        $locationCounts = [
            'Región Metropolitana' => 20,
            'Valparaíso' => 8,
            'Biobío' => 5
        ];

        $cityCounts = [
            'Vitacura' => 10,
            'Las Condes' => 4,
            'Rancagua' => 2,
            'La Cisterna' => 2,
            'Providencia' => 2,
            'Ñuñoa' => 1,
            'La Florida' => 1,
            'Los Álamos' => 1,
            'Santiago' => 1,
            'Viña del Mar' => 1,
            'Punta Arenas' => 1
        ];

        return view('cars.index', compact('cars', 'brands', 'models', 'locations', 'cities', 'brandCounts', 'modelCounts', 'locationCounts', 'cityCounts', 'slugData', 'type', 'country'));
    }

    private function parseSlug($slug)
    {
        // Parse slug: chevrolet-aveo-quito
        $parts = explode('-', $slug);

        return [
            'original' => $slug,
            'brand' => $parts[0] ?? null,
            'model' => $parts[1] ?? null,
            'city' => $parts[2] ?? null,
            'title' => ucwords(str_replace('-', ' ', $slug))
        ];
    }

    public function show($type = null, $country = null, $id = null)
    {
        // Dato mock para diseño
        $car = (object)[
            'id' => $id,
            'title' => 'Honda CR-V 2023 Automático',
            'brand' => 'Honda',
            'model' => 'CR-V',
            'price' => 18500000,
            'year' => 2023,
            'kilometers' => 15000,
            'location' => 'Región Metropolitana',
            'city' => 'Vitacura',
            'transmission' => 'Automático',
            'condition' => 'usado',
            'image_url' => 'https://via.placeholder.com/150'
        ];

        return view('cars.show', compact('car', 'type', 'country'));
    }
}
