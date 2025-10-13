#!/usr/bin/env php
<?php

declare(strict_types=1);

if (!defined('GALLERY_STATIC_EXPORT')) {
    define('GALLERY_STATIC_EXPORT', true);
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$appRoot = dirname(__DIR__);

require_once $appRoot . '/settings.inc.php';
require_once $appRoot . '/functions.inc.php';

$outputDir = $argv[1] ?? ($appRoot . '/build');
$outputDir = normalizePath($outputDir);

if (str_starts_with($outputDir, $appRoot) === false && !str_starts_with($appRoot, $outputDir)) {
    // ok even if outside repo; ensure absolute path
}

if (is_dir($outputDir)) {
    rrmdir($outputDir);
} elseif (file_exists($outputDir)) {
    unlink($outputDir);
}

ensureDir($outputDir);

$databasePath = normalizePath($appRoot . '/' . ltrim($database_path ?? 'gallery.db', '/\\'));
$photosDirFs = normalizePath($appRoot . '/' . trim($photos_dir, '/\\'));
$thumbnailsDirFs = normalizePath($appRoot . '/' . trim($thumbnails_dir, '/\\'));

$database = new GalleryDatabase($databasePath);
$libraryData = $database->getAllPhotos();

$GLOBALS['__gallery_root__'] = $appRoot;

echo "Rendering pages...\n";

renderHome($appRoot, $outputDir);
renderMap($appRoot, $outputDir);
renderAbout($appRoot, $outputDir);
renderViewPages($appRoot, $outputDir, $libraryData);
renderViewRedirect($outputDir);

echo "Copying assets...\n";
copyDirectory($appRoot . '/assets', $outputDir . '/assets');
copyDirectory($thumbnailsDirFs, $outputDir . '/' . trim($thumbnails_dir, '/\\'));
copyDirectory($photosDirFs, $outputDir . '/' . trim($photos_dir, '/\\'));

if (is_dir($appRoot . '/scripts')) {
    copyDirectory($appRoot . '/scripts', $outputDir . '/scripts');
}

echo "Static site exported to {$outputDir}\n";

// ---- Helper functions -----------------------------------------------------

function normalizePath(string $path): string
{
    $real = realpath($path);
    if ($real !== false) {
        return rtrim(str_replace('\\', '/', $real), '/');
    }
    if (str_starts_with($path, '/')) {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
    return rtrim(str_replace('\\', '/', getcwd() . '/' . $path), '/');
}

function renderHome(string $appRoot, string $outputDir): void
{
    $html = captureInclude($appRoot . '/pages/home.php');
    $html = rewriteForStatic($html, '');
    writeFile($outputDir . '/index.html', $html);
}

function renderMap(string $appRoot, string $outputDir): void
{
    $html = captureInclude($appRoot . '/pages/map.php');
    $html = rewriteForStatic($html, '../');
    writeFile($outputDir . '/map/index.html', $html);
}

function renderAbout(string $appRoot, string $outputDir): void
{
    $html = captureInclude($appRoot . '/pages/about.php');
    $html = rewriteForStatic($html, '../');
    writeFile($outputDir . '/about/index.html', $html);
}

/**
 * @param array<int, array<string, mixed>> $libraryData
 */
function renderViewPages(string $appRoot, string $outputDir, array $libraryData): void
{
    foreach ($libraryData as $record) {
        $id = (string) ($record['id'] ?? '');
        if ($id === '') {
            continue;
        }

        $previousGet = $_GET ?? [];
        $previousRequest = $_REQUEST ?? [];

        $_GET = ['id' => $id];
        $_REQUEST = $_GET;

        $html = captureInclude($appRoot . '/pages/view.php');
        $html = rewriteForStatic($html, '../');

        $_GET = $previousGet;
        $_REQUEST = $previousRequest;

        $safeId = rawurlencode($id);
        writeFile($outputDir . '/view/' . $safeId . '.html', $html);
    }
}

function renderViewRedirect(string $outputDir): void
{
    $redirectHtml = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Photo Viewer</title>
    <link rel="stylesheet" href="assets/styles.css">
    <script>
        (function () {
            const params = new URLSearchParams(window.location.search);
            const id = params.get('id');
            if (id) {
                const target = './' + encodeURIComponent(id) + '.html';
                window.location.replace(target);
            }
        })();
    </script>
</head>
<body>
    <main class="container">
        <h1>Photo Viewer</h1>
        <p>No photo selected. Please return to the <a href="/">gallery</a>.</p>
    </main>
</body>
</html>
HTML;

    $redirectHtml = rewriteAssetUrls($redirectHtml, '../');
    $redirectHtml = rewriteRootLinks($redirectHtml, '../');
    writeFile($outputDir . '/view/index.html', $redirectHtml);
}

function captureInclude(string $file): string
{
    ob_start();
    include $file;
    return (string) ob_get_clean();
}

function rewriteForStatic(string $html, string $relativeRoot): string
{
    $html = rewriteViewLinks($html, $relativeRoot);
    $html = rewriteAssetUrls($html, $relativeRoot);
    $html = rewriteRootLinks($html, $relativeRoot);
    return $html;
}

function rewriteViewLinks(string $html, string $relativeRoot): string
{
    return preg_replace_callback('#/view/\\?id=([A-Za-z0-9_%\\-]+)#', static function (array $matches) use ($relativeRoot): string {
        $decoded = rawurldecode($matches[1]);
        return $relativeRoot . 'view/' . rawurlencode($decoded) . '.html';
    }, $html);
}

function rewriteAssetUrls(string $html, string $relativeRoot): string
{
    $paths = ['assets', 'photos', 'originals', 'images'];
    foreach ($paths as $path) {
        $html = str_replace('="/' . $path . '/', '="' . $relativeRoot . $path . '/', $html);
        $html = str_replace("='/{$path}/", "='" . $relativeRoot . $path . '/', $html);
        $html = str_replace('(/' . $path . '/', '(' . $relativeRoot . $path . '/', $html);
        $html = str_replace('url(/' . $path . '/', 'url(' . $relativeRoot . $path . '/', $html);
        $html = str_replace('url("/' . $path . '/', 'url("' . $relativeRoot . $path . '/', $html);
        $html = str_replace("url('/{$path}/", "url('" . $relativeRoot . $path . '/', $html);
    }
    return $html;
}

function rewriteRootLinks(string $html, string $relativeRoot): string
{
    $homeTarget = ($relativeRoot === '' ? '' : $relativeRoot) . 'index.html';
    $replacements = [
        'href="/"' => 'href="' . $homeTarget . '"',
        "href='/'" => "href='" . $homeTarget . "'",
        'href="/map/"' => 'href="' . $relativeRoot . 'map/"',
        "href='/map/'" => "href='" . $relativeRoot . "map/'",
        'href="/descriptions/"' => 'href="' . $relativeRoot . 'descriptions/"',
        "href='/descriptions/'" => "href='" . $relativeRoot . "descriptions/'",
        'href="/about/"' => 'href="' . $relativeRoot . 'about/"',
        "href='/about/'" => "href='" . $relativeRoot . "about/'",
    ];

    foreach ($replacements as $search => $replace) {
        $html = str_replace($search, $replace, $html);
    }

    return $html;
}

function writeFile(string $path, string $contents): void
{
    $path = normalizePath($path);
    $dir = dirname($path);
    ensureDir($dir);
    file_put_contents($path, $contents);
}

function ensureDir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}

function copyDirectory(string $source, string $destination): void
{
    if (!is_dir($source)) {
        return;
    }

    ensureDir($destination);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $targetPath = $destination . substr($item->getPathname(), strlen($source));
        if ($item->isDir()) {
            ensureDir($targetPath);
        } else {
            ensureDir(dirname($targetPath));
            copy($item->getPathname(), $targetPath);
        }
    }
}
