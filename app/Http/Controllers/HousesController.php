<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class HousesController extends Controller
{
    public function home($type = null, $country = null)
    {
        return view('houses.home', compact('type', 'country'));
    }

    public function index(Request $request)
    {
        // Datos mock para diseño
        $mockHouses = collect([
            (object)[
                'id' => 1,
                'title' => 'Casa moderna 3 habitaciones en Quito',
                'type' => 'Casa',
                'bedrooms' => 3,
                'bathrooms' => 2,
                'price' => 150000,
                'area' => 120,
                'location' => 'Pichincha',
                'city' => 'Quito',
                'condition' => 'nueva',
                'image_url' => 'https://via.placeholder.com/150'
            ],
            (object)[
                'id' => 2,
                'title' => 'Departamento 2 dormitorios con vista',
                'type' => 'Departamento',
                'bedrooms' => 2,
                'bathrooms' => 1,
                'price' => 85000,
                'area' => 65,
                'location' => 'Guayas',
                'city' => 'Guayaquil',
                'condition' => 'usado',
                'image_url' => 'https://via.placeholder.com/150'
            ],
            (object)[
                'id' => 3,
                'title' => 'Villa 4 habitaciones con jardín',
                'type' => 'Villa',
                'bedrooms' => 4,
                'bathrooms' => 3,
                'price' => 250000,
                'area' => 200,
                'location' => 'Azuay',
                'city' => 'Cuenca',
                'condition' => 'nueva',
                'image_url' => 'https://via.placeholder.com/150'
            ],
        ]);

        // Simular paginación
        $perPage = 12;
        $currentPage = $request->get('page', 1);
        $houses = new LengthAwarePaginator(
            $mockHouses,
            $mockHouses->count(),
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Datos mock para filtros
        $types = collect(['Casa', 'Departamento', 'Villa', 'Terreno']);
        $locations = collect(['Pichincha', 'Guayas', 'Azuay', 'Manabí']);
        $cities = collect(['Quito', 'Guayaquil', 'Cuenca', 'Manta', 'Ambato', 'Santo Domingo', 'Portoviejo', 'Machala']);

        // Conteos mock
        $typeCounts = [
            'Casa' => 15,
            'Departamento' => 12,
            'Villa' => 8,
            'Terreno' => 5
        ];

        $locationCounts = [
            'Pichincha' => 20,
            'Guayas' => 15,
            'Azuay' => 10,
            'Manabí' => 8
        ];

        $cityCounts = [
            'Quito' => 12,
            'Guayaquil' => 10,
            'Cuenca' => 8,
            'Manta' => 5,
            'Ambato' => 4,
            'Santo Domingo' => 3,
            'Portoviejo' => 2,
            'Machala' => 2
        ];

        return view('houses.index', compact('houses', 'types', 'locations', 'cities', 'typeCounts', 'locationCounts', 'cityCounts'));
    }

    public function landing(Request $request, $type = null, $country = null, $slug = null)
    {
        // Parse slug para extraer información
        $slugData = $this->parseSlug($slug);

        // Datos mock para diseño
        $mockHouses = collect([
            (object)[
                'id' => 1,
                'title' => 'Casa moderna 3 habitaciones en Quito',
                'type' => 'Casa',
                'bedrooms' => 3,
                'bathrooms' => 2,
                'price' => 150000,
                'area' => 120,
                'location' => 'Pichincha',
                'city' => 'Quito',
                'condition' => 'nueva',
                'image_url' => 'https://via.placeholder.com/150'
            ],
            (object)[
                'id' => 2,
                'title' => 'Departamento 2 dormitorios con vista',
                'type' => 'Departamento',
                'bedrooms' => 2,
                'bathrooms' => 1,
                'price' => 85000,
                'area' => 65,
                'location' => 'Guayas',
                'city' => 'Guayaquil',
                'condition' => 'usado',
                'image_url' => 'https://via.placeholder.com/150'
            ],
            (object)[
                'id' => 3,
                'title' => 'Villa 4 habitaciones con jardín',
                'type' => 'Villa',
                'bedrooms' => 4,
                'bathrooms' => 3,
                'price' => 250000,
                'area' => 200,
                'location' => 'Azuay',
                'city' => 'Cuenca',
                'condition' => 'nueva',
                'image_url' => 'https://via.placeholder.com/150'
            ],
        ]);

        // Simular paginación
        $perPage = 12;
        $currentPage = $request->get('page', 1);
        $houses = new LengthAwarePaginator(
            $mockHouses,
            $mockHouses->count(),
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Datos mock para filtros
        $types = collect(['Casa', 'Departamento', 'Villa', 'Terreno']);
        $locations = collect(['Pichincha', 'Guayas', 'Azuay', 'Manabí']);
        $cities = collect(['Quito', 'Guayaquil', 'Cuenca', 'Manta', 'Ambato', 'Santo Domingo', 'Portoviejo', 'Machala']);

        // Conteos mock
        $typeCounts = [
            'Casa' => 15,
            'Departamento' => 12,
            'Villa' => 8,
            'Terreno' => 5
        ];

        $locationCounts = [
            'Pichincha' => 20,
            'Guayas' => 15,
            'Azuay' => 10,
            'Manabí' => 8
        ];

        $cityCounts = [
            'Quito' => 12,
            'Guayaquil' => 10,
            'Cuenca' => 8,
            'Manta' => 5,
            'Ambato' => 4,
            'Santo Domingo' => 3,
            'Portoviejo' => 2,
            'Machala' => 2
        ];

        return view('houses.index', compact('houses', 'types', 'locations', 'cities', 'typeCounts', 'locationCounts', 'cityCounts', 'slugData', 'type', 'country'));
    }

    private function parseSlug($slug)
    {
        // Parse slug: casa-quito, departamento-guayaquil
        $parts = explode('-', $slug);

        return [
            'original' => $slug,
            'type' => $parts[0] ?? null,
            'city' => $parts[1] ?? null,
            'title' => ucwords(str_replace('-', ' ', $slug))
        ];
    }

    public function show($type = null, $country = null, $id = null)
    {
        // Dato mock para diseño
        $house = (object)[
            'id' => $id,
            'title' => 'Casa moderna 3 habitaciones en Quito',
            'type' => 'Casa',
            'bedrooms' => 3,
            'bathrooms' => 2,
            'price' => 150000,
            'area' => 120,
            'location' => 'Pichincha',
            'city' => 'Quito',
            'condition' => 'nueva',
            'image_url' => 'https://via.placeholder.com/150'
        ];

        return view('houses.show', compact('house', 'type', 'country'));
    }
}
