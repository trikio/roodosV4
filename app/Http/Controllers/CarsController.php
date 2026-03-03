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

    private function normalizeOrder(Request $request): string
    {
        $order = (string) $request->get('order', '');
        if ($order !== '') {
            return $order;
        }

        // Backward compatibility with legacy "sort" query param used in views.
        return match ($request->get('sort')) {
            'price_low' => 'priceasc',
            'price_high' => 'pricedesc',
            default => '',
        };
    }

    public function home($country = null)
    {
        // Extract type from subdomain (autos.roodos.* -> 'autos')
        $type = 'autos';

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
            'order' => $this->normalizeOrder($request),
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
                'nexo_id' => $item['nexo_id'] ?? '',
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
        $countryCode = $country ?: $this->resolveCountryFromHost($request);
        $countryCode = $countryCode ?: 'lab';

        $landing = $this->manticore->getLandingBySlug($countryCode, (string) $slug);

        if (!$landing || empty($landing['title'])) {
            abort(404);
        }

        $searchQuery = trim((string) $landing['title']);
        $slugData = [
            'original' => $slug,
            'title' => $landing['title'],
        ];

        // DEBUG - Check what parameters are being received
        \Log::info('[LANDING] Method called', [
            'type' => $type,
            'country' => $countryCode,
            'slug' => $slug,
            'landing_title' => $landing['title'],
            'url' => $request->url(),
            'path' => $request->path()
        ]);

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
            'order' => $this->normalizeOrder($request),
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
                'nexo_id' => $item['nexo_id'] ?? '',
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

        $country = $countryCode;
        return view('cars.index', compact('cars', 'brands', 'models', 'locations', 'cities', 'slugData', 'type', 'country', 'searchQuery'));
    }

    private function resolveCountryFromHost(Request $request): ?string
    {
        $host = $request->getHost();
        if (preg_match('/^autos\.roodos\.([^.]+)$/', $host, $matches)) {
            return $matches[1];
        }

        return null;
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
        $carId = (int) $id;

        if ($carId <= 0) {
            abort(404);
        }

        $car = $this->manticore->getCarById('car_ec', $carId);

        if (!$car || empty($car['url'])) {
            abort(404);
        }

        $targetUrl = (string) $car['url'];
        $source = (string) ($car['nexo_id'] ?? 'fuente externa');

        return view('cars.show', compact('targetUrl', 'source', 'country'));
    }
}
