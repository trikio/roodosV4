<?php

namespace App\Http\Controllers;

use App\Services\ManticoreSearchService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class HousesController extends Controller
{
    protected ManticoreSearchService $manticore;

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

        return match ($request->get('sort')) {
            'price_low' => 'priceasc',
            'price_high' => 'pricedesc',
            default => '',
        };
    }

    public function home(Request $request, $country = null)
    {
        $type = 'casas';
        $countryCode = $this->resolveCountryCode($request, $country);
        $searchIndex = $this->getHousesIndexName($countryCode);

        $options = [
            'page' => 1,
            'q' => '',
            'operation' => '',
            'type' => '',
            'state' => '',
            'city' => '',
            'min_price' => '',
            'max_price' => '',
            'rooms' => '',
            'bath' => '',
            'min_size' => '',
            'max_size' => '',
            'order' => '',
        ];

        $results = $this->safeHouseSearch($searchIndex, $options);
        $totalHouses = $this->extractTotalFromMeta($results['info'] ?? []);

        $country = $countryCode;
        return view('houses.home', compact('type', 'country', 'totalHouses'));
    }

    public function index(Request $request)
    {
        return $this->renderResults($request, null, null);
    }

    public function landing(Request $request, $country = null, $slug = null)
    {
        $type = 'casas';
        $countryCode = $this->resolveCountryCode($request, $country);

        $landing = $this->manticore->getHouseLandingBySlug($countryCode, (string) $slug);
        $searchQuery = trim((string) ($landing['title'] ?? ''));

        $slugData = [
            'original' => $slug,
            'title' => $searchQuery !== '' ? $searchQuery : ucwords(str_replace('-', ' ', (string) $slug)),
        ];

        return $this->renderResults($request, $slugData, $type, $countryCode);
    }

    public function show($country = null, $id = null)
    {
        $request = request();
        $countryCode = $this->resolveCountryCode($request, $country);
        $searchIndex = $this->getHousesIndexName($countryCode);
        $houseId = (int) $id;

        if ($houseId <= 0) {
            abort(404);
        }

        $house = $this->manticore->getHouseById($searchIndex, $houseId);

        if (!$house || empty($house['url'])) {
            abort(404);
        }

        $targetUrl = (string) $house['url'];
        $source = (string) ($house['house_operation_name'] ?? 'fuente externa');

        $country = $countryCode;
        return response()
            ->view('houses.show', compact('targetUrl', 'source', 'country'))
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function renderResults(Request $request, ?array $slugData = null, ?string $type = null, ?string $countryCode = null)
    {
        $countryCode = $countryCode ?: $this->resolveCountryCode($request);
        $searchIndex = $this->getHousesIndexName($countryCode);

        $options = [
            'page' => $request->get('page', 1),
            'q' => $slugData ? ($slugData['title'] ?? '') : $request->get('q', ''),
            'operation' => $request->get('operation', ''),
            'type' => $request->get('type', ''),
            'state' => $request->get('location', ''),
            'city' => $request->get('city', ''),
            'min_price' => $request->get('min_price', ''),
            'max_price' => $request->get('max_price', ''),
            'rooms' => $request->get('rooms', ''),
            'bath' => $request->get('bath', ''),
            'min_size' => $request->get('min_size', ''),
            'max_size' => $request->get('max_size', ''),
            'order' => $this->normalizeOrder($request),
        ];

        $results = $this->safeHouseSearch($searchIndex, $options);
        $total = $this->extractTotalFromMeta($results['info'] ?? []);

        $maxPage = $total > 0 ? (int) ceil($total / ManticoreSearchService::RESULTS_PER_PAGE) : 1;
        if ((int) $options['page'] > $maxPage) {
            $query = $request->query();
            $query['page'] = $maxPage;
            return redirect()->to($request->path() . '?' . http_build_query($query));
        }

        $housesCollection = collect($results['items'] ?? [])->map(function ($item) {
            return (object) [
                'id' => $item['id'],
                'title' => $item['title'] ?? '',
                'operation' => $item['house_operation_name'] ?? '',
                'type' => $item['house_type_name'] ?? '',
                'price' => $item['price'] ?? 0,
                'rooms' => $item['rooms'] ?? 0,
                'bath' => $item['bath'] ?? 0,
                'size' => $item['size'] ?? 0,
                'location' => $item['state_name'] ?? '',
                'city' => $item['city_name'] ?? '',
                'image_url' => $item['image'] ?? null,
                'url' => $item['url'] ?? '#',
                'slug' => $item['slug'] ?? '',
            ];
        });

        $houses = new LengthAwarePaginator(
            $housesCollection,
            $total,
            ManticoreSearchService::RESULTS_PER_PAGE,
            (int) $options['page'],
            ['path' => $slugData ? $request->path() : '/search', 'query' => $request->query()]
        );

        $operations = collect($results['operation'] ?? [])
            ->filter(fn ($item) => !empty($item['name']))
            ->map(fn ($item) => ['id' => $item['id_house_operation'], 'name' => $item['name'], 'total' => $item['total']]);

        $types = collect($results['type'] ?? [])
            ->filter(fn ($item) => !empty($item['name']))
            ->map(fn ($item) => ['id' => $item['id_house_type'], 'name' => $item['name'], 'total' => $item['total']]);

        $locations = collect($results['state'] ?? [])
            ->filter(fn ($item) => !empty($item['name']))
            ->map(fn ($item) => ['id' => $item['id_state'], 'name' => $item['name'], 'total' => $item['total']]);

        $cities = collect($results['city'] ?? [])
            ->filter(fn ($item) => !empty($item['name']))
            ->map(fn ($item) => ['id' => $item['id_city'], 'name' => $item['name'], 'total' => $item['total']]);

        $searchQuery = $options['q'];
        $country = $countryCode;

        return view('houses.index', compact(
            'houses',
            'operations',
            'types',
            'locations',
            'cities',
            'searchQuery',
            'slugData',
            'type',
            'country'
        ));
    }

    private function extractTotalFromMeta(array $metaRows): int
    {
        foreach ($metaRows as $meta) {
            if (($meta['Variable_name'] ?? null) === 'total_found') {
                return (int) $meta['Value'];
            }
        }

        return 0;
    }

    private function resolveCountryFromHost(Request $request): ?string
    {
        $host = $request->getHost();
        if (preg_match('/^casas\.roodos\.([^.]+)$/', $host, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function resolveCountryCode(Request $request, ?string $country = null): string
    {
        $countryCode = strtolower((string) ($country ?: $this->resolveCountryFromHost($request) ?: 'lab'));

        if (!preg_match('/^[a-z]{2,5}$/', $countryCode)) {
            return 'lab';
        }

        return $countryCode;
    }

    private function getHousesIndexName(string $countryCode): string
    {
        return 'house_' . strtolower($countryCode);
    }

    private function safeHouseSearch(string $searchIndex, array $options): array
    {
        try {
            return $this->manticore->houseSearch($searchIndex, $options);
        } catch (Exception $e) {
            Log::warning('[HOUSES] Falling back to empty search result set', [
                'search_index' => $searchIndex,
                'query' => $options['q'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return [
                'items' => [],
                'info' => [],
                'operation' => [],
                'type' => [],
                'state' => [],
                'city' => [],
            ];
        }
    }
}
