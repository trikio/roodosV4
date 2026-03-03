<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Services\ManticoreSearchService;

class CarsController extends Controller
{
    protected $manticore;

    public function __construct(ManticoreSearchService $manticore)
    {
        $this->manticore = $manticore;
    }

    /**
     * Normalize country parameter - convert 'local' to 'ec' for development
     */
    private function normalizeCountry($country)
    {
        return $country === 'local' ? 'ec' : $country;
    }

    public function home($country = null)
    {
        // Extract type from subdomain (autos.roodos.* -> 'autos')
        $type = 'autos';

        // Normalize country parameter (convert 'local' to 'ec' for development)
        $country = $this->normalizeCountry($country);

        // Get total cars count
        $options = [
            'page' => 1,
            'q' => '',
            'make' => '',
            'model' => '',
            'state' => '',
            'city' => '',
            'fuel' => '',
            'transmission' => '',
            'body' => '',
            'min_price' => '',
            'max_price' => '',
            'min_year' => '',
            'max_year' => '',
            'min_km' => '',
            'max_km' => '',
            'order' => '',
        ];

        $results = $this->manticore->carSearch('car_ec', $options);

        // Get total from meta
        $totalCars = 0;
        if (!empty($results['info'])) {
            foreach ($results['info'] as $meta) {
                if (isset($meta['Variable_name']) && $meta['Variable_name'] === 'total_found') {
                    $totalCars = (int)$meta['Value'];
                    break;
                }
            }
        }

        return view('cars.home', compact('type', 'country', 'totalCars'));
    }

    public function index(Request $request)
    {
        // Build search options from request
        $options = [
            'page' => $request->get('page', 1),
            'q' => $request->get('q', ''),
            'make' => $request->get('brand', ''),
            'model' => $request->get('model', ''),
            'state' => $request->get('location', ''),
            'city' => $request->get('city', ''),
            'fuel' => $request->get('fuel', ''),
            'transmission' => $request->get('transmission', ''),
            'body' => $request->get('body', ''),
            'min_price' => $request->get('min_price', ''),
            'max_price' => $request->get('max_price', ''),
            'min_year' => $request->get('min_year', ''),
            'max_year' => $request->get('max_year', ''),
            'min_km' => $request->get('min_km', ''),
            'max_km' => $request->get('max_km', ''),
            'order' => $request->get('order', ''),
        ];

        // Search using ManticoreSearch
        $results = $this->manticore->carSearch('car_ec', $options);

        // DEBUG
        \Log::info('[INDEX] ManticoreSearch Results', [
            'items_count' => count($results['items'] ?? []),
            'info_count' => count($results['info'] ?? []),
            'make_count' => count($results['make'] ?? []),
            'query' => $options['q'],
            'raw_results_keys' => array_keys($results)
        ]);

        // Get total from meta
        $total = 0;
        if (!empty($results['info'])) {
            foreach ($results['info'] as $meta) {
                if (isset($meta['Variable_name']) && $meta['Variable_name'] === 'total_found') {
                    $total = (int)$meta['Value'];
                    break;
                }
            }
        }

        // DEBUG
        \Log::info('Total calculation', [
            'total' => $total,
            'info_array' => $results['info'] ?? []
        ]);

        // Check if requested page exceeds maximum available pages
        $maxPage = $total > 0 ? (int)ceil($total / ManticoreSearchService::RESULTS_PER_PAGE) : 1;
        if ($options['page'] > $maxPage) {
            // Redirect to the last available page
            $query = $request->query();
            $query['page'] = $maxPage;
            return redirect()->to($request->path() . '?' . http_build_query($query));
        }

        // Transform results to objects for view
        $carsCollection = collect($results['items'])->map(function ($item) {
            return (object)[
                'id' => $item['id'],
                'title' => $item['title'],
                'brand' => $item['make_name'],
                'model' => $item['model_name'],
                'price' => $item['price'],
                'year' => $item['year_int'],
                'kilometers' => $item['km'],
                'location' => $item['state_name'],
                'city' => $item['city_name'],
                'transmission' => $item['transmission_name'],
                'fuel' => $item['fuel_name'],
                'body' => $item['body_name'],
                'condition' => 'usado',
                'image_url' => $item['image'] ?? 'https://via.placeholder.com/150',
                'url' => $item['url'] ?? '#',
                'slug' => $item['slug'] ?? '',
            ];
        });

        // DEBUG
        \Log::info('Collection created', [
            'collection_count' => $carsCollection->count(),
            'first_item' => $carsCollection->first()
        ]);

        // Create paginator
        $cars = new LengthAwarePaginator(
            $carsCollection,
            $total,
            ManticoreSearchService::RESULTS_PER_PAGE,
            $options['page'],
            ['path' => '/search', 'query' => $request->query()]
        );

        // Process facets for filters (with IDs, excluding empty values)
        $brands = collect($results['make'])
            ->filter(function($item) {
                return !empty($item['name']);
            })
            ->map(function($item) {
                return ['id' => $item['id_car_make'], 'name' => $item['name'], 'total' => $item['total']];
            });
        $models = collect($results['model'])
            ->filter(function($item) {
                return !empty($item['name']);
            })
            ->map(function($item) {
                return ['id' => $item['id_car_model'], 'name' => $item['name'], 'total' => $item['total']];
            });
        $locations = collect($results['state'])
            ->filter(function($item) {
                return !empty($item['name']);
            })
            ->map(function($item) {
                return ['id' => $item['id_state'], 'name' => $item['name'], 'total' => $item['total']];
            });
        $cities = collect($results['city'])
            ->filter(function($item) {
                return !empty($item['name']);
            })
            ->map(function($item) {
                return ['id' => $item['id_city'], 'name' => $item['name'], 'total' => $item['total']];
            });

        $searchQuery = $options['q'];
        return view('cars.index', compact('cars', 'brands', 'models', 'locations', 'cities', 'searchQuery'));
    }

