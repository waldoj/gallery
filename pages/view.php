<?php

declare(strict_types=1);

/**
 * Renders a single photo and its metadata using $_REQUEST['id'] as the unique identifier.
 */

$appRoot = $GLOBALS['__gallery_root__'] ?? dirname(__DIR__);

require $appRoot . '/settings.inc.php';
require_once $appRoot . '/functions.inc.php';

$photoId = isset($_REQUEST['id']) ? basename((string) $_REQUEST['id']) : null;

if ($photoId === null || $photoId === '') {
    http_response_code(400);
    die('Missing required photo id.');
}

$libraryPath = rtrim($appRoot, '/\\') . '/' . ltrim($library, '/\\');
$photosDirFs = rtrim($appRoot, '/\\') . '/' . trim($photos_dir, '/\\') . '/';
$thumbnailsDirFs = rtrim($appRoot, '/\\') . '/' . trim($thumbnails_dir, '/\\') . '/';

$libraryManager = new GalleryLibraryManager($libraryPath, $photosDirFs, $thumbnailsDirFs, $sizes);
$libraryData = $libraryManager->load();

if (!isset($libraryData[$photoId]) || !is_array($libraryData[$photoId])) {
    http_response_code(404);
    die('Photo not found in the library.');
}

$photoMetadata = $libraryData[$photoId];
$filename = $photoMetadata['filename'] ?? null;

if ($filename === null) {
    http_response_code(404);
    die('Photo file not found.');
}

$originalPath = $photos_dir . $filename;
$originalFsPath = $photosDirFs . $filename;
if (!is_file($originalFsPath)) {
    http_response_code(404);
    die('Photo file not found.');
}

$extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
$extensionSuffix = $extension !== '' ? '.' . $extension : '';
$photoIdString = (string) ($photoMetadata['id'] ?? $photoId);

$preferredSizes = ['large', 'medium', 'thumbnail'];
$displayPath = null;

foreach ($preferredSizes as $sizeName) {
    $candidateFs = $thumbnailsDirFs . $photoIdString . '_' . $sizeName . $extensionSuffix;
    if (is_file($candidateFs)) {
        $displayPath = $thumbnails_dir . $photoIdString . '_' . $sizeName . $extensionSuffix;
        break;
    }
}

if ($displayPath === null) {
    foreach ($sizes as $sizeName => $_) {
        $cleanName = preg_replace('/[^a-z0-9_\-]/i', '', (string) $sizeName);
        $candidateFs = $thumbnailsDirFs . $photoIdString . '_' . $cleanName . $extensionSuffix;
        if (is_file($candidateFs)) {
            $displayPath = $thumbnails_dir . $photoIdString . '_' . $cleanName . $extensionSuffix;
            break;
        }
    }
}

$photoPath = $displayPath ?? $originalPath;
$photoUrl = gallery_public_url_path($photoPath);
$downloadUrl = gallery_public_url_path($originalPath);

$title = $photoMetadata['title'] ?? pathinfo($filename, PATHINFO_FILENAME);
$description = $photoMetadata['description'] ?? '';
$dateTaken = $photoMetadata['date_taken'] ?? '';

$exifData = isset($photoMetadata['exif']) && is_array($photoMetadata['exif'])
    ? $photoMetadata['exif']
    : [];

$mapCoordinates = GalleryExifHelper::extractGpsCoordinates($exifData);
$mapLat = null;
$mapLon = null;
$mapDirectionAngle = null;
$mapDirectionLabel = null;
$mapLinkUrl = null;

$directionAngle = null;
if (isset($exifData['GPSImgDirection'])) {
    $directionAngle = GalleryExifHelper::fractionToFloat($exifData['GPSImgDirection']);
    if ($directionAngle !== null) {
        $directionAngle = fmod($directionAngle, 360.0);
        if ($directionAngle < 0) {
            $directionAngle += 360.0;
        }
    }
}

if ($mapCoordinates !== null) {
    $mapLat = $mapCoordinates['latitude'];
    $mapLon = $mapCoordinates['longitude'];

    if ($directionAngle !== null) {
        $compassPoints = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        $index = ((int) round($directionAngle / 45)) % 8;
        if ($index < 0) {
            $index += 8;
        }
        $mapDirectionLabel = $compassPoints[$index];
        $mapDirectionAngle = $index * 45;
    }

    $mapLinkUrl = sprintf(
        'https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=%d/%s/%s',
        rawurlencode(number_format($mapLat, 6, '.', '')),
        rawurlencode(number_format($mapLon, 6, '.', '')),
        16,
        rawurlencode(number_format($mapLat, 6, '.', '')),
        rawurlencode(number_format($mapLon, 6, '.', ''))
    );
}

$detailFields = array_diff_key(
    $photoMetadata,
    array_flip(['title', 'description', 'date_taken', 'filename', 'exif', 'id'])
);

$renderer = new GalleryTemplateRenderer();

echo $renderer->render('view.html.twig', [
    'title' => $title,
    'description' => $description,
    'date_taken' => $dateTaken,
    'photo_path' => $photoUrl,
    'detail_fields' => $detailFields,
    'download_url' => $downloadUrl,
    'map_lat' => $mapLat,
    'map_lon' => $mapLon,
    'map_direction_angle' => $mapDirectionAngle,
    'map_direction_label' => $mapDirectionLabel,
    'map_link_url' => $mapLinkUrl,
    'exif' => $exifData,
]);
