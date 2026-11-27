<?php

namespace App\Console\Commands;

use App\Services\ManticoreSearchService;
use App\Support\HouseLandings;
use App\Support\Sitemap;
use Illuminate\Console\Command;

class GenerateSitemaps extends Command
{
    protected $signature = 'sitemaps:generate
                            {country? : Código de país; sin él, todos menos lab}
                            {--force : Generar aunque la limpieza de landings no esté marcada como aplicada}';

    /**
     * Created by hand once the landings:clean SQL is applied and reindexed. Until then the
     * index still holds every legacy car landing, and a sitemap would ask Google to crawl them.
     */
    public const CLEANUP_MARKER = '.limpieza-aplicada';

    protected $description = 'Escribe los sitemaps de landings de autos activas (sin redirect) y de landings de casas creadas desde el inventario de cada país en storage/app/sitemaps.';

    private const SITEMAP_SIZE = 50000;

    public function handle(ManticoreSearchService $manticore): int
    {
        $country = $this->argument('country');
        $countries = $country !== null
            ? [strtolower($country)]
            : array_values(array_diff(array_map('strtolower', array_keys(config('countries.names'))), ['lab']));

        foreach ($countries as $countryCode) {
            if (!preg_match('/^[a-z]{2,5}$/', $countryCode)) {
                $this->error("Código de país inválido: {$countryCode}");

                return self::FAILURE;
            }

            $directory = Sitemap::directory($countryCode);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            if (!$this->option('force') && !is_file($directory . '/' . self::CLEANUP_MARKER)) {
                $this->warn("[{$countryCode}] Autos omitido: falta {$directory}/" . self::CLEANUP_MARKER . ' (crearlo después de aplicar la limpieza de landings).');
            } else {
                $urls = (function () use ($manticore, $countryCode) {
                    foreach ($manticore->iterateCarLandings($countryCode) as $row) {
                        if (trim((string) $row['redirect']) === '') {
                            yield Sitemap::landingUrl($countryCode, (string) $row['slug']);
                        }
                    }
                })();
                [$total, $files] = $this->write($directory, 'landings', $urls);
                $this->info("[{$countryCode}] Autos: {$total} landings en {$files} sitemap(s).");
            }

            // Only the house landings made from the inventory: their search is exact and has ads.
            $urls = (function () use ($manticore, $countryCode) {
                foreach ($manticore->iterateHouseLandings($countryCode) as $row) {
                    if (HouseLandings::generatedFilters($row['content'] ?? null) !== null) {
                        yield Sitemap::landingUrl($countryCode, (string) $row['slug'], 'casas');
                    }
                }
            })();
            [$total, $files] = $this->write($directory, 'casas', $urls);
            $this->info("[{$countryCode}] Casas: {$total} landings en {$files} sitemap(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Writes {$prefix}-N.xml aside and swaps them in, so a served sitemap is never half written.
     *
     * @return array{0: int, 1: int} URLs and files written
     */
    private function write(string $directory, string $prefix, iterable $urls): array
    {
        $files = 0;
        $total = 0;
        $chunk = [];
        $flush = function () use (&$chunk, &$files, $directory, $prefix) {
            file_put_contents($directory . '/' . $prefix . '-' . (++$files) . '.xml.tmp', Sitemap::urlset($chunk));
            $chunk = [];
        };

        foreach ($urls as $url) {
            $chunk[] = $url;
            $total++;
            if (count($chunk) === self::SITEMAP_SIZE) {
                $flush();
            }
        }
        if ($chunk !== []) {
            $flush();
        }

        array_map('unlink', glob($directory . '/' . $prefix . '-*.xml') ?: []);
        foreach (glob($directory . '/' . $prefix . '-*.xml.tmp') ?: [] as $temporary) {
            rename($temporary, substr($temporary, 0, -4));
        }

        return [$total, $files];
    }
}
