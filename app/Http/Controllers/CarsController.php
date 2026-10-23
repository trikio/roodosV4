<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use App\Services\ManticoreSearchService;
use App\Support\LandingClassifier;

class CarsController extends Controller
{
    protected $manticore;

    /** Cars a landing needs to be its own canonical; below this it shows and points to its parent. */
    private const LANDING_MIN_RESULTS = 10;

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

        // Filters default to "no filter" in the service, so an empty search returns the total count.
        $results = $this->manticore->carSearch($searchIndex, ['page' => 1]);
        $totalCars = $this->extractTotal($results['info'] ?? []);

        $homeLinks = $this->homeLinks($countryCode);

        $country = $countryCode;
        return view('cars.home', compact('type', 'country', 'totalCars', 'homeLinks'));
    }

    /**
     * Home links pointing straight at the final URL: the "autos-x-usados", "autos-usados-x" or "autos-x" landing
     * when the country has it, otherwise the make/model page. Cities without a landing are left out.
     *
     * @return array{models: array, brands: array, cities: array}
     */
    private function homeLinks(string $countryCode): array
    {
        $models = ['Ford Ranger', 'Honda Accord', 'BMW 320', 'Toyota Corolla', 'Chevrolet Aveo', 'Mazda 3', 'Nissan Sentra', 'Hyundai Tucson'];
        $brands = ['Alfa Romeo', 'Audi', 'BMW', 'Chevrolet', 'Ford', 'Honda', 'Hyundai', 'Kia', 'Mazda', 'Mercedes-Benz', 'Nissan', 'Toyota', 'Volkswagen', 'Volvo', 'Mitsubishi', 'Suzuki', 'Jeep', 'Subaru'];
        $cities = config('countries.autos_home.locations.' . strtoupper($countryCode), []);

        // Make/model landings read "autos-x-usados"; city ones "autos-usados-x" (city last).
        $candidates = fn (string $label) => ['autos-' . Str::slug($label) . '-usados', 'autos-usados-' . Str::slug($label), 'autos-' . Str::slug($label)];
        $allCandidates = array_merge(...array_map($candidates, array_merge($models, $brands, $cities)));
        $existing = array_flip($this->manticore->existingCarLandingSlugs($countryCode, $allCandidates));

        $link = function (string $label, ?string $fallbackPrefix) use ($candidates, $existing) {
            foreach ($candidates($label) as $slug) {
                if (isset($existing[$slug])) {
                    return ['label' => $label, 'url' => url('/' . $slug)];
                }
            }

            return $fallbackPrefix !== null ? ['label' => $label, 'url' => url($fallbackPrefix . Str::slug($label))] : null;
        };

        return [
            'models' => array_map(fn ($label) => $link($label, '/modelo/'), $models),
            'brands' => array_map(fn ($label) => $link($label, '/marca/'), $brands),
            'cities' => array_values(array_filter(array_map(fn ($label) => $link($label, null), $cities))),
        ];
    }

    public function index(Request $request)
    {
        return $this->renderCarsIndex($request);
    }

    public function brand(Request $request, $country = null, string $slug = '')
    {
        if ($hub = $this->hubLandingRedirect($request, $country, $slug)) {
            return $hub;
        }

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
        if ($hub = $this->hubLandingRedirect($request, $country, $slug)) {
            return $hub;
        }

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
                'breadcrumb' => [
                    [
                        'label' => 'Autos',
                        'url' => url('/'),
                    ],
                    [
                        'label' => $model['make_name'] ?? $this->formatBreadcrumbLabel((string) ($model['slug_make'] ?? '')),
                        'url' => !empty($model['slug_make']) ? url('/marca/' . $model['slug_make']) : null,
                    ],
                    [
                        'label' => $model['make_model'] ?? $model['model_name'] ?? ucfirst(str_replace('-', ' ', $slug)),
                        'url' => null,
                    ],
                ],
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

        // Legacy landings removed by landings:clean: 410 tells Google they are gone for good.
        if (!$landing || empty($landing['title'])) {
            abort(410);
        }

        $redirectPath = trim((string) ($landing['redirect'] ?? ''));
        if ($redirectPath !== '') {
            return redirect()->to(url($redirectPath), 301);
        }

        $searchQuery = trim((string) $landing['title']);
        $breadcrumbSlugs = LandingClassifier::breadcrumbSlugs($landing['content'] ?? null);
        // Parent levels are "autos-{make}[-{model}]-usados"; camionetas, motos or new-car landings
        // have no such parent and keep searching by title.
        $filters = LandingClassifier::isAutosUsados($searchQuery)
            ? $this->resolveLandingFilters($landing, $breadcrumbSlugs, (string) $slug)
            : [];
        $canonicalUrl = url('/' . ltrim((string) $slug, '/'));
        $widened = false;
        $resultsQuery = $searchQuery;

        if ($filters !== []) {
            $level = $this->resolveLandingLevel($countryCode, (string) $slug, $searchQuery, $filters, $breadcrumbSlugs);
            $filters = $level['filters'];
            $canonicalUrl = $level['canonical'] ?? $canonicalUrl;
            $widened = $level['widened'];
            $resultsQuery = trim($searchQuery . ' ' . $level['extra_terms']);
        }

        $slugData = [
            'original' => $slug,
            'title' => $landing['title'],
            'breadcrumb' => $this->buildLandingBreadcrumb($landing, $countryCode),
            'meta_description' => trim((string) ($landing['meta_description'] ?? '')),
            'canonical_url' => $canonicalUrl,
            'widened' => $widened,
        ];

        return $this->renderCarsIndex($request, $country, array_merge(
            ['q' => $resultsQuery],
            array_fill_keys(array_keys(ManticoreSearchService::LANDING_FILTER_ATTRIBUTES), ''),
            $filters
        ), compact('slugData', 'type', 'searchQuery'));
    }

    /**
     * /marca/x and /modelo/x list the same cars as the "autos-x-usados" landing, the URL that
     * ranks: when that landing exists they 301 to it instead of competing with it.
     */
    private function hubLandingRedirect(Request $request, ?string $country, string $slug)
    {
        $countryCode = $this->resolveCountryCode($request, $country);
        $hubSlug = 'autos-' . $slug . '-usados';
        $landing = $this->manticore->getLandingBySlug($countryCode, $hubSlug);

        if (empty($landing['title']) || trim((string) ($landing['redirect'] ?? '')) !== '') {
            return null;
        }

        $query = $request->getQueryString();

        return redirect()->to(url('/' . $hubSlug) . ($query ? '?' . $query : ''), 301);
    }

    /**
     * Make/model/state/city filters for a landing, from its ids when the slug confirms them
     * (see LandingClassifier::confirmedNames). An empty result means a text-only landing,
     * searched by its title as before.
     */
    private function resolveLandingFilters(array $landing, array $breadcrumbSlugs, string $slug): array
    {
        ['make' => $makeConfirmed, 'model' => $modelConfirmed] = LandingClassifier::confirmedNames($slug, $breadcrumbSlugs['make'], $breadcrumbSlugs['model']);

        if (!$makeConfirmed && !$modelConfirmed) {
            return [];
        }

        $filters = [];

        if ($modelConfirmed) {
            $modelId = (int) ($landing['id_car_model'] ?? 0);
            $makeId = (int) ($landing['id_car_make'] ?? 0);
            if ($modelId <= 0) {
                $model = $this->manticore->getCarModelBySlug($breadcrumbSlugs['model']);
                $modelId = (int) ($model['id'] ?? 0);
                $makeId = $makeId ?: (int) ($model['id_car_make'] ?? 0);
            }
            if ($modelId > 0) {
                $filters['model'] = $modelId;
            }
            if ($makeId > 0) {
                $filters['make'] = $makeId;
            }
        }

        if (empty($filters['make']) && $makeConfirmed) {
            $makeId = (int) ($landing['id_car_make'] ?? 0);
            if ($makeId <= 0) {
                $makeId = (int) ($this->manticore->getCarMakeBySlug($breadcrumbSlugs['make'])['id'] ?? 0);
            }
            if ($makeId > 0) {
                $filters['make'] = $makeId;
            }
        }

        // Without a make the landing has no parent to widen to; keep it as a text landing.
        if (empty($filters['make'])) {
            return [];
        }

        foreach (['state' => 'id_state', 'city' => 'id_city'] as $key => $attribute) {
            if ((int) ($landing[$attribute] ?? 0) > 0) {
                $filters[$key] = (int) $landing[$attribute];
            }
        }

        return $filters;
    }

    /**
     * Backlog rule: a landing with LANDING_MIN_RESULTS cars matching every specific term of its
     * title is its own canonical. Otherwise it shows its model results (or make results, if the
     * model also falls short) and the canonical points to that parent.
     *
     * @return array{filters: array, canonical: ?string, widened: bool, extra_terms: string}
     */
    private function resolveLandingLevel(string $countryCode, string $slug, string $title, array $filters, array $breadcrumbSlugs): array
    {
        $searchIndex = $this->getCarsIndexName($countryCode);
        $specificTerms = implode(' ', LandingClassifier::classify($title)['terms']);
        $parents = $this->landingParents($filters, $breadcrumbSlugs);

        // A landing whose title only names its make/model ("autos-honda") lists the same cars as
        // the main landing of that level, so it points there instead of competing with it.
        $nameTokens = array_merge(LandingClassifier::slugTokens($breadcrumbSlugs['make']), LandingClassifier::slugTokens($breadcrumbSlugs['model']));
        $termTokens = $specificTerms === '' ? [] : explode(' ', $specificTerms);
        // A model level short of cars still widens to its make below, like any other landing.
        if (empty($filters['state']) && empty($filters['city']) && array_diff($termTokens, $nameTokens) === []) {
            $level = $parents[0]['filters'] == $filters ? $parents[0] : $parents[count($parents) - 1];
            $isMakeLevel = empty($level['filters']['model']);

            if ($isMakeLevel || $this->manticore->countCars($searchIndex, $level['filters']) >= self::LANDING_MIN_RESULTS) {
                return ['filters' => $filters, 'canonical' => $this->landingParentCanonical($countryCode, $slug, $level), 'widened' => false, 'extra_terms' => ''];
            }
        }

        if ($this->manticore->countCars($searchIndex, ['q' => $specificTerms] + $filters, true) >= self::LANDING_MIN_RESULTS) {
            return ['filters' => $filters, 'canonical' => null, 'widened' => false, 'extra_terms' => ''];
        }

        foreach ($parents as $index => $parent) {
            $isLast = $index === count($parents) - 1;
            if (!$isLast && $this->manticore->countCars($searchIndex, $parent['filters']) < self::LANDING_MIN_RESULTS) {
                continue;
            }

            $canonical = $this->landingParentCanonical($countryCode, $slug, $parent);

            return [
                'filters' => $parent['filters'],
                'canonical' => $canonical,
                // Pointing to itself means this landing already is the parent level: same cars.
                'widened' => $canonical !== null,
                // The results query needs at least one title word per car; the parent names make
                // sure every car of the parent level qualifies, even if the title lacks them.
                'extra_terms' => str_replace('-', ' ', $parent['slug']),
            ];
        }

        return ['filters' => $filters, 'canonical' => null, 'widened' => false, 'extra_terms' => ''];
    }

    /**
     * Parent levels of a filtered landing, most specific first: its model (if any), then its make.
     */
    private function landingParents(array $filters, array $breadcrumbSlugs): array
    {
        $parents = [];
        if (!empty($filters['model'])) {
            $parents[] = ['filters' => ['make' => $filters['make'], 'model' => $filters['model']], 'slug' => $breadcrumbSlugs['model'], 'page' => '/modelo/'];
        }
        $parents[] = ['filters' => ['make' => $filters['make']], 'slug' => $breadcrumbSlugs['make'], 'page' => '/marca/'];

        return $parents;
    }

    /**
     * Canonical URL of a parent level, or null when $slug already is that level's main landing.
     */
    private function landingParentCanonical(string $countryCode, string $slug, array $parent): ?string
    {
        $parentSlug = $parent['slug'] !== '' ? 'autos-' . $parent['slug'] . '-usados' : '';
        if ($parentSlug === $slug) {
            return null;
        }

        return $this->landingParentUrl($countryCode, $parentSlug, $parent['page'] . $parent['slug']);
    }

    /**
     * The "autos-{make}[-{model}]-usados" landing when it exists, otherwise the make/model page.
     */
    private function landingParentUrl(string $countryCode, string $parentSlug, string $fallbackPath): ?string
    {
        if ($parentSlug !== '') {
            $parent = $this->manticore->getLandingBySlug($countryCode, $parentSlug);
            if (!empty($parent['title']) && trim((string) ($parent['redirect'] ?? '')) === '') {
                return url('/' . $parentSlug);
            }
        }

        return str_ends_with($fallbackPath, '/') ? null : url($fallbackPath);
    }

    private function resolveCountryFromHost(Request $request): ?string
    {
        $host = $request->getHost();
        if (preg_match('/^autos\.roodos\.([^.]+)$/', $host, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function buildLandingBreadcrumb(array $landing, string $countryCode): array
    {
        $items = [[
            'label' => 'Autos',
            'url' => url('/'),
        ]];

        $breadcrumb = LandingClassifier::breadcrumbSlugs($landing['content'] ?? null);
        if ($breadcrumb['make'] === '' && $breadcrumb['model'] === '') {
            return $items;
        }

        $makeSlug = $breadcrumb['make'];
        if ($makeSlug !== '') {
            $items[] = [
                'label' => $this->formatBreadcrumbLabel($makeSlug),
                'url' => url('/marca/' . $makeSlug),
            ];
        }

        $modelSlug = $breadcrumb['model'];
        if ($modelSlug !== '') {
            $items[] = [
                'label' => $this->formatBreadcrumbLabel($modelSlug),
                'url' => url('/modelo/' . $modelSlug),
            ];
        }

        $title = trim((string) ($landing['title'] ?? ''));
        if ($title !== '') {
            $items[] = [
                'label' => $title,
                'url' => null,
            ];
        }

        return $items;
    }

    private function formatBreadcrumbLabel(string $slug): string
    {
        return (string) Str::of($slug)
            ->replace('-', ' ')
            ->squish()
            ->title();
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
            ['path' => url($request->path()), 'query' => $this->paginationQuery($request, $options, $viewData)]
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

    /**
     * Query string for page links. Landing, make and model pages merge their filters into the
     * request; repeating them in every link created endless URL variants, so those pages keep
     * only the order. Search pages keep the non-empty filters the visitor chose.
     */
    private function paginationQuery(Request $request, array $options, array $viewData): array
    {
        if (isset($viewData['slugData'])) {
            return array_filter(['order' => $options['order']], fn ($value) => $value !== '' && $value !== null);
        }

        return array_filter($request->except('page'), fn ($value) => $value !== '' && $value !== null);
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
