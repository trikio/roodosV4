<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Turns the "Visits from Google" exports (Mixpanel Insights: Date, Metric, Current URL, visits)
 * into resources/landings/google/{país}.txt, the landings landings:clean never touches. Exports
 * can be split by period or overlap: slugs are merged with the lists already there.
 */
class ImportLandingVisits extends Command
{
    protected $signature = 'landings:import-visits
                            {files* : CSV exportados (Visits from Google_Insights_*.csv)}
                            {--replace : Reemplazar las listas en vez de sumarles los slugs}';

    protected $description = 'Arma la lista de landings con visitas desde Google, que landings:clean conserva.';

    /** Paths that are not landings. */
    private const NOT_LANDINGS = '#^(auto|casa|marca|modelo|guias|search|sitemaps|buscar)(/|$)#';

    public function handle(): int
    {
        $slugs = [];
        foreach ($this->argument('files') as $file) {
            if (!is_readable($file)) {
                $this->error("No se puede leer {$file}");

                return self::FAILURE;
            }

            $handle = fopen($file, 'r');
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 4 || (int) $row[3] <= 0) {
                    continue;
                }
                $url = parse_url($row[2]);
                if (!preg_match('/^autos\.roodos\.([a-z]+)$/', $url['host'] ?? '', $host)) {
                    continue;
                }
                $slug = strtolower(trim(rawurldecode($url['path'] ?? ''), '/'));
                if ($slug !== '' && !str_contains($slug, '/') && !preg_match(self::NOT_LANDINGS, $slug)) {
                    $slugs[$host[1]][$slug] = true;
                }
            }
            fclose($handle);
        }

        $directory = resource_path('landings/google');
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        ksort($slugs);
        foreach ($slugs as $country => $countrySlugs) {
            $path = "{$directory}/{$country}.txt";
            $before = !$this->option('replace') && is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            $all = array_unique(array_merge($before, array_keys($countrySlugs)));
            sort($all);
            file_put_contents($path, implode("\n", $all) . "\n");
            $this->line(sprintf('%s: %d landings visitadas (%d nuevas en la lista)', $country, count($all), count($all) - count($before)));
        }

        return self::SUCCESS;
    }
}
