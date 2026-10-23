<?php

namespace App\Console\Commands;

use App\Services\ManticoreSearchService;
use App\Support\LandingClassifier;
use App\Support\LandingVocabulary;
use App\Support\Sitemap;
use Illuminate\Console\Command;

/**
 * Whitelist cleanup of the legacy car landings. A landing stays only when it is a real search
 * (make, model, place and/or year, in the country's vocabulary) with enough ads; its duplicates
 * and the landings that only narrow it down 301 to it; everything else is deactivated (410).
 */
class CleanLandings extends Command
{
    protected $signature = 'landings:clean
                            {country : Código de país (pe, cl, ec, mx, lab) o "all" para todos menos lab}
                            {--min-ads=5 : Avisos mínimos para que una landing se quede}
                            {--output= : Carpeta del SQL y CSV (por defecto storage/app/landings)}
                            {--memory=2G : Límite de memoria de PHP (México, con ~2 millones de landings, usa ~4G)}';

    protected $description = 'Genera un SQL para revisar que deja solo las landings de búsquedas reales (lista blanca). No modifica la base.';

    private const SQL_CHUNK = 1000;

    private const SITEMAP_SIZE = 50000;

    private ManticoreSearchService $manticore;

    /**
     * Each landing is kept as a packed list (millions of them in México): slug, group key,
     * junk reason or null, whether it is a real search, whether its ids are unconfirmed.
     */
    private const SLUG = 0;
    private const KEY = 1;
    private const JUNK = 2;
    private const REAL = 3;
    private const BAD_IDS = 4;

    /** @var array<string, array{0: string, 1: bool}> raw group key => [normalized key, real search] */
    private array $groups = [];

    /** @var array<string, ?string> group key => redirect target, shared by every landing of the group */
    private array $parentTargets = [];

    public function handle(ManticoreSearchService $manticore): int
    {
        $this->manticore = $manticore;
        $argument = strtolower((string) $this->argument('country'));
        $countries = $argument === 'all'
            ? array_values(array_diff(array_map('strtolower', array_keys(config('countries.names'))), ['lab']))
            : [$argument];

        foreach ($countries as $country) {
            if (!preg_match('/^[a-z]{2,5}$/', $country)) {
                $this->error("Código de país inválido: {$country}");

                return self::FAILURE;
            }
        }

        ini_set('memory_limit', (string) $this->option('memory'));

        foreach ($countries as $country) {
            $this->cleanCountry($country);
        }

        return self::SUCCESS;
    }

    private function cleanCountry(string $country): void
    {
        $this->info("[{$country}] Leyendo vocabulario y landings...");
        $vocabulary = LandingVocabulary::forCountry($this->manticore, $country);
        $this->groups = [];
        $this->parentTargets = [];
        [$landings, $redirected] = $this->loadLandings($country, $vocabulary);

        $this->info("[{$country}] Contando avisos por grupo (" . count($landings) . ' landings)...');
        $keepers = $this->pickKeepers($country, $landings);
        $actions = $this->planActions($landings, $keepers, $vocabulary);
        $actions['rewrite'] = $this->rewriteHubRedirects($redirected, $landings, $actions);

        $directory = $this->option('output') ?: storage_path('app/landings');
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $basename = $directory . '/landings_clean_' . $country . '_' . date('Ymd_His');

        $summary = $this->summary($country, count($landings), count($redirected), $actions);
        file_put_contents($basename . '.sql', $this->buildSql($country, $summary, $actions));
        $this->writeCsv($basename . '.csv', $country, $landings, $actions);
        $sitemaps = $this->writeRemovedSitemaps($country, $landings, $actions);

        $this->line($summary);
        $this->info("SQL: {$basename}.sql");
        $this->info("Detalle por landing: {$basename}.csv");
        $this->info("Sitemaps de URLs eliminadas: {$sitemaps} archivo(s) en " . Sitemap::directory($country));
    }

    /**
     * @return array{0: array<int, array>, 1: array<int, array{slug: string, redirect: string}>}
     *         Landings without redirect keyed by id, and those that already redirect.
     */
    private function loadLandings(string $country, LandingVocabulary $vocabulary): array
    {
        $landings = [];
        $redirected = [];

        foreach ($this->manticore->iterateCarLandings($country) as $row) {
            $id = (int) $row['id'];
            $slug = (string) $row['slug'];
            $title = (string) $row['title'];

            if (trim((string) $row['redirect']) !== '') {
                $redirected[$id] = ['slug' => $slug, 'redirect' => trim((string) $row['redirect'])];
                continue;
            }

            $breadcrumb = LandingClassifier::breadcrumbSlugs($row['content']);
            $confirmed = LandingClassifier::confirmedNames($slug, $breadcrumb['make'], $breadcrumb['model']);

            [$key, $real] = $this->group(LandingClassifier::groupKey($title), $vocabulary);
            $landings[$id] = [
                self::SLUG => $slug,
                self::KEY => $key,
                self::JUNK => LandingClassifier::junkReason($title),
                self::REAL => $real,
                self::BAD_IDS => (int) $row['id_car_make'] > 0 && !$confirmed['make'] && !$confirmed['model'],
            ];
        }

        return [$landings, $redirected];
    }

