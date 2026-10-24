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

    /** Links from the home when the country has no curated list: per main city. */
    private const PER_CITY = 2;

    /** Scraped ad labels ("Marca Chevrolet Modelo Aveo Versión..."): such titles are not linked. */
    private const LABEL_WORDS = ['marca', 'modelo', 'version', 'condicion', 'item', 'tipo', 'motor', 'kilometros', 'recorrido', 'puertas'];

    public function __construct(private ManticoreSearchService $manticore)
    {
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
