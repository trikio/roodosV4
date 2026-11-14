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
                            {--memory=2G : Límite de memoria de PHP}';

    protected $description = 'Reporte (solo lectura) de landings marca + modelo + año que el inventario actual justifica.';

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
        }

        usort($rows, fn ($a, $b) => [$b[3], $a[4]] <=> [$a[3], $b[4]]);
        $path = $this->writeCsv($country, $rows);
        $this->printSummary($country, $rows, $minAds, $minYear);
        $this->info("Detalle: {$path}");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{make: string, model: string, name: string, slug: string}> by model id
     */
    private function modelNames(ManticoreSearchService $manticore, array $modelIds): array
    {
        $names = [];
        $makes = [];
        foreach (array_chunk(array_values($modelIds), 500) as $chunk) {
            foreach ($manticore->carModelsByIds($chunk) as $model) {
                $names[(int) $model['id']] = $model;
            }
        }

        $result = [];
        foreach ($names as $id => $model) {
            $makeId = (int) ($model['id_car_make'] ?? 0);
            $makes[$makeId] ??= (string) ($manticore->getCarMakeById($makeId)['name'] ?? '');
            $modelName = trim((string) $model['model_name']);
            if ($makes[$makeId] === '' || $modelName === '' || empty($model['slug'])) {
                continue;
            }

            $result[$id] = [
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
