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

    /** Bots de IA y de SEO que recorrían los listados a ~10 peticiones por segundo. */
    public const SLOWED_BOTS = ['ClaudeBot', 'GPTBot', 'AhrefsBot', 'SemrushBot', 'MJ12bot'];

    public const CRAWL_DELAY = 5;

    /**
     * Allows everything on purpose: removed URLs must stay crawlable until Google sees their
     * 410/301. Block patterns here only once Search Console shows them dropped from the index.
     * The bots in SLOWED_BOTS only get a Crawl-delay (Googlebot ignores it anyway).
     */
    public function robots(string $country)
    {
        return $this->robotsResponse('Sitemap: ' . Sitemap::baseUrl(strtolower($country)) . "/sitemap.xml\n");
    }

    public function housesRobots(string $country)
    {
        return $this->robotsResponse('Sitemap: ' . Sitemap::baseUrl(strtolower($country), 'casas') . "/sitemap.xml\n");
    }

    /** House landings made from the inventory (casas-N.xml); they need no cleanup first. */
    public function housesIndex(string $country)
    {
        $country = strtolower($country);
        $files = glob(Sitemap::directory($country) . '/casas-*.xml') ?: [];
        natsort($files);

        $urls = array_map(fn ($file) => Sitemap::baseUrl($country, 'casas') . '/sitemaps/' . basename($file), array_values($files));

        return response(Sitemap::index($urls), 200, ['Content-Type' => 'application/xml']);
    }

    public function housesShow(string $country, string $file)
    {
        $path = Sitemap::directory(strtolower($country)) . '/' . $file;
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'application/xml']);
    }

    private function robotsResponse(string $sitemapLine)
    {
        $slowed = implode('', array_map(fn ($bot) => "User-agent: {$bot}\n", self::SLOWED_BOTS));
        $body = "User-agent: *\nDisallow:\n\n"
            . $slowed . 'Crawl-delay: ' . self::CRAWL_DELAY . "\nDisallow:\n"
            . ($sitemapLine !== '' ? "\n" . $sitemapLine : '');

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
