<?php

namespace App\Console\Commands;

use App\Services\ManticoreSearchService;
use App\Support\LandingClassifier;
use App\Support\LandingNames;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Read-only report of the landings the current inventory supports: every model crossed with a year
 * (default), a transmission, a fuel or a region that has enough ads, its proposed slug and title,
 * and whether a landing for that search already exists. Nothing is created; with --sql it writes
 * the statements that create the new ones, to review before running them.
 */
class LandingCandidates extends Command
{
    protected $signature = 'landings:candidates
                            {country : Código de país (pe, cl, ec, mx, lab)}
                            {--min-ads=8 : Avisos mínimos para proponer una landing}
                            {--kind=anio : Qué se cruza con el modelo: anio, transmision, combustible, region o ciudad}
                            {--min-year=2005 : Año más antiguo (solo --kind=anio)}
                            {--max-share=0.85 : Fuera de anio, no se propone si cubre más que esta parte de los avisos del modelo}
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
        'no_acota' => 'Casi todos los avisos del modelo: sería la misma página del modelo',
    ];

    /**
     * Catalog models that are not worth a landing: duplicates of another model with a worse name
     * ("Cs 35" of CS35, "Crv" of CR-V, "Mg Rx5" of Rx5), versions ("F150 Xlt") or vague words
     * ("Quattro", "Serie", "Clase"). As "make|model" in lower case.
     */
    private const SKIP_MODELS = [
        'audi|coupe', 'audi|quattro', 'bmw|serie', 'changan|cs 15', 'changan|cs 35', 'changan|new cs15',
        'chery|tiggo', 'chevrolet|crdi', 'chevrolet|dmax crdi', 'chevrolet|luv dmax', 'chevrolet|joy black',
        'ford|f150', 'ford|f150 4x4', 'ford|f150 dc', 'ford|f150 lariat', 'ford|f150 raptor', 'ford|f150 xlt',
        'gac|motor', 'geely|ge', 'honda|br', 'honda|crv', 'honda|wr', 'hyundai|grand', 'jeep|jt',
        'kia|soluto lx', 'kia|sonet ac', 'land rover|land rover', 'mazda|cx', 'mercedes benz|200',
        'mercedes benz|250', 'mercedes benz|clase', 'mg|mg', 'mg|mg gt', 'mg|mg hs', 'mg|mg rx5', 'mg|mg zx',
        'mg|mg3', 'mg|mg5', 'nissan|xtrail', 'ram|ram', 'toyota|rav', 'toyota|new rav', 'toyota|super',
        'zx|auto', 'zx|auto terralord',
    ];

    /** --kind => [index attribute, generated key, CSV column]. */
    private const KINDS = [
        'anio' => ['year_int', 'year', 'año'],
        'transmision' => ['id_transmission', 'transmission', 'transmisión'],
        'combustible' => ['id_fuel', 'fuel', 'combustible'],
        'region' => ['id_state', 'state', 'región'],
        'ciudad' => ['id_city', 'city', 'ciudad'],
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
        $kind = (string) $this->option('kind');
        if (!isset(self::KINDS[$kind])) {
            $this->error('--kind debe ser ' . implode(', ', array_keys(self::KINDS)));

            return self::FAILURE;
        }
        [$attribute, $generatedKey] = self::KINDS[$kind];
        $maxShare = (float) $this->option('max-share');

        $this->info("[{$country}] Contando avisos por modelo y {$kind}...");
        if ($kind === 'anio') {
            $groups = array_map(fn ($group) => $group + ['value' => $group['year_int'], 'name' => $group['year_int']],
                $manticore->carCountsByModelAndYear('car_' . $country, $minYear, $minAds));
            $modelTotals = [];
        } else {
            $groups = $manticore->carCountsByModelAnd('car_' . $country, $attribute, $minAds);
            $modelTotals = $manticore->carCountsByModel('car_' . $country);
        }
        $names = $this->modelNames($manticore, array_unique(array_column($groups, 'id_car_model')));

        $this->info("[{$country}] Leyendo landings activas...");
        [$byKey, $byTerms, $bySlug] = $this->existingLandings($manticore, $country, $kind === 'anio');

        $rows = [];
        $new = [];
        foreach ($groups as $group) {
            $modelId = (int) $group['id_car_model'];
            $model = $names[$modelId] ?? null;
            $words = $this->words($kind, (string) $group['name'], (string) ($group['state_name'] ?? ''));
            if ($model === null || $words === null) {
                continue;
            }

            [$title, $slug] = $this->titleAndSlug($kind, $model, $words);
            $key = LandingClassifier::groupKey($title);
            $terms = $this->termsKey($title);
            $total = (int) $group['total'];

            [$status, $existing] = match (true) {
                isset($byKey[$key]) => ['existe', $byKey[$key]],
                isset($byTerms[$terms]) => ['otro_tipo', $byTerms[$terms]],
                isset($bySlug[$slug]) => ['slug_ocupado', $slug],
                $modelTotals !== [] && $total > $maxShare * ($modelTotals[$modelId] ?? $total) => ['no_acota', ''],
                default => ['nueva', ''],
            };

            $rows[] = [$model['make'], $model['model'], (string) $group['name'], $total, $slug, $title, $status, $existing];
            if ($status === 'nueva') {
                $new[] = [
                    'model' => $model, 'model_id' => $modelId, 'slug' => $slug, 'title' => $title, 'kind' => $kind,
                    'filters' => [$generatedKey => (int) $group['value']],
                    'state' => $kind === 'region' ? (int) $group['value'] : 0,
                    'city' => $kind === 'ciudad' ? (int) $group['value'] : 0,
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$b[3], $a[4]] <=> [$a[3], $b[4]]);
        $path = $this->writeCsv($country, $kind, $rows);
        $this->printSummary($country, $rows, $minAds, $minYear, $kind);
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
            if ($makes[$makeId] === '' || $modelName === '' || empty($model['slug'])
                || in_array(mb_strtolower($makes[$makeId] . '|' . $modelName), self::SKIP_MODELS, true)) {
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
     * Active landings by group key, by terms alone (any type or condition) and by slug. For year
     * candidates only landings whose title has a year can match, so the rest are not kept.
     *
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, true>}
     */
    private function existingLandings(ManticoreSearchService $manticore, string $country, bool $yearsOnly): array
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
            if (($yearsOnly && !preg_match('/\b(19|20)\d{2}\b/', $title)) || LandingClassifier::junkReason($title) !== null) {
                continue;
            }
            $byKey[LandingClassifier::groupKey($title)] ??= $slug;
            $byTerms[$this->termsKey($title)] ??= $slug;
        }

        return [$byKey, $byTerms, $bySlug];
    }

    /**
     * The words a landing of this kind adds to the model ("2020", "automático", "diésel",
     * "Santiago", "Los Olivos"), or null when the value is not one to make landings of. A city
     * named like its region (Lima, Arequipa, Nayarit) is left to the region's landing: same words,
     * and in Mexico often a region an ad named twice rather than a real city.
     */
    private function words(string $kind, string $name, string $stateName = ''): ?string
    {
        $words = match ($kind) {
            'anio' => $name,
            'transmision' => LandingNames::transmission($name),
            'combustible' => LandingNames::fuel($name),
            'region' => LandingNames::place($name),
            'ciudad' => $stateName !== '' && Str::slug($name) === Str::slug(LandingNames::place($stateName)) ? null : trim($name),
        };

        return $words === null || trim($words) === '' ? null : $words;
    }

    /**
     * "Toyota Hilux 2020 usados" at /toyota-hilux-2020 (the legacy pattern of these landings),
     * "Toyota Corolla automático usados" at /toyota-corolla-automatico-usados and "Toyota Hilux
     * usados en Santiago" at /toyota-hilux-usados-en-santiago (cities the same: "Volkswagen
     * Escarabajo usados en Los Olivos").
     *
     * @return array{0: string, 1: string}
     */
    private function titleAndSlug(string $kind, array $model, string $words): array
    {
        return match ($kind) {
            'anio' => ["{$model['name']} {$words} usados", $model['slug'] . '-' . $words],
            'region', 'ciudad' => ["{$model['name']} usados en {$words}", $model['slug'] . '-usados-en-' . Str::slug($words)],
            default => ["{$model['name']} {$words} usados", $model['slug'] . '-' . Str::slug($words) . '-usados'],
        };
    }

    private function termsKey(string $title): string
    {
        $terms = LandingClassifier::classify($title)['terms'];
        sort($terms);

        return implode(' ', $terms);
    }

    /**
     * One landing per new model and year (or transmission, fuel, region). car_landings is unique by (country_id, slug), so a row
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
                'generated' => ['make' => $model['make_id'], 'model' => $landing['model_id']] + $landing['filters'] + ['created' => $today],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            // "... usados en Santiago en Chile" would read badly.
            $inCountry = (in_array($landing['kind'], ['region', 'ciudad'], true) ? ', ' : ' en ') . $countryName;

            $values = [
                $q($country),
                $q($landing['slug']),
                $q($landing['title']),
                $q($landing['title']),
                $q($landing['title'] . $inCountry),
                $q($landing['title'] . $inCountry . ": compara precios, kilometraje y avisos de concesionarios y particulares en Roodos."),
                $q($content),
                $model['make_id'],
                $landing['model_id'],
                $landing['state'],
                $landing['city'],
                "''",
                1,
            ];

            $sql[] = 'INSERT INTO car_landings (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ") AS new ON DUPLICATE KEY UPDATE {$update};";
        }

        return implode("\n", $sql) . "\n";
    }

    private function writeCsv(string $country, string $kind, array $rows): string
    {
        $directory = $this->option('output') ?: storage_path('app/landings');
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $suffix = $kind === 'anio' ? '' : $kind . '_';
        $path = $directory . '/landings_candidates_' . $country . '_' . $suffix . date('Ymd_His') . '.csv';

        $handle = fopen($path, 'w');
        fputcsv($handle, ['marca', 'modelo', self::KINDS[$kind][2], 'avisos', 'slug_propuesto', 'titulo', 'estado', 'landing_existente']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }

    private function printSummary(string $country, array $rows, int $minAds, int $minYear, string $kind): void
    {
        $this->newLine();
        $what = $kind === 'anio' ? 'año desde ' . $minYear : self::KINDS[$kind][2];
        $this->line(sprintf('[%s] Modelo + %s con %d avisos o más: %d', $country, $what, $minAds, count($rows)));

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
