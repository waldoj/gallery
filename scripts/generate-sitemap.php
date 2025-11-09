#!/usr/bin/env php
<?php

declare(strict_types=1);

$appRoot = dirname(__DIR__);

require_once $appRoot . '/settings.inc.php';
require_once $appRoot . '/functions.inc.php';
require_once __DIR__ . '/sitemap.inc.php';

$outputDir = $argv[1] ?? ($appRoot . '/build');
$baseUrl = $argv[2] ?? ($site_base_url ?? '');
$baseUrl = trim((string) $baseUrl);

$databasePath = rtrim($appRoot, '/\\') . '/' . ltrim($database_path ?? 'gallery.db', '/\\');

try {
    $database = new GalleryDatabase($databasePath);
    $photoRecords = $database->getAllPhotos();
} catch (Throwable $throwable) {
    fwrite(STDERR, "Failed to read photo data for sitemap: " . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

$staticRoutes = [
    '/' => '1.0',
    '/map/' => '0.8',
    '/about/' => '0.6',
];

if (!gallery_generate_sitemap($photoRecords, $outputDir, $baseUrl, $staticRoutes)) {
    exit(1);
}

echo "Sitemap written to " . rtrim($outputDir, '/\\') . "/sitemap.xml\n";
