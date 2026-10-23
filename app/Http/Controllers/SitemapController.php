<?php

namespace App\Http\Controllers;

use App\Console\Commands\GenerateSitemaps;
use App\Support\Sitemap;

class SitemapController extends Controller
{
    /**
     * Index of the country's sitemap files: landings-N.xml (pages that stay) and, while the
     * cleanup is being crawled, removed-N.xml (URLs that now redirect or return 410).
     */
    public function index(string $country)
    {
        $country = strtolower($country);
        $directory = Sitemap::directory($country);
        // Until the country's cleanup is applied its URLs still answer 200: publish nothing yet.
        $files = is_file($directory . '/' . GenerateSitemaps::CLEANUP_MARKER)
            ? (glob($directory . '/{landings,removed}-*.xml', GLOB_BRACE) ?: [])
            : [];
        natsort($files);

        $urls = array_map(fn ($file) => Sitemap::baseUrl($country) . '/sitemaps/' . basename($file), array_values($files));

        return response(Sitemap::index($urls), 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Allows everything on purpose: removed URLs must stay crawlable until Google sees their
     * 410/301. Block patterns here only once Search Console shows them dropped from the index.
     */
    public function robots(string $country)
    {
        $body = "User-agent: *\nDisallow:\n\nSitemap: " . Sitemap::baseUrl(strtolower($country)) . "/sitemap.xml\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function show(string $country, string $file)
    {
        $directory = Sitemap::directory(strtolower($country));
        $path = $directory . '/' . $file;
        abort_unless(is_file($path) && is_file($directory . '/' . GenerateSitemaps::CLEANUP_MARKER), 404);

        return response()->file($path, ['Content-Type' => 'application/xml']);
    }
}
