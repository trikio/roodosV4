<?php

namespace App\Console\Commands;

use App\Services\ManticoreSearchService;
use App\Support\Sitemap;
use Illuminate\Console\Command;

class GenerateSitemaps extends Command
{
    protected $signature = 'sitemaps:generate
                            {country? : Código de país; sin él, todos menos lab}
                            {--force : Generar aunque la limpieza de landings no esté marcada como aplicada}';

    /**
     * Created by hand once the landings:clean SQL is applied and reindexed. Until then the
     * index still holds every legacy landing, and a sitemap would ask Google to crawl them.
     */
    public const CLEANUP_MARKER = '.limpieza-aplicada';

    protected $description = 'Escribe los sitemaps de landings activas (sin redirect) de cada país en storage/app/sitemaps.';

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
                $this->warn("[{$countryCode}] Omitido: falta {$directory}/" . self::CLEANUP_MARKER . ' (crearlo después de aplicar la limpieza de landings).');
                continue;
            }

            // Written aside and swapped in, so the served sitemap is never half written.
            $files = 0;
            $total = 0;
            $urls = [];
            $flush = function () use (&$urls, &$files, $directory) {
                file_put_contents($directory . '/landings-' . (++$files) . '.xml.tmp', Sitemap::urlset($urls));
                $urls = [];
            };

            foreach ($manticore->iterateCarLandings($countryCode) as $row) {
                if (trim((string) $row['redirect']) !== '') {
                    continue;
                }
                $urls[] = Sitemap::landingUrl($countryCode, (string) $row['slug']);
                $total++;
                if (count($urls) === self::SITEMAP_SIZE) {
                    $flush();
                }
            }
            if ($urls !== []) {
                $flush();
            }

            array_map('unlink', glob($directory . '/landings-*.xml') ?: []);
            foreach (glob($directory . '/landings-*.xml.tmp') ?: [] as $temporary) {
                rename($temporary, substr($temporary, 0, -4));
            }

            $this->info("[{$countryCode}] {$total} landings en {$files} sitemap(s).");
        }

        return self::SUCCESS;
    }
}
