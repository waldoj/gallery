<?php

declare(strict_types=1);

$appRoot = $GLOBALS['__gallery_root__'] ?? dirname(__DIR__);

require $appRoot . '/settings.inc.php';
require_once $appRoot . '/functions.inc.php';

// Resolve filesystem paths for the photo and thumbnail directories.
$photosDirFs = rtrim($appRoot, '/\\') . '/' . trim($photos_dir, '/\\') . '/';
$thumbnailsDirFs = rtrim($appRoot, '/\\') . '/' . trim($thumbnails_dir, '/\\') . '/';

$databasePath = rtrim($appRoot, '/\\') . '/' . ltrim($database_path ?? 'gallery.db', '/\\');

try {
    $database = new GalleryDatabase($databasePath);
    $libraryData = $database->getAllPhotos();
} catch (Throwable $throwable) {
    $libraryData = [];
}

$photos = [];

foreach ($libraryData as $record) {
    $photoIdString = (string) ($record['id'] ?? '');
    if ($photoIdString === '') {
        continue;
    }

    $filename = $record['filename'] ?? null;
    if ($filename === null) {
        continue;
    }

    $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    $extensionSuffix = $extension !== '' ? '.' . $extension : '';

    $idForFile = $photoIdString;
    $thumbnailPath = null;

    // Prefer a "thumbsquare" or "thumbnail" derivative, then fall back to other sizes.
    $preferredSizes = [];
    if (array_key_exists('thumbsquare', $sizes)) {
        $preferredSizes[] = 'thumbsquare';
    }
    if (array_key_exists('thumbnail', $sizes)) {
        $preferredSizes[] = 'thumbnail';
    }
    foreach (array_keys($sizes) as $sizeName) {
        if (!in_array($sizeName, $preferredSizes, true)) {
            $preferredSizes[] = $sizeName;
        }
    }

    foreach ($preferredSizes as $sizeName) {
        $cleanName = preg_replace('/[^a-z0-9_\-]/i', '', (string) $sizeName);
        $candidateFs = $thumbnailsDirFs . $idForFile . '_' . $cleanName . $extensionSuffix;
        if (is_file($candidateFs)) {
            $thumbnailPath = $thumbnails_dir . $idForFile . '_' . $cleanName . $extensionSuffix;
            break;
        }
    }

    if ($thumbnailPath === null) {
        $globMatches = glob($thumbnails_dir . $idForFile . '_*');
        if ($globMatches !== false && !empty($globMatches)) {
            $thumbnailPath = $globMatches[0];
        }
    }

    // As a last resort, fall back to the original image if no derivative exists.
    if ($thumbnailPath === null || !is_file($thumbnailsDirFs . basename($thumbnailPath ?: ''))) {
        $thumbnailPath = $photos_dir . $filename;
    }

    if (!is_file($photosDirFs . $filename) && !is_file($thumbnailsDirFs . basename($thumbnailPath))) {
        continue;
    }

    // Collect the fields the template needs to display each card.
    $title = isset($record['title']) && $record['title'] !== '' ? $record['title'] : 'Untitled';
    $dateTaken = isset($record['date_taken']) && $record['date_taken'] !== '' ? $record['date_taken'] : 'Unknown date';

    $photos[] = [
        'id' => $idForFile,
        'photo_id' => $photoIdString,
        'title' => $title,
        'date_taken' => $dateTaken,
        'thumbnail_path' => gallery_public_url_path((string) $thumbnailPath),
        'link' => gallery_public_url_path('/view/?id=' . rawurlencode($photoIdString)),
    ];
}

// Render the gallery using Twig for layout/styling.
$renderer = new GalleryTemplateRenderer();

echo $renderer->render('index.html.twig', [
    'photos' => $photos,
]);
