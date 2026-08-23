<?php

namespace App\Service\Sitemap;

/**
 * Pure, dependency-free XML sitemap builder for the public extension snapshot.
 *
 * Used both by StaticSiteBuilder (local static build, via Composer autoload)
 * and by the standalone bin/generate-sitemap.php script (nightly deploy
 * workflow, which never runs `composer install`), so this class must not
 * depend on anything outside plain PHP.
 */
final class SitemapXmlGenerator
{
    public const SITE_BASE_URL = 'https://extensionhub.pages.dev';

    /**
     * @param array<string, mixed> $extensionsData decoded public/data/extensions.json content
     */
    public static function generate(array $extensionsData): string
    {
        $urls = [
            ['loc' => self::SITE_BASE_URL . '/'],
            ['loc' => self::SITE_BASE_URL . '/use-the-data'],
        ];

        // Snapshot v2 item paths are already the canonical rawurlencoded-UUID
        // detail URL (`/extension/{rawurlencoded UUID}`); reusing them here
        // guarantees the sitemap can never diverge from the canonical URL,
        // and automatically excludes old PK/slug URLs and unknown UUIDs.
        foreach ($extensionsData['items'] ?? [] as $item) {
            if (!is_array($item) || !isset($item['path']) || !is_string($item['path']) || $item['path'] === '') {
                continue;
            }

            $url = ['loc' => self::SITE_BASE_URL . $item['path']];

            // Static pages must not invent a lastmod; extensions get one only
            // when the snapshot actually provides updatedAt.
            if (isset($item['updatedAt']) && is_string($item['updatedAt']) && $item['updatedAt'] !== '') {
                $url['lastmod'] = $item['updatedAt'];
            }

            $urls[] = $url;
        }

        return self::render($urls);
    }

    /**
     * @param array<int, array{loc: string, lastmod?: string}> $urls
     */
    private static function render(array $urls): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($urls as $url) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . self::escape($url['loc']) . '</loc>';
            if (isset($url['lastmod'])) {
                $lines[] = '    <lastmod>' . self::escape($url['lastmod']) . '</lastmod>';
            }
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines) . "\n";
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
