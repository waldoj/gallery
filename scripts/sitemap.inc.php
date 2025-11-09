<?php

declare(strict_types=1);

/**
 * @param array<int, array<string, mixed>> $photoRecords
 * @param array<string, string|float|int|null> $staticRoutes
 */
function gallery_generate_sitemap(array $photoRecords, string $outputDir, string $baseUrl, array $staticRoutes = []): bool
{
    $baseUrl = trim($baseUrl);
    if ($baseUrl === '') {
        fwrite(STDERR, "Skipping sitemap generation: base URL is empty.\n");
        return false;
    }

    $baseUrl = rtrim($baseUrl, '/');
    $outputDir = rtrim($outputDir, '/\\');
    $urls = [];

    $appendUrl = static function (string $path, ?string $priority) use (&$urls, $baseUrl): void {
        $normalizedPath = $path === '/' ? '/' : '/' . ltrim($path, '/');
        $fullUrl = $baseUrl . $normalizedPath;
        if (isset($urls[$fullUrl])) {
            return;
        }
        $entry = ['loc' => $fullUrl];
        if ($priority !== null && $priority !== '') {
            $entry['priority'] = $priority;
        }
        $urls[$fullUrl] = $entry;
    };

    if (empty($staticRoutes)) {
        $staticRoutes = [
            '/' => '1.0',
            '/map/' => '0.8',
            '/about/' => '0.6',
        ];
    }

    foreach ($staticRoutes as $route => $priority) {
        $priorityString = null;
        if (is_string($priority) || is_numeric($priority)) {
            $priorityString = (string) $priority;
        }
        $appendUrl($route, $priorityString);
    }

    foreach ($photoRecords as $record) {
        $id = isset($record['id']) ? (string) $record['id'] : '';
        if ($id === '') {
            continue;
        }
        $appendUrl('/view/' . rawurlencode($id) . '.html', '0.7');
    }

    if (empty($urls)) {
        fwrite(STDERR, "Skipping sitemap generation: no URLs to write.\n");
        return false;
    }

    if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
        throw new RuntimeException('Unable to create directory for sitemap: ' . $outputDir);
    }

    $xmlLines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
    ];

    foreach ($urls as $entry) {
        $xmlLines[] = '  <url>';
        $xmlLines[] = '    <loc>' . htmlspecialchars($entry['loc'], ENT_XML1) . '</loc>';
        if (isset($entry['priority'])) {
            $xmlLines[] = '    <priority>' . htmlspecialchars($entry['priority'], ENT_XML1) . '</priority>';
        }
        $xmlLines[] = '  </url>';
    }

    $xmlLines[] = '</urlset>';
    $sitemapPath = $outputDir . '/sitemap.xml';
    file_put_contents($sitemapPath, implode(PHP_EOL, $xmlLines) . PHP_EOL);

    return true;
}
