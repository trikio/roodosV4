<?php

namespace App\Services;

use App\Support\LandingClassifier;
use Illuminate\Support\Str;

/**
 * Internal links between landings, make pages and model pages, so authority flows through the
 * landings and Google can reach the deep ones. Every link points to an active, non-redirecting
 * landing; junk titles and near-duplicates of the same search are skipped.
 */
class LandingLinks
{
    /** Links from a landing to related landings. */
    public const RELATED = 12;

    /** Links from a make or model page to its landings. */
    public const HUB = 30;

    /** Links from a car detail page, and how many of them may be of its city. */
    public const PER_CAR = 8;
    private const PER_CAR_CITY = 3;

    /** Vehicle types never linked from a car detail page. */
    private const NOT_CAR_TYPES = ['buses', 'motos', 'camiones'];

    /** Links from the home when the country has no curated list: per main city. */
    private const PER_CITY = 2;

    /** Scraped ad labels ("Marca Chevrolet Modelo Aveo Versión..."): such titles are not linked. */
    private const LABEL_WORDS = ['marca', 'modelo', 'version', 'condicion', 'item', 'tipo', 'motor', 'kilometros', 'recorrido', 'puertas'];

    /** Makes linked from the home and from the guides. */
    public const MAIN_MAKES = ['Alfa Romeo', 'Audi', 'BMW', 'Chevrolet', 'Ford', 'Honda', 'Hyundai', 'Kia', 'Mazda', 'Mercedes-Benz', 'Nissan', 'Toyota', 'Volkswagen', 'Volvo', 'Mitsubishi', 'Suzuki', 'Jeep', 'Subaru'];

    public function __construct(private ManticoreSearchService $manticore)
    {
    }

    /**
     * The "autos-x-usados", "autos-usados-x" or "autos-x" landing of each label when the country
     * has it, or null. Make/model landings read "autos-x-usados"; city ones "autos-usados-x".
     *
     * @return array<string, ?string> label => URL
     */
    public function landingUrls(string $countryCode, array $labels): array
    {
        $candidates = fn (string $label) => ['autos-' . Str::slug($label) . '-usados', 'autos-usados-' . Str::slug($label), 'autos-' . Str::slug($label)];
        $existing = array_flip($this->manticore->existingCarLandingSlugs($countryCode, array_merge(...array_map($candidates, $labels))));

        $urls = [];
        foreach ($labels as $label) {
            $urls[$label] = null;
            foreach ($candidates($label) as $slug) {
                if (isset($existing[$slug])) {
                    $urls[$label] = url('/' . $slug);
                    break;
                }
            }
        }

        return $urls;
    }

    /**
     * Landings sharing the most words with this one's title (same make, model, city...).
     *
     * @return array<int, array{title: string, url: string}>
     */
    public function related(string $countryCode, array $landing): array
    {
        $title = (string) ($landing['title'] ?? '');
        $terms = implode(' ', LandingClassifier::classify($title)['terms']) ?: $title;
        $rows = $this->manticore->similarCarLandings($countryCode, $terms, false, 60, array_filter([(int) ($landing['id'] ?? 0)]));

        return $this->pick($rows, self::RELATED, [LandingClassifier::groupKey($title)]);
    }

    /**
     * Landings whose title names the make (or "make model").
     *
     * @return array<int, array{title: string, url: string}>
     */
    public function forName(string $countryCode, string $name): array
    {
        $terms = implode(' ', LandingClassifier::words($name));

        return $this->pick($this->bestFirst($this->manticore->similarCarLandings($countryCode, $terms, true, 150)), self::HUB);
    }

    /**
     * Landings for a car detail page, most specific first: its model in its city, its model (no
     * city), then its make (no model, no city). Each level looks the landings up by the ids they are tagged with and, when
     * that is not enough (most landings have no model or city id), by the names in their title.
     *
     * @param array{make_id: int, model_id: int, city_id: int, make: string, model: string, city: string} $car
     * @return array<int, array{title: string, url: string}>
     */
    public function forCar(string $countryCode, array $car): array
    {
        $makeModel = trim($car['make'] . ' ' . $car['model']);
        $levels = [
            [['id_car_model' => $car['model_id'], 'id_city' => $car['city_id']], $car['model'] !== '' && $car['city'] !== '' ? $makeModel . ' ' . $car['city'] : '', self::PER_CAR_CITY, $car['model_id'] > 0 && $car['city_id'] > 0, true],
            [['id_car_model' => $car['model_id'], 'id_city' => 0], $car['model'] !== '' ? $makeModel : '', self::PER_CAR, $car['model_id'] > 0, true],
            [['id_car_make' => $car['make_id'], 'id_car_model' => 0, 'id_city' => 0], $car['make'], self::PER_CAR, $car['make_id'] > 0, false],
        ];

        $links = [];
        foreach ($levels as [$ids, $name, $limit, $hasIds, $withYears]) {
            $fits = fn ($rows) => array_filter($rows, fn ($row) => $this->fitsCar((string) $row['title'], $name, $withYears));
            $rows = $hasIds ? $fits($this->bestFirst($this->manticore->carLandingsByIds($countryCode, $ids, 150))) : [];
            if (count($this->pickRows($rows, $limit)) < $limit && trim($name) !== '') {
                // Most title matches name more than the level (other models, towns), hence the margin.
                $terms = implode(' ', LandingClassifier::words($name));
                $rows = array_merge($rows, $fits($this->bestFirst($this->manticore->similarCarLandings($countryCode, $terms, true, 500))));
            }

            foreach ($this->pick($rows, $limit) as $link) {
                $links[LandingClassifier::groupKey($link['title'])] ??= $link;
                if (count($links) === self::PER_CAR) {
                    return array_values($links);
                }
            }
        }

        return array_values($links);
    }

