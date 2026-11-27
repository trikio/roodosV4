<?php

namespace App\Http\Controllers;

use App\Services\ManticoreSearchService;
use Exception;
use Illuminate\Http\Request;
use App\Support\CappedPaginator;
use App\Support\HousePriceStats;
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
            abort(410); // dado de baja: Google lo saca antes que con un 404
        }

        $operationId = (int) ($house['id_house_operation'] ?? 0);
        $typeId = (int) ($house['id_house_type'] ?? 0);
        $price = (int) ($house['price'] ?? 0);

        // Compared with its city; a city with few ads of its kind says little, so then its region.
        $area = null;
        $stats = null;
        $places = [
            ['id_city', (int) ($house['id_city'] ?? 0), (string) ($house['city_name'] ?? ''), 'city'],
            ['id_state', (int) ($house['id_state'] ?? 0), (string) ($house['state_name'] ?? ''), 'location'],
        ];
        foreach ($places as [$field, $placeId, $placeName, $param]) {
            if ($placeId <= 0 || $placeName === '') {
                continue;
            }
            $stats = HousePriceStats::from($this->manticore->housePriceSample($searchIndex, $operationId, $typeId, $field, $placeId));
            if ($stats) {
                $area = ['field' => $field, 'id' => $placeId, 'name' => $placeName, 'param' => $param];
                break;
            }
        }

        // Junk prices ($1) and the same development posted over and over are left out.
        $similar = [];
        if ($area) {
            $rows = $this->manticore->similarHouses(
                $searchIndex, $houseId, $operationId, $typeId, $area['field'], $area['id'],
                $price > 0 ? $price : $stats['price'],
                intdiv($stats['price'], 10), $stats['price'] * 10
            );
            $seen = [self::dedupeKey($house) => true];
            foreach ($rows as $row) {
                $key = self::dedupeKey($row);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $similar[] = $this->houseSummary($row);
                if (count($similar) === 6) {
                    break;
                }
            }
        }

        $summary = $this->houseSummary($house);
        $verdict = null;
        if ($stats && $price > 0) {
            $verdict = $stats['per_m2'] && HousePriceStats::validSize($summary->size)
                ? ['per_m2', HousePriceStats::verdict($price / $summary->size, $stats['per_m2']['median'])]
                : ['price', HousePriceStats::verdict($price, $stats['price'])];
            if ($verdict[1] === null) {
                $verdict = null;
            }
        }

        $searchUrl = $area
            ? url('/search') . '?' . http_build_query(['operation' => $operationId, 'type' => $typeId, $area['param'] => $area['id']])
            : null;

        return response()
            ->view('houses.show', [
                'house' => $summary,
                'adId' => $houseId,
                'country' => $countryCode,
                'source' => self::sourceName((string) $house['url']),
                'targetUrl' => (string) $house['url'],
                'stats' => $stats,
                'area' => $area,
                'verdict' => $verdict,
                'similar' => $similar,
                'searchUrl' => $searchUrl,
            ])
            // Listing data copied from the sources and short-lived: kept out of the index, but its
            // links are followed.
            ->header('X-Robots-Tag', 'noindex, follow');
    }

    private function houseSummary(array $item): object
    {
        return (object) [
            'id' => (int) $item['id'],
            'title' => (string) ($item['title'] ?? ''),
            'operation' => (string) ($item['house_operation_name'] ?? ''),
            'type' => (string) ($item['house_type_name'] ?? ''),
            'price' => (int) ($item['price'] ?? 0),
            'rooms' => (int) ($item['rooms'] ?? 0),
            'bath' => (int) ($item['bath'] ?? 0),
            'size' => (int) ($item['size'] ?? 0),
            'location' => (string) ($item['state_name'] ?? ''),
            'city' => (string) ($item['city_name'] ?? ''),
            'details' => trim((string) ($item['details'] ?? '')),
            'image_url' => ($item['image'] ?? '') ?: null,
        ];
    }

    /** Same price and size, or same title: the same property (or development) posted again. */
    private static function dedupeKey(array $item): string
    {
        $title = preg_replace('/[^a-z0-9]+/', '', strtolower(\Illuminate\Support\Str::ascii((string) ($item['title'] ?? ''))));
        $price = (int) ($item['price'] ?? 0);
        $size = (int) ($item['size'] ?? 0);

        return $size > 0 ? "{$price}|{$size}" : "{$price}|{$title}";
    }

    /** "inmuebles.mercadolibre.com.mx" -> "mercadolibre.com.mx", "www.doomos.cl" -> "doomos.cl". */
    private static function sourceName(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^(www|inmuebles|casa|departamento|terreno|local|oficina)\./', '', $host);

        return $host !== '' ? $host : 'fuente externa';
    }

    private function renderResults(Request $request, ?array $slugData = null, ?string $type = null, ?string $countryCode = null)
    {
        $countryCode = $countryCode ?: $this->resolveCountryCode($request);
        $searchIndex = $this->getHousesIndexName($countryCode);

        $options = [
            'page' => max(1, (int) $request->get('page', 1)),
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

        abort_if(CappedPaginator::beyondCap($options['page']), 410);

        $results = $this->safeHouseSearch($searchIndex, $options);
        $total = $this->extractTotalFromMeta($results['info'] ?? []);

        $maxPage = min(CappedPaginator::MAX_PAGE, $total > 0 ? (int) ceil($total / ManticoreSearchService::RESULTS_PER_PAGE) : 1);
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

        $houses = new CappedPaginator(
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
