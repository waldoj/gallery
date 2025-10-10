<?php

/**
 * Updater
 */

// Only permit this to run at the CLI
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

require_once __DIR__ . '/settings.inc.php';
require_once __DIR__ . '/functions.inc.php';

$timestamp = time();
$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

if (is_file($library)) {
    $backupPath = $backupDir . '/library-' . $timestamp . '.yml';
    if (!@copy($library, $backupPath)) {
        fwrite(STDERR, "Warning: Failed to create backup at {$backupPath}\n");
    }
}


$libraryManager = new GalleryLibraryManager($library, $photos_dir, $thumbnails_dir, $sizes);
$result = $libraryManager->sync();
$libraryData = $result['library'];
$duplicates = $result['duplicates'];

$libraryManager->save($libraryData);

if (!empty($duplicates)) {
    echo "Duplicate photos detected:\n";
    foreach ($duplicates as $dup) {
        echo sprintf("- %s (hash: %s)\n", $dup['filename'], $dup['hash']);
    }
}

echo "Library updated.\n";