    public function landing(Request $request, $country = null, $slug = null)
    {
        // Extract type from subdomain (autos.roodos.* -> 'autos')
        $type = 'autos';

        // Normalize country parameter (convert 'local' to 'ec' for development)
        $country = $this->normalizeCountry($country);

        // DEBUG - Check what parameters are being received
        \Log::info('[LANDING] Method called', [
            'type' => $type,
            'country' => $country,
            'slug' => $slug,
            'url' => $request->url(),
            'path' => $request->path()
        ]);

        // Parse slug para extraer información (chevrolet-aveo-quito)
        $slugData = $this->parseSlug($slug);

        // Build search query from slug
        $searchQuery = trim($slugData['brand'] . ' ' . $slugData['model']);

        // Build search options from request and slug
        $options = [
            'page' => $request->get('page', 1),
            'q' => $searchQuery,
            'make' => $request->get('brand', ''),
            'model' => $request->get('model', ''),
            'state' => $request->get('location', ''),
            'city' => $request->get('city', ''),
            'fuel' => $request->get('fuel', ''),
            'transmission' => $request->get('transmission', ''),
            'body' => $request->get('body', ''),
            'min_price' => $request->get('min_price', ''),
            'max_price' => $request->get('max_price', ''),
            'min_year' => $request->get('min_year', ''),
            'max_year' => $request->get('max_year', ''),
            'min_km' => $request->get('min_km', ''),
            'max_km' => $request->get('max_km', ''),
            'order' => $request->get('order', ''),
        ];

        // Search using ManticoreSearch
        $results = $this->manticore->carSearch('car_ec', $options);

        // DEBUG
        \Log::info('[LANDING] ManticoreSearch Results', [
            'items_count' => count($results['items'] ?? []),
            'info_count' => count($results['info'] ?? []),
            'make_count' => count($results['make'] ?? []),
            'query' => $options['q'],
            'slugData' => $slugData,
            'raw_results_keys' => array_keys($results)
        ]);

        // Get total from meta
        $total = 0;
        if (!empty($results['info'])) {
            foreach ($results['info'] as $meta) {
                if (isset($meta['Variable_name']) && $meta['Variable_name'] === 'total_found') {
                    $total = (int)$meta['Value'];
                    break;
                }
            }
        }

        // DEBUG
        \Log::info('Total calculation', [
            'total' => $total,
            'info_array' => $results['info'] ?? []
        ]);

        // Check if requested page exceeds maximum available pages
        $maxPage = $total > 0 ? (int)ceil($total / ManticoreSearchService::RESULTS_PER_PAGE) : 1;
        if ($options['page'] > $maxPage) {
            // Redirect to the last available page
            $query = $request->query();
            $query['page'] = $maxPage;
            return redirect()->to($request->path() . '?' . http_build_query($query));
        }

        // Transform results to objects for view
        $carsCollection = collect($results['items'])->map(function ($item) {
            return (object)[
                'id' => $item['id'],
                'title' => $item['title'],
                'brand' => $item['make_name'],
                'model' => $item['model_name'],
                'price' => $item['price'],
                'year' => $item['year_int'],
                'kilometers' => $item['km'],
                'location' => $item['state_name'],
                'city' => $item['city_name'],
                'transmission' => $item['transmission_name'],
                'fuel' => $item['fuel_name'],
                'body' => $item['body_name'],
                'condition' => 'usado',
                'image_url' => $item['image'] ?? 'https://via.placeholder.com/150',
                'url' => $item['url'] ?? '#',
                'slug' => $item['slug'] ?? '',
            ];
        });

        // DEBUG
        \Log::info('Collection created', [
            'collection_count' => $carsCollection->count(),
            'first_item' => $carsCollection->first()
        ]);

        // Create paginator without q parameter (slug contains the search info)
        $cars = new LengthAwarePaginator(
            $carsCollection,
            $total,
            ManticoreSearchService::RESULTS_PER_PAGE,
            $options['page'],
            ['path' => $request->path(), 'query' => $request->query()]
        );

        // Process facets for filters (with IDs, excluding empty values)
        $brands = collect($results['make'])
            ->filter(function($item) {
                return !empty($item['name']);
            })
            ->map(function($item) {
                return ['id' => $item['id_car_make'], 'name' => $item['name'], 'total' => $item['total']];
            });
        $models = collect($results['model'])
            ->filter(function($item) {
                return !empty($item['name']);
            })
            ->map(function($item) {
                return ['id' => $item['id_car_model'], 'name' => $item['name'], 'total' => $item['total']];
            });
        $locations = collect($results['state'])
            ->filter(function($item) {
                return !empty($item['name']);
            })
            ->map(function($item) {
                return ['id' => $item['id_state'], 'name' => $item['name'], 'total' => $item['total']];
            });
        $cities = collect($results['city'])
            ->filter(function($item) {
                return !empty($item['name']);
            })
            ->map(function($item) {
                return ['id' => $item['id_city'], 'name' => $item['name'], 'total' => $item['total']];
            });

        return view('cars.index', compact('cars', 'brands', 'models', 'locations', 'cities', 'slugData', 'type', 'country', 'searchQuery'));
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

    public function show($country = null, $id = null)
    {
        // Extract type from subdomain (autos.roodos.* -> 'autos')
        $type = 'autos';

        // Normalize country parameter (convert 'local' to 'ec' for development)
        $country = $this->normalizeCountry($country);

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
