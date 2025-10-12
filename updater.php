<?php

/**
 * Updater
 */

// Only permit this to run at the CLI
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

$appRoot = __DIR__;
$appRootNormalized = rtrim(str_replace('\\', '/', $appRoot), '/');

require_once $appRoot . '/settings.inc.php';
require_once $appRoot . '/functions.inc.php';

$libraryPath = $appRootNormalized . '/' . ltrim($library, '/\\');
$photosDirFs = $appRootNormalized . '/' . trim($photos_dir, '/\\');
$thumbnailsDirFs = $appRootNormalized . '/' . trim($thumbnails_dir, '/\\');

$timestamp = time();
$backupDir = $appRoot . '/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

if (is_file($libraryPath)) {
    $backupPath = $backupDir . '/library-' . $timestamp . '.yml';
    if (!@copy($libraryPath, $backupPath)) {
        fwrite(STDERR, "Warning: Failed to create backup at {$backupPath}\n");
    }
}
$libraryManager = new GalleryLibraryManager($libraryPath, $photosDirFs, $thumbnailsDirFs, $sizes);

$result = $libraryManager->sync();
$libraryData = $result['library'];
$duplicates = $result['duplicates'];
$thumbnailsMissing = $result['thumbnails_missing'] ?? [];

$libraryManager->save($libraryData);

if (!empty($duplicates)) {
    echo "Duplicate photos detected:\n";
    foreach ($duplicates as $dup) {
        echo sprintf("- %s (hash: %s)\n", $dup['filename'], $dup['hash']);
    }
}

echo "Library updated.\n";
