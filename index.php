<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.inc.php';
require_once __DIR__ . '/functions.inc.php';

// Load all photo metadata via the library manager so we can render the gallery.
$libraryManager = new GalleryLibraryManager($library, $photos_dir, $thumbnails_dir, $sizes);
$libraryData = $libraryManager->load();

$photos = [];

// Walk each record and pick out the information needed for the gallery grid.
foreach ($libraryData as $photoId => $metadata) {
    if (!is_array($metadata)) {
        continue;
    }

    $photoIdString = (string) $photoId;

    $filename = $metadata['filename'] ?? null;
    if ($filename === null) {
        continue;
    }

    $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    $extensionSuffix = $extension !== '' ? '.' . $extension : '';

    $idForFile = (string) ($metadata['id'] ?? $photoIdString);
    $thumbnailPath = null;

    // Prefer a "thumbnail" derivative, then fall back to any other generated sizes.
    $preferredSizes = [];
    if (array_key_exists('thumbnail', $sizes)) {
        $preferredSizes[] = 'thumbnail';
    }
    foreach (array_keys($sizes) as $sizeName) {
        if (!in_array($sizeName, $preferredSizes, true)) {
            $preferredSizes[] = $sizeName;
        }
    }

    foreach ($preferredSizes as $sizeName) {
        $candidate = $thumbnails_dir . $idForFile . '_' . preg_replace('/[^a-z0-9_\-]/i', '', (string) $sizeName) . $extensionSuffix;
        if ($candidate !== '' && is_file($candidate)) {
            $thumbnailPath = $candidate;
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
    if ($thumbnailPath === null || !is_file($thumbnailPath)) {
        $thumbnailPath = $photos_dir . $filename;
    }

    if (!is_file($thumbnailPath)) {
        continue;
    }

    // Collect the fields the template needs to display each card.
    $title = isset($metadata['title']) && $metadata['title'] !== '' ? $metadata['title'] : 'Untitled';
    $dateTaken = isset($metadata['date_taken']) && $metadata['date_taken'] !== '' ? $metadata['date_taken'] : 'Unknown date';

    $photos[] = [
        'id' => $idForFile,
        'photo_id' => $photoIdString,
        'title' => $title,
        'date_taken' => $dateTaken,
        'thumbnail_path' => $thumbnailPath,
        'link' => 'view.php?id=' . rawurlencode($photoIdString),
    ];
}

// Render the gallery using Twig for layout/styling.
$renderer = new GalleryTemplateRenderer();

echo $renderer->render('index.html.twig', [
    'photos' => $photos,
]);
