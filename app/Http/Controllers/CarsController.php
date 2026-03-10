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

    public function home(Request $request, $country = null)
    {
        // Extract type from subdomain (autos.roodos.* -> 'autos')
        $type = 'autos';
        $countryCode = $this->resolveCountryCode($request, $country);
        $searchIndex = $this->getCarsIndexName($countryCode);

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

        $results = $this->manticore->carSearch($searchIndex, $options);

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

        $country = $countryCode;
        return view('cars.home', compact('type', 'country', 'totalCars'));
    }

    public function index(Request $request)
    {
        return $this->renderCarsIndex($request);
    }

    public function brand(Request $request, $country = null, string $slug = '')
    {
        $make = $this->manticore->getCarMakeBySlug($slug);

        if (!$make || empty($make['id'])) {
            abort(404);
        }

        $request->merge(['brand' => (int) $make['id']]);

        return $this->renderCarsIndex($request, $country, [
            'make' => (int) $make['id'],
        ], [
            'slugData' => [
                'original' => $slug,
                'title' => $make['name'] ?? ucfirst(str_replace('-', ' ', $slug)),
            ],
            'searchQuery' => '',
        ]);
    }

    public function model(Request $request, $country = null, string $slug = '')
    {
        $model = $this->manticore->getCarModelBySlug($slug);

        if (!$model || empty($model['id'])) {
            abort(404);
        }

        $request->merge([
            'brand' => (int) ($model['id_car_make'] ?? 0),
            'model' => (int) $model['id'],
        ]);

        return $this->renderCarsIndex($request, $country, [
            'make' => (int) ($model['id_car_make'] ?? 0),
            'model' => (int) $model['id'],
        ], [
            'slugData' => [
                'original' => $slug,
                'title' => $model['make_model'] ?? $model['model_name'] ?? ucfirst(str_replace('-', ' ', $slug)),
            ],
            'searchQuery' => '',
        ]);
    }

    public function landing(Request $request, $country = null, $slug = null)
    {
        // Extract type from subdomain (autos.roodos.* -> 'autos')
        $type = 'autos';
        $countryCode = $this->resolveCountryCode($request, $country);

        $landing = $this->manticore->getLandingBySlug($countryCode, (string) $slug);

        if (!$landing || empty($landing['title'])) {
            abort(404);
        }

        $searchQuery = trim((string) $landing['title']);
        $slugData = [
            'original' => $slug,
            'title' => $landing['title'],
        ];

        return $this->renderCarsIndex($request, $country, [
            'q' => $searchQuery,
        ], compact('slugData', 'type', 'searchQuery'));
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
        $request = request();
        $countryCode = $this->resolveCountryCode($request, $country);
        $searchIndex = $this->getCarsIndexName($countryCode);
        $carId = (int) $id;

        if ($carId <= 0) {
            abort(404);
        }

        $car = $this->manticore->getCarById($searchIndex, $carId);

        if (!$car || empty($car['url'])) {
            abort(404);
        }

        $targetUrl = (string) $car['url'];
        $source = (string) ($car['nexo_id'] ?? 'fuente externa');

        $country = $countryCode;
        return response()
            ->view('cars.show', compact('targetUrl', 'source', 'country'))
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function resolveCountryCode(Request $request, ?string $country = null): string
    {
        $countryCode = strtolower((string) ($country ?: $this->resolveCountryFromHost($request) ?: 'lab'));

        if (!preg_match('/^[a-z]{2,5}$/', $countryCode)) {
            return 'lab';
        }

        return $countryCode;
    }

    private function getCarsIndexName(string $countryCode): string
    {
        return 'car_' . strtolower($countryCode);
    }

    private function renderCarsIndex(Request $request, ?string $country = null, array $overrides = [], array $viewData = [])
    {
        $countryCode = $this->resolveCountryCode($request, $country);
        $searchIndex = $this->getCarsIndexName($countryCode);

        $options = array_merge([
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
        ], $overrides);

        $request->merge([
            'q' => $options['q'],
            'brand' => $options['make'],
            'model' => $options['model'],
            'location' => $options['state'],
            'city' => $options['city'],
            'fuel' => $options['fuel'],
            'transmission' => $options['transmission'],
            'body' => $options['body'],
            'min_price' => $options['min_price'],
            'max_price' => $options['max_price'],
            'min_year' => $options['min_year'],
            'max_year' => $options['max_year'],
            'min_km' => $options['min_km'],
            'max_km' => $options['max_km'],
            'order' => $options['order'],
        ]);

        $results = $this->manticore->carSearch($searchIndex, $options);
        $total = $this->extractTotal($results['info'] ?? []);

        $maxPage = $total > 0 ? (int) ceil($total / ManticoreSearchService::RESULTS_PER_PAGE) : 1;
        if ((int) $options['page'] > $maxPage) {
            $query = $request->query();
            $query['page'] = $maxPage;
            return redirect()->to($request->path() . '?' . http_build_query($query));
        }

        $carsCollection = collect($results['items'])->map(function ($item) {
            return (object) [
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
                'image_url' => $item['image'] ?? null,
                'url' => $item['url'] ?? '#',
                'slug' => $item['slug'] ?? '',
                'nexo_id' => $item['nexo_id'] ?? '',
            ];
        });

        $cars = new LengthAwarePaginator(
            $carsCollection,
            $total,
            ManticoreSearchService::RESULTS_PER_PAGE,
            $options['page'],
            ['path' => $request->path(), 'query' => $request->query()]
        );

        $brands = $this->mapFacetCollection($results['make'] ?? [], 'id_car_make');
        $models = $this->mapFacetCollection($results['model'] ?? [], 'id_car_model');
        $locations = $this->mapFacetCollection($results['state'] ?? [], 'id_state');
        $cities = $this->mapFacetCollection($results['city'] ?? [], 'id_city');
        $searchQuery = $viewData['searchQuery'] ?? $options['q'];
        $type = $viewData['type'] ?? 'autos';
        $country = $countryCode;

        return view('cars.index', array_merge([
            'cars' => $cars,
            'brands' => $brands,
            'models' => $models,
            'locations' => $locations,
            'cities' => $cities,
            'searchQuery' => $searchQuery,
            'type' => $type,
            'country' => $country,
        ], $viewData));
    }

    private function extractTotal(array $info): int
    {
        foreach ($info as $meta) {
            if (($meta['Variable_name'] ?? null) === 'total_found') {
                return (int) $meta['Value'];
            }
        }

        return 0;
    }

    private function mapFacetCollection(array $items, string $idField)
    {
        return collect($items)
            ->filter(function ($item) {
                return !empty($item['name']);
            })
            ->map(function ($item) use ($idField) {
                return ['id' => $item[$idField], 'name' => $item['name'], 'total' => $item['total']];
            });
    }
}
