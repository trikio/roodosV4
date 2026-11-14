<?php

namespace App\Console\Commands;

use App\Services\ManticoreSearchService;
use App\Support\LandingClassifier;
use Illuminate\Console\Command;

/**
 * Read-only report of the "make model year" landings the current inventory supports: every model
 * and year with enough ads, its proposed slug and title, and whether a landing for that search
 * already exists. Nothing is created; this sizes the work before writing the landings.
 */
class LandingCandidates extends Command
{
    protected $signature = 'landings:candidates
                            {country : Código de país (pe, cl, ec, mx, lab)}
                            {--min-ads=8 : Avisos mínimos para proponer una landing}
                            {--min-year=2005 : Año más antiguo}
                            {--output= : Carpeta del CSV (por defecto storage/app/landings)}
                            {--sql : Además, escribir el SQL que crea (o reactiva) las landings nuevas}
                            {--memory=2G : Límite de memoria de PHP}';

    protected $description = 'Reporte (solo lectura) de landings marca + modelo + año que el inventario actual justifica.';

    private const COUNTRY_NAMES = ['cl' => 'Chile', 'mx' => 'México', 'pe' => 'Perú', 'ec' => 'Ecuador', 'lab' => 'Laboratorio'];

    /** Statuses, in the order the summary lists them. */
    private const STATUSES = [
        'nueva' => 'No existe: se crearía',
        'existe' => 'Ya existe una landing de la misma búsqueda',
        'otro_tipo' => 'Existe con otro tipo o condición (camionetas, 4x4, nuevos...)',
        'slug_ocupado' => 'El slug propuesto lo usa otra landing',
    ];