    /**
     * Whether a landing fits a car detail level: not about buses, motorbikes or trucks, and naming
     * nothing beyond the level's make, model and city, plus years on the model levels. Without
     * that, a rare make filled up with "Buses Alfa Romeo nuevos", "Alfa Romeo 1926" or another
     * town's landing.
     */
    private function fitsCar(string $title, string $levelName, bool $withYears): bool
    {
        $info = LandingClassifier::classify($title);
        if (in_array($info['type'], self::NOT_CAR_TYPES, true)) {
            return false;
        }

        $allowed = LandingClassifier::words($levelName);
        foreach ($info['terms'] as $term) {
            if (!in_array($term, $allowed, true) && !($withYears && preg_match('/^(19|20)\d{2}$/', $term))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Curated landings for the home (config/landing_links.php), or landings of the main cities.
     *
     * @return array<int, array{title: string, url: string}>
     */
    public function forHome(string $countryCode, array $cities): array
    {
        $curated = config('landing_links.home.' . strtoupper($countryCode), []);
        if ($curated !== []) {
            $titles = $this->manticore->carLandingTitles($countryCode, $curated);
            $links = [];
            foreach ($curated as $slug) {
                if (isset($titles[$slug])) {
                    $links[] = $this->link($slug, $titles[$slug]);
                }
            }

            return $links;
        }

        $rows = [];
        foreach ($cities as $city) {
            $cityRows = $this->bestFirst($this->manticore->similarCarLandings($countryCode, implode(' ', LandingClassifier::words($city)), true, 150));
            $rows = array_merge($rows, array_slice($this->pickRows($cityRows, self::PER_CITY), 0, self::PER_CITY));
        }

        return $this->pick($rows, self::HUB);
    }

    /**
     * @return array<int, array{title: string, url: string}>
     */
    private function pick(array $rows, int $limit, array $seenKeys = []): array
    {
        return array_map(
            fn ($row) => $this->link((string) $row['slug'], (string) $row['title']),
            $this->pickRows($rows, $limit, $seenKeys)
        );
    }

    /**
     * Rows without junk titles, one per search (group key), at most $limit.
     */
    private function pickRows(array $rows, int $limit, array $seenKeys = []): array
    {
        $seen = array_flip($seenKeys);
        $picked = [];

        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '' || LandingClassifier::junkReason($title) !== null
                || array_intersect(LandingClassifier::words($title), self::LABEL_WORDS) !== []) {
                continue;
            }

            $key = LandingClassifier::groupKey($title);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $picked[] = $row;
            if (count($picked) === $limit) {
                break;
            }
        }

        return $picked;
    }

    /**
     * Rows in the shapes that rank in Search Console first: titles starting with "venta" or the
     * vehicle type ("Autos ...", "Camionetas ...") that name the condition, then shorter ones.
     * Used where the index returns rows in id order (make, model and city lists).
     */
    private function bestFirst(array $rows): array
    {
        $scored = [];
        foreach (array_values($rows) as $position => $row) {
            $words = LandingClassifier::words((string) ($row['title'] ?? ''));
            $scored[] = [
                ($words[0] ?? '') === 'venta' || isset(LandingClassifier::TYPE_WORDS[$words[0] ?? '']) ? 0 : 1,
                array_intersect($words, array_keys(LandingClassifier::CONDITION_WORDS)) !== [] ? 0 : 1,
                count($words),
                $position,
                $row,
            ];
        }
        sort($scored);

        return array_column($scored, 4);
    }

    /**
     * @return array{title: string, url: string}
     */
    private function link(string $slug, string $title): array
    {
        return ['title' => Str::ucfirst(trim($title)), 'url' => url('/' . ltrim($slug, '/'))];
    }
}
