<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Guides written in Markdown under content/guias/{país}/{slug}.md. Each country has its own
 * articles (they are not translations of each other), so there is no fallback to another country.
 * A guide whose published_at is still in the future stays hidden until that day.
 *
 * The front matter is plain "key: value" lines, with JSON-style quotes when a value needs them:
 * symfony/yaml is only a dev dependency here.
 */
class Guides
{
    public static function directory(string $countryCode): string
    {
        return base_path('content/guias/' . $countryCode);
    }

    /**
     * The country's published guides, newest first, without their body.
     *
     * @return array<int, array<string, string>>
     */
    public static function all(string $countryCode): array
    {
        $guides = array_map(fn ($file) => self::read($file)[0], glob(self::directory($countryCode) . '/*.md') ?: []);
        $guides = array_values(array_filter($guides, [self::class, 'isPublished']));
        usort($guides, fn ($a, $b) => strcmp($b['published_at'], $a['published_at']));

        return $guides;
    }

    /**
     * A guide with its body rendered to HTML as "html", or null when the country does not have it.
     */
    public static function find(string $countryCode, string $slug): ?array
    {
        $file = self::directory($countryCode) . '/' . $slug . '.md';
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || !is_file($file)) {
            return null;
        }

        [$guide, $markdown] = self::read($file);
        if (!self::isPublished($guide)) {
            return null;
        }
        $guide['html'] = (string) Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        return $guide;
    }

    private static function isPublished(array $guide): bool
    {
        return $guide['published_at'] <= now()->toDateString();
    }

    /**
     * @return array{0: array<string, string>, 1: string} front matter and Markdown body
     */
    private static function read(string $file): array
    {
        [, $frontMatter, $markdown] = preg_split('/^---$/m', file_get_contents($file), 3);

        $guide = [];
        foreach (preg_split('/\R/', trim($frontMatter)) as $line) {
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $guide[$key] = str_starts_with($value, '"') ? json_decode($value) : $value;
        }

        return [$guide, $markdown];
    }
}