    public function handle(ManticoreSearchService $manticore): int
    {
        $country = strtolower((string) $this->argument('country'));
        if (!preg_match('/^[a-z]{2,5}$/', $country)) {
            $this->error("Código de país inválido: {$country}");

            return self::FAILURE;
        }
        ini_set('memory_limit', (string) $this->option('memory'));
        $minAds = max(1, (int) $this->option('min-ads'));
        $minYear = (int) $this->option('min-year');

        $this->info("[{$country}] Contando avisos por modelo y año...");
        $groups = $manticore->carCountsByModelAndYear('car_' . $country, $minYear, $minAds);
        $names = $this->modelNames($manticore, array_unique(array_column($groups, 'id_car_model')));

        $this->info("[{$country}] Leyendo landings activas...");
        [$byKey, $byTerms, $bySlug] = $this->existingLandings($manticore, $country);

        $rows = [];
        $new = [];
        foreach ($groups as $group) {
            $model = $names[(int) $group['id_car_model']] ?? null;
            if ($model === null) {
                continue;
            }

            $year = (int) $group['year_int'];
            $title = $model['name'] . ' ' . $year . ' usados';
            $slug = $model['slug'] . '-' . $year; // The legacy pattern of these landings: "kia-sportage-2020".
            $key = LandingClassifier::groupKey($title);
            $terms = $this->termsKey($title);

            [$status, $existing] = match (true) {
                isset($byKey[$key]) => ['existe', $byKey[$key]],
                isset($byTerms[$terms]) => ['otro_tipo', $byTerms[$terms]],
                isset($bySlug[$slug]) => ['slug_ocupado', $slug],
                default => ['nueva', ''],
            };

            $rows[] = [$model['make'], $model['model'], $year, (int) $group['total'], $slug, $title, $status, $existing];
            if ($status === 'nueva') {
                $new[] = ['model' => $model, 'model_id' => (int) $group['id_car_model'], 'year' => $year, 'slug' => $slug, 'title' => $title];
            }
        }

        usort($rows, fn ($a, $b) => [$b[3], $a[4]] <=> [$a[3], $b[4]]);
        $path = $this->writeCsv($country, $rows);
        $this->printSummary($country, $rows, $minAds, $minYear);
        $this->info("Detalle: {$path}");

        if ($this->option('sql')) {
            $sqlPath = substr($path, 0, -4) . '.sql';
            file_put_contents($sqlPath, $this->buildSql($country, $new));
            file_put_contents(substr($path, 0, -4) . '.json', json_encode([
                'country' => $country,
                'sql' => $sqlPath,
                'create' => count($new),
                'candidates' => count($rows),
                'titles' => array_column(array_slice($new, 0, 20), 'title'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info("SQL: {$sqlPath}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{make: string, model: string, name: string, slug: string}> by model id
     */
    private function modelNames(ManticoreSearchService $manticore, array $modelIds): array
    {
        $names = [];
        $makes = [];
        $makeSlugs = [];
        foreach (array_chunk(array_values($modelIds), 500) as $chunk) {
            foreach ($manticore->carModelsByIds($chunk) as $model) {
                $names[(int) $model['id']] = $model;
            }
        }

        $result = [];
        foreach ($names as $id => $model) {
            $makeId = (int) ($model['id_car_make'] ?? 0);
            if (!isset($makes[$makeId])) {
                $make = $manticore->getCarMakeById($makeId);
                $makes[$makeId] = (string) ($make['name'] ?? '');
                $makeSlugs[$makeId] = (string) ($make['slug'] ?? '');
            }
            $modelName = trim((string) $model['model_name']);
            if ($makes[$makeId] === '' || $modelName === '' || empty($model['slug'])) {
                continue;
            }

            $result[$id] = [
                'make_id' => $makeId,
                'make_slug' => $makeSlugs[$makeId],
                'make' => $makes[$makeId],
                'model' => $modelName,
                'name' => $makes[$makeId] . ' ' . $modelName,
                'slug' => (string) $model['slug'],
            ];
        }

        return $result;
    }

    /**
     * Active landings by group key, by terms alone (any type or condition) and by slug. Only
     * landings whose title has a year can match a candidate, so the rest are not kept.
     *
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, true>}
     */
    private function existingLandings(ManticoreSearchService $manticore, string $country): array
    {
        $byKey = [];
        $byTerms = [];
        $bySlug = [];

        foreach ($manticore->iterateCarLandings($country) as $row) {
            if (trim((string) $row['redirect']) !== '') {
                continue;
            }
            $slug = (string) $row['slug'];
            $bySlug[$slug] = true;

            $title = (string) $row['title'];
            if (!preg_match('/\b(19|20)\d{2}\b/', $title) || LandingClassifier::junkReason($title) !== null) {
                continue;
            }
            $byKey[LandingClassifier::groupKey($title)] ??= $slug;
            $byTerms[$this->termsKey($title)] ??= $slug;
        }

        return [$byKey, $byTerms, $bySlug];
    }

    private function termsKey(string $title): string
    {
        $terms = LandingClassifier::classify($title)['terms'];
        sort($terms);

        return implode(' ', $terms);
    }

    /**
     * One landing per new model and year. car_landings is unique by (country_id, slug), so a row
     * with that slug (removed or redirected earlier) is brought back instead of duplicated. The
     * table is MyISAM, without transactions: each statement stands alone and can be run again.
     * The content carries the filters the page lists and the date landings:clean counts the grace
     * period from.
     */
    private function buildSql(string $country, array $new): string
    {
        $q = fn (string $value) => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        $countryName = self::COUNTRY_NAMES[$country] ?? ucfirst($country);
        $today = date('Y-m-d');
        $columns = ['country_id', 'slug', 'title', 'title_h1', 'meta_title', 'meta_description', 'content', 'id_car_make', 'id_car_model', 'id_state', 'id_city', 'redirect', 'activo'];
        $update = implode(', ', array_map(fn ($column) => "{$column} = new.{$column}", array_slice($columns, 2)));

        $sql = ["-- Landings marca + modelo + año nuevas para '{$country}' (" . count($new) . ') - ' . date('Y-m-d H:i:s'), 'SET NAMES utf8mb4;', ''];
        foreach ($new as $landing) {
            $model = $landing['model'];
            $content = json_encode([
                'breadcrumb' => ['make' => $model['make_slug'], 'model' => $model['slug']],
                'generated' => ['make' => $model['make_id'], 'model' => $landing['model_id'], 'year' => $landing['year'], 'created' => $today],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $values = [
                $q($country),
                $q($landing['slug']),
                $q($landing['title']),
                $q($landing['title']),
                $q("{$landing['title']} en {$countryName}"),
                $q("{$landing['title']} en {$countryName}: compara precios, kilometraje y avisos de concesionarios y particulares en Roodos."),
                $q($content),
                $model['make_id'],
                $landing['model_id'],
                0,
                0,
                "''",
                1,
            ];

            $sql[] = 'INSERT INTO car_landings (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ") AS new ON DUPLICATE KEY UPDATE {$update};";
        }

        return implode("\n", $sql) . "\n";
    }

    private function writeCsv(string $country, array $rows): string
    {
        $directory = $this->option('output') ?: storage_path('app/landings');
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $path = $directory . '/landings_candidates_' . $country . '_' . date('Ymd_His') . '.csv';

        $handle = fopen($path, 'w');
        fputcsv($handle, ['marca', 'modelo', 'año', 'avisos', 'slug_propuesto', 'titulo', 'estado', 'landing_existente']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }

    private function printSummary(string $country, array $rows, int $minAds, int $minYear): void
    {
        $this->newLine();
        $this->line(sprintf('[%s] Modelo + año desde %d con %d avisos o más: %d', $country, $minYear, $minAds, count($rows)));

        $counts = array_count_values(array_column($rows, 6));
        $this->table(['Estado', 'Landings', ''], array_map(
            fn ($status) => [$status, $counts[$status] ?? 0, self::STATUSES[$status]],
            array_keys(self::STATUSES)
        ));

        $byThreshold = [];
        foreach ([5, 8, 10, 15, 20] as $threshold) {
            if ($threshold < $minAds) {
                continue;
            }
            $subset = array_filter($rows, fn ($row) => $row[3] >= $threshold);
            $byThreshold[] = [$threshold . '+', count($subset), count(array_filter($subset, fn ($row) => $row[6] === 'nueva'))];
        }
        $this->table(['Avisos', 'Candidatas', 'Nuevas'], $byThreshold);

        $new = array_slice(array_values(array_filter($rows, fn ($row) => $row[6] === 'nueva')), 0, 10);
        if ($new !== []) {
            $this->line('Nuevas con más avisos:');
            foreach ($new as $row) {
                $this->line(sprintf('  %4d  %s  /%s', $row[3], $row[5], $row[4]));
            }
        }
    }
}
