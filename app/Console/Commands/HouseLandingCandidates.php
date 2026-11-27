<?php

namespace App\Console\Commands;

use App\Services\ManticoreSearchService;
use App\Support\HouseLandings;
use App\Support\LandingNames;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Read-only report of the house landings the current inventory supports: operation + type for the
 * whole country, per region and per city, with enough ads, and whether a landing for that search
 * already exists. With --sql it writes the statements that create the new ones, to review before
 * running them.
 */
class HouseLandingCandidates extends Command
{
    protected $signature = 'house-landings:candidates
                            {country : Código de país (pe, cl, ec, mx, lab)}
                            {--min-ads=8 : Avisos mínimos para proponer una landing}
                            {--max-share=0.85 : No se propone una ciudad (o región) que tenga más que esta parte de los avisos de su región (o del país)}
                            {--output= : Carpeta del CSV (por defecto storage/app/landings)}
                            {--sql : Además, escribir el SQL que crea (o reactiva) las landings nuevas}';

    protected $description = 'Reporte (solo lectura) de landings de casas operación + tipo + lugar que el inventario actual justifica.';

    private const COUNTRY_NAMES = ['cl' => 'Chile', 'mx' => 'México', 'pe' => 'Perú', 'ec' => 'Ecuador', 'lab' => 'Laboratorio'];

    private const STATUSES = [
        'nueva' => 'No existe: se crearía',
        'existe' => 'Ya existe una landing de la misma búsqueda (o con su slug)',
        'no_acota' => 'Casi todos los avisos de su región o del país: sería la misma página',
    ];

    /** Words that do not change a search: "Arriendo de casas en Lima" is "casas arriendo Lima". */
    private const STOPWORDS = ['en', 'de', 'del', 'la', 'las', 'los', 'el', 'y', 'para'];
    private const SYNONYMS = ['arriendo' => 'renta', 'alquiler' => 'renta', 'arrienda' => 'renta', 'alquila' => 'renta', 'vende' => 'venta', 'depto' => 'departamento'];

    public function handle(ManticoreSearchService $manticore): int
    {
        $country = strtolower((string) $this->argument('country'));
        if (!preg_match('/^[a-z]{2,5}$/', $country)) {
            $this->error("Código de país inválido: {$country}");

            return self::FAILURE;
        }
        $minAds = max(1, (int) $this->option('min-ads'));
        $maxShare = (float) $this->option('max-share');
        $index = 'house_' . $country;

        $this->info("[{$country}] Contando avisos por operación, tipo y lugar...");
        $levels = [
            'pais' => $manticore->houseCountsByPlace($index, 'country', $minAds),
            'region' => $manticore->houseCountsByPlace($index, 'state', $minAds),
            'ciudad' => $manticore->houseCountsByPlace($index, 'city', $minAds),
        ];
        $countryTotals = [];
        foreach ($levels['pais'] as $group) {
            $countryTotals[$group['id_house_operation'] . '|' . $group['id_house_type']] = (int) $group['total'];
        }
        $stateTotals = [];
        foreach ($levels['region'] as $group) {
            $stateTotals[$group['id_house_operation'] . '|' . $group['id_house_type'] . '|' . $group['id_state']] = (int) $group['total'];
        }

        // A city name in two regions (Benito Juárez in Mexico City and in Quintana Roo), or equal to
        // a region's (Querétaro), carries its region.
        $citiesByName = [];
        foreach ($levels['ciudad'] as $group) {
            $citiesByName[Str::slug(HouseLandings::city((string) $group['city_name']))][(int) $group['id_city']] = true;
        }
        $stateSlugs = [];
        foreach ($levels['region'] as $group) {
            $stateSlugs[Str::slug(LandingNames::place((string) $group['state_name']))] = true;
        }

        [$bySlug, $byTerms] = $this->existingLandings($manticore, $country);

        $rows = [];
        $new = [];
        foreach ($levels as $level => $groups) {
            usort($groups, fn ($a, $b) => (int) $b['total'] <=> (int) $a['total']);
            foreach ($groups as $group) {
                $operation = (string) $group['house_operation_name'];
                $type = (string) $group['house_type_name'];
                if (trim($operation) === '' || trim($type) === '') {
                    continue;
                }
                $pair = $group['id_house_operation'] . '|' . $group['id_house_type'];
                $total = (int) $group['total'];
                $stateId = $level === 'pais' ? 0 : (int) $group['id_state'];
                $cityId = $level === 'ciudad' ? (int) $group['id_city'] : 0;

                $place = match ($level) {
                    'pais' => null,
                    'region' => LandingNames::place((string) $group['state_name']),
                    'ciudad' => $this->cityPlace($group, $citiesByName, $stateSlugs),
                };
                if ($place === '') {
                    continue;
                }
                $title = HouseLandings::title($operation, $type, $place);
                $slug = Str::slug($title);

                $parentTotal = match ($level) {
                    'pais' => null,
                    'region' => $countryTotals[$pair] ?? null,
                    'ciudad' => $stateTotals[$pair . '|' . $stateId] ?? null,
                };

                [$status, $existing] = match (true) {
                    isset($bySlug[$slug]) => ['existe', $slug],
                    isset($byTerms[$this->termsKey($title)]) => ['existe', $byTerms[$this->termsKey($title)]],
                    $parentTotal !== null && $total > $maxShare * $parentTotal => ['no_acota', ''],
                    default => ['nueva', ''],
                };
                $bySlug[$slug] ??= $slug; // two groups with one title (a city in one region named twice)

                $rows[] = [$level, $operation, $type, (string) $place, $total, $slug, $title, $status, $existing];
                if ($status === 'nueva') {
                    $new[] = [
                        'slug' => $slug, 'title' => $title, 'level' => $level, 'total' => $total,
                        'operation' => (int) $group['id_house_operation'], 'type' => (int) $group['id_house_type'],
                        'state' => $stateId, 'city' => $cityId,
                    ];
                }
            }
        }

        $path = $this->writeCsv($country, $rows);
        $this->printSummary($country, $rows, $minAds);
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

    private function cityPlace(array $group, array $citiesByName, array $stateSlugs): string
    {
        $city = HouseLandings::city((string) $group['city_name']);
        $state = LandingNames::place((string) $group['state_name']);
        $slug = Str::slug($city);
        if ($slug === '') {
            return '';
        }
        if (count($citiesByName[$slug] ?? []) <= 1 && !isset($stateSlugs[$slug])) {
            return $city;
        }

        // Querétaro (the city) in Querétaro (the region): the region's landing keeps the name.
        return Str::slug($state) === $slug || $state === '' ? "{$city} (ciudad)" : "{$city}, {$state}";
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} slugs, and slugs by search terms */
    private function existingLandings(ManticoreSearchService $manticore, string $country): array
    {
        $bySlug = [];
        $byTerms = [];
        foreach ($manticore->iterateHouseLandings($country) as $row) {
            $slug = (string) $row['slug'];
            $bySlug[$slug] = $slug;
            $byTerms[$this->termsKey((string) $row['title'])] ??= $slug;
        }

        return [$bySlug, $byTerms];
    }

    private function termsKey(string $title): string
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower(Str::ascii($title)), -1, PREG_SPLIT_NO_EMPTY);
        $terms = [];
        foreach ($words as $word) {
            if (in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $word = self::SYNONYMS[$word] ?? $word;
            if (strlen($word) > 4 && str_ends_with($word, 'es') && !str_ends_with($word, 'ees')) {
                $word = substr($word, 0, -2);
            } elseif (strlen($word) > 3 && str_ends_with($word, 's')) {
                $word = substr($word, 0, -1);
            }
            $terms[$word] = true;
        }
        $terms = array_keys($terms);
        sort($terms);

        return implode(' ', $terms);
    }