    /**
     * The landing that stays for each real-search group with enough ads.
     *
     * @return array<string, int> group key => landing id
     */
    private function pickKeepers(string $country, array $landings): array
    {
        $mains = [];
        foreach ($landings as $id => $landing) {
            // Country-wide groups ("autos-usados-peru", "peru-motos") name nothing and always
            // have ads: they compete for one main page too.
            $countryWide = str_ends_with($landing[self::KEY], '|');
            if ($landing[self::JUNK] !== null || (!$landing[self::REAL] && !$countryWide)) {
                continue;
            }

            $current = $mains[$landing[self::KEY]] ?? null;
            if ($current === null || $this->rank($id, $landing) < $this->rank($current, $landings[$current])) {
                $mains[$landing[self::KEY]] = $id;
            }
        }

        $minAds = (int) $this->option('min-ads');
        $keepers = [];
        foreach ($mains as $key => $id) {
            $terms = $this->keyParts($key)['terms'];
            if ($terms === []) {
                $keepers[$key] = $id;
                continue;
            }
            if ($this->manticore->countCars('car_' . $country, ['q' => implode(' ', $terms)], true) >= $minAds) {
                $keepers[$key] = $id;
            }
        }

        return $keepers;
    }

    /**
     * Normalized group key of a title's raw key, and whether it is a real search. Real searches
     * get the make a model implies, so "aveo-ecuador" groups with "autos-chevrolet-aveo-usados".
     *
     * @return array{0: string, 1: bool}
     */
    private function group(string $rawKey, LandingVocabulary $vocabulary): array
    {
        if (isset($this->groups[$rawKey])) {
            return $this->groups[$rawKey];
        }

        ['prefix' => $prefix, 'terms' => $terms] = $this->keyParts($rawKey);
        $parts = $vocabulary->split($terms);
        $real = $terms !== [] && $parts['unknown'] === []
            && ($parts['make'] !== [] || $parts['implied_make'] !== [] || $parts['place'] !== []);

        $key = $rawKey;
        if ($real && $parts['implied_make'] !== []) {
            $normalized = array_values(array_unique(array_merge($terms, $parts['implied_make'])));
            sort($normalized);
            $key = $prefix . implode(' ', $normalized);
        }

        return $this->groups[$rawKey] = $this->groups[$key] = [$key, $real];
    }

    /**
     * Lower is better: starts with the plural type ("autos-", not "auto-" or a make), names the
     * condition in plural anywhere ("autos-toyota-usados-lima" keeps the city last), then fewer
     * filler words ("marca", "version"...), no repeated words ("honda-honda"), shorter, older.
     */
    private function rank(int $id, array $landing): array
    {
        $tokens = LandingClassifier::slugTokens($landing[self::SLUG]);
        $first = $tokens[0] ?? '';
        $typeRank = match (true) {
            (LandingClassifier::TYPE_WORDS[$first] ?? null) === $first => 0,
            isset(LandingClassifier::TYPE_WORDS[$first]) => 1,
            default => 2,
        };

        return [
            $typeRank,
            array_intersect($tokens, ['usados', 'usadas', 'nuevos', 'nuevas', 'seminuevos', 'seminuevas']) !== [] ? 0 : 1,
            count(array_intersect($tokens, LandingClassifier::GENERIC_WORDS)),
            count($tokens) === count(array_unique($tokens)) ? 0 : 1,
            count($tokens),
            strlen($landing[self::SLUG]),
            $id,
        ];
    }

    /**
     * @return array{redirect: array<int, string>, deactivate: array<int, string>, fix_ids: int[]}
     *         redirect: id => target path; deactivate: id => reason
     */
    private function planActions(array $landings, array $keepers, LandingVocabulary $vocabulary): array
    {
        $actions = ['redirect' => [], 'deactivate' => [], 'fix_ids' => []];
        $keeperPaths = [];

        foreach ($landings as $id => $landing) {
            $key = $landing[self::KEY];

            if (isset($keepers[$key]) && $landing[self::JUNK] === null) {
                if ($keepers[$key] === $id) {
                    if ($landing[self::BAD_IDS]) {
                        $actions['fix_ids'][] = $id;
                    }
                } else {
                    $actions['redirect'][$id] = $keeperPaths[$key] ??= '/' . $landings[$keepers[$key]][self::SLUG];
                }
                continue;
            }

            // array_key_exists, not ??=: most groups have no parent, and null must be cached too.
            if (!array_key_exists($key, $this->parentTargets)) {
                $this->parentTargets[$key] = $this->parentTarget($key, $keepers, $landings, $vocabulary);
            }
            $parent = $this->parentTargets[$key];
            if ($parent !== null) {
                $actions['redirect'][$id] = $parent;
            } else {
                $actions['deactivate'][$id] = $landing[self::JUNK] ?? ($landing[self::REAL] ? 'pocos_avisos' : 'texto_libre');
            }
        }

        return $actions;
    }

