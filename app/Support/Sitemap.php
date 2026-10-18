<?php

namespace App\Support;

/**
 * XML for the per-country sitemaps, stored under storage/app/sitemaps/{país}/ and served on
 * autos.roodos.{país}/sitemap.xml.
 */
class Sitemap
{
    public static function directory(string $countryCode): string
    {
        return storage_path('app/sitemaps/' . $countryCode);
    }

    public static function baseUrl(string $countryCode): string
    {
        return 'https://autos.roodos.' . $countryCode;
    }

    public static function landingUrl(string $countryCode, string $slug): string
    {
        $path = implode('/', array_map('rawurlencode', explode('/', ltrim($slug, '/'))));

        return self::baseUrl($countryCode) . '/' . $path;
    }

    public static function urlset(array $urls): string
    {
        $entries = array_map(fn ($url) => '  <url><loc>' . htmlspecialchars($url, ENT_XML1) . '</loc></url>', $urls);

        return self::document('urlset', $entries);
    }

    public static function index(array $sitemapUrls): string
    {
        $entries = array_map(fn ($url) => '  <sitemap><loc>' . htmlspecialchars($url, ENT_XML1) . '</loc></sitemap>', $sitemapUrls);

        return self::document('sitemapindex', $entries);
    }

    private static function document(string $root, array $entries): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . "<{$root} xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            . implode("\n", $entries) . ($entries ? "\n" : '')
            . "</{$root}>\n";
    }
}