    /**
     * One landing per new search, the whole country first and then regions and cities with the
     * most ads: the landing links list them in id order. house_landings is unique by
     * (country_id, slug), so a row with that slug is brought back instead of duplicated; MyISAM,
     * so each statement stands alone and can be run again.
     */
    private function buildSql(string $country, array $new): string
    {
        $q = fn (string $value) => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        $countryName = self::COUNTRY_NAMES[$country] ?? ucfirst($country);
        $today = date('Y-m-d');
        $columns = ['country_id', 'slug', 'title', 'title_h1', 'meta_title', 'meta_description', 'content', 'id_house_operation', 'id_house_type', 'id_state', 'id_city', 'activo', 'valor'];
        $update = implode(', ', array_map(fn ($column) => "{$column} = new.{$column}", array_slice($columns, 2)));

        $sql = ["-- Landings de casas operación + tipo + lugar nuevas para '{$country}' (" . count($new) . ') - ' . date('Y-m-d H:i:s'), 'SET NAMES utf8mb4;', ''];
        foreach ($new as $landing) {
            $generated = ['operation' => $landing['operation'], 'type' => $landing['type']]
                + ($landing['level'] === 'region' ? ['state' => $landing['state']] : [])
                + ($landing['level'] === 'ciudad' ? ['city' => $landing['city']] : [])
                + ['created' => $today];
            $content = json_encode(['generated' => $generated], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $metaTitle = $landing['title'] . ($landing['level'] === 'pais' ? ' en ' : ', ') . $countryName;

            $values = [
                $q($country),
                $q($landing['slug']),
                $q($landing['title']),
                $q($landing['title']),
                $q($metaTitle),
                $q($metaTitle . ': compara precios, precio por m² y superficie en avisos de inmobiliarias y particulares en Roodos.'),
                $q($content),
                $landing['operation'],
                $landing['type'],
                $landing['state'],
                $landing['city'],
                1,
                $landing['total'],
            ];

            $sql[] = 'INSERT INTO house_landings (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ") AS new ON DUPLICATE KEY UPDATE {$update};";
        }

        return implode("\n", $sql) . "\n";
    }

    private function writeCsv(string $country, array $rows): string
    {
        $directory = $this->option('output') ?: storage_path('app/landings');
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $path = $directory . '/house_landings_candidates_' . $country . '_' . date('Ymd_His') . '.csv';

        $handle = fopen($path, 'w');
        fputcsv($handle, ['nivel', 'operacion', 'tipo', 'lugar', 'avisos', 'slug_propuesto', 'titulo', 'estado', 'landing_existente']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }

    private function printSummary(string $country, array $rows, int $minAds): void
    {
        $this->newLine();
        $this->line(sprintf('[%s] Operación + tipo + lugar con %d avisos o más: %d', $country, $minAds, count($rows)));
        $table = [];
        foreach (['pais', 'region', 'ciudad'] as $level) {
            $subset = array_filter($rows, fn ($row) => $row[0] === $level);
            $counts = array_count_values(array_column($subset, 7));
            $table[] = [$level, $counts['nueva'] ?? 0, $counts['existe'] ?? 0, $counts['no_acota'] ?? 0];
        }
        $this->table(['Nivel', 'nueva', 'existe', 'no_acota'], $table);

        $new = array_slice(array_values(array_filter($rows, fn ($row) => $row[7] === 'nueva')), 0, 10);
        foreach ($new as $row) {
            $this->line(sprintf('  %5d  %s  /%s', $row[4], $row[6], $row[5]));
        }
    }
}