    /**
     * Closest kept landing above this one: its known words (make/model/place/year) without the
     * year, then without the place, then the make alone. Null when none of them stays.
     */
    private function parentTarget(string $key, array $keepers, array $landings, LandingVocabulary $vocabulary): ?string
    {
        ['prefix' => $prefix, 'terms' => $terms] = $this->keyParts($key);
        $parts = $vocabulary->split($terms);
        $parts['make'] = array_values(array_unique(array_merge($parts['make'], $parts['implied_make'])));

        $candidates = [
            array_merge($parts['make'], $parts['model'], $parts['place'], $parts['year']),
            array_merge($parts['make'], $parts['model'], $parts['place']),
            array_merge($parts['make'], $parts['model']),
            $parts['make'] ?: $parts['model'],
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === []) {
                continue;
            }
            sort($candidate);
            $candidateKey = $prefix . implode(' ', $candidate);
            if ($candidateKey !== $key && isset($keepers[$candidateKey])) {
                return '/' . $landings[$keepers[$candidateKey]][self::SLUG];
            }
        }

        return null;
    }

    /**
     * Existing redirects to "/marca/x" or "/modelo/x" now go straight to the kept
     * "autos-x-usados" landing, since those pages 301 there.
     *
     * @return array<int, string> id => new target
     */
    private function rewriteHubRedirects(array $redirected, array $landings, array $actions): array
    {
        $kept = [];
        foreach ($landings as $id => $landing) {
            if (!isset($actions['redirect'][$id]) && !isset($actions['deactivate'][$id])) {
                $kept[$landing[self::SLUG]] = true;
            }
        }

        $rewrite = [];
        foreach ($redirected as $id => $row) {
            if (preg_match('#^/(marca|modelo)/([a-z0-9-]+)$#', $row['redirect'], $matches)
                && isset($kept['autos-' . $matches[2] . '-usados'])) {
                $rewrite[$id] = '/autos-' . $matches[2] . '-usados';
            }
        }

        return $rewrite;
    }

    /**
     * @return array{prefix: string, terms: string[]} "autos|usados|" and the group's words.
     */
    private function keyParts(string $key): array
    {
        $position = strrpos($key, '|');
        $terms = substr($key, $position + 1);

        return ['prefix' => substr($key, 0, $position + 1), 'terms' => $terms === '' ? [] : explode(' ', $terms)];
    }

    private function summary(string $country, int $active, int $alreadyRedirected, array $actions): string
    {
        $redirects = $actions['redirect'];
        $kept = $active - count($redirects) - count($actions['deactivate']);
        $reasons = array_count_values($actions['deactivate']);
        arsort($reasons);
        $reasonText = implode(', ', array_map(fn ($reason, $count) => "{$reason}: {$count}", array_keys($reasons), $reasons));

        return implode("\n", [
            "Limpieza de landings '{$country}' (lista blanca, mínimo " . $this->option('min-ads') . ' avisos) - ' . date('Y-m-d H:i:s'),
            '  Landings sin redirect:            ' . $active,
            '  Se quedan:                        ' . $kept,
            '  Redirigidas (301):                ' . count($redirects) . ' (hacia ' . count(array_unique($redirects)) . ' landings)',
            '  Eliminadas (activo = 0 -> 410):   ' . count($actions['deactivate']) . ($reasonText !== '' ? " ({$reasonText})" : ''),
            '  Con ids corregidos a 0:           ' . count($actions['fix_ids']),
            "  Ya tenían redirect:               {$alreadyRedirected} (" . count($actions['rewrite']) . ' reapuntadas a su landing autos-x-usados)',
        ]);
    }

    private function buildSql(string $country, string $summary, array $actions): string
    {
        $q = fn (string $value) => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        $backup = 'car_landings_backup_' . $country . '_' . date('Ymd_His');
        $scope = 'country_id = ' . $q($country);

        $sql = ['-- ' . str_replace("\n", "\n-- ", $summary), ''];
        $sql[] = '-- 1. Respaldo de las landings del país. Para revertir:';
        $sql[] = "--    UPDATE car_landings c JOIN {$backup} b ON b.id = c.id SET c.redirect = b.redirect, c.activo = b.activo, c.id_car_make = b.id_car_make, c.id_car_model = b.id_car_model;";
        $sql[] = "CREATE TABLE {$backup} AS SELECT * FROM car_landings WHERE {$scope};";
        $sql[] = '';
        $sql[] = 'START TRANSACTION;';

        $byTarget = function (array $targets, string $condition) use ($q, $scope, &$sql) {
            $grouped = [];
            foreach ($targets as $id => $target) {
                $grouped[$target][] = $id;
            }
            foreach ($grouped as $target => $ids) {
                foreach (array_chunk($ids, self::SQL_CHUNK) as $chunk) {
                    $sql[] = 'UPDATE car_landings SET redirect = ' . $q($target) . " WHERE {$scope}{$condition} AND id IN (" . implode(',', $chunk) . ');';
                }
            }
        };

        // "redirect = ''" keeps the script idempotent and never overwrites a redirect set by hand.
        $sql[] = '';
        $sql[] = '-- 2. Duplicadas y landings que solo acotan a otra: 301 hacia la landing que se queda.';
        $byTarget($actions['redirect'], " AND redirect = ''");

        $sql[] = '';
        $sql[] = '-- 3. Sin búsqueda real ni landing superior (texto de avisos, basura, pocos avisos): se desactivan y pasan a 410.';
        foreach (array_chunk(array_keys($actions['deactivate']), self::SQL_CHUNK) as $chunk) {
            $sql[] = "UPDATE car_landings SET activo = 0 WHERE {$scope} AND redirect = '' AND id IN (" . implode(',', $chunk) . ');';
        }

        $sql[] = '';
        $sql[] = '-- 4. Ids de marca/modelo que el slug no confirma (p. ej. "$100 telefono..." marcado como Audi 100).';
        foreach (array_chunk($actions['fix_ids'], self::SQL_CHUNK) as $chunk) {
            $sql[] = "UPDATE car_landings SET id_car_make = 0, id_car_model = 0 WHERE {$scope} AND id IN (" . implode(',', $chunk) . ');';
        }

        $sql[] = '';
        $sql[] = '-- 5. Redirects existentes a /marca/x o /modelo/x: directo a la landing autos-x-usados (evita doble salto).';
        $byTarget($actions['rewrite'], " AND redirect LIKE '/m%'");

        $sql[] = '';
        $sql[] = 'COMMIT;';
        $sql[] = '';
        $sql[] = "-- Después: sudo indexer --rotate car_landings_{$country} && php artisan sitemaps:generate {$country}";

        return implode("\n", $sql) . "\n";
    }

    /**
     * Titles are not kept in memory, so they are read again from the index while writing.
     */
    private function writeCsv(string $path, string $country, array $landings, array $actions): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, ['id', 'slug', 'titulo', 'accion', 'destino', 'motivo']);

        $fixIds = array_flip($actions['fix_ids']);
        foreach ($this->manticore->iterateCarLandings($country) as $row) {
            $id = (int) $row['id'];
            if (!isset($landings[$id])) {
                continue;
            }
            $landing = $landings[$id];

            if (isset($actions['redirect'][$id])) {
                $result = ['redirigir', $actions['redirect'][$id], $landing[self::JUNK] ?? ''];
            } elseif (isset($actions['deactivate'][$id])) {
                $result = ['eliminar', '', $actions['deactivate'][$id]];
            } elseif (isset($fixIds[$id])) {
                $result = ['mantener', '', 'ids corregidos'];
            } else {
                $result = ['mantener', '', ''];
            }

            fputcsv($handle, array_merge([$id, $landing[self::SLUG], (string) $row['title']], $result));
        }

        fclose($handle);
    }

    /**
     * Sitemaps listing every URL that now redirects or returns 410, so Google recrawls them soon.
     * They are meant to be temporary: delete them once Search Console shows the URLs dropped.
     */
    private function writeRemovedSitemaps(string $country, array $landings, array $actions): int
    {
        $directory = Sitemap::directory($country);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        array_map('unlink', glob($directory . '/removed-*.xml') ?: []);

        $ids = array_merge(array_keys($actions['redirect']), array_keys($actions['deactivate']));
        $files = 0;
        foreach (array_chunk($ids, self::SITEMAP_SIZE) as $chunk) {
            $urls = array_map(fn ($id) => Sitemap::landingUrl($country, $landings[$id][self::SLUG]), $chunk);
            file_put_contents($directory . '/removed-' . (++$files) . '.xml', Sitemap::urlset($urls));
        }

        return $files;
    }
}
