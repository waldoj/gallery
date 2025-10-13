<?php

declare(strict_types=1);

/**
 * Renders a single photo and its metadata using $_REQUEST['id'] as the unique identifier.
 */

use Symfony\Component\Yaml\Yaml;

$appRoot = $GLOBALS['__gallery_root__'] ?? dirname(__DIR__);

require $appRoot . '/settings.inc.php';
require_once $appRoot . '/functions.inc.php';

$photoId = isset($_REQUEST['id']) ? basename((string) $_REQUEST['id']) : null;

if ($photoId === null || $photoId === '') {
    http_response_code(400);
    die('Missing required photo id.');
}

$photosDirFs = rtrim($appRoot, '/\\') . '/' . trim($photos_dir, '/\\') . '/';
$thumbnailsDirFs = rtrim($appRoot, '/\\') . '/' . trim($thumbnails_dir, '/\\') . '/';
$databasePath = rtrim($appRoot, '/\\') . '/' . ltrim($database_path ?? 'gallery.db', '/\\');

try {
    $database = new GalleryDatabase($databasePath);
    $photoRow = $database->getPhotoById($photoId);
    $exifData = $database->getExifByPhotoId($photoId);
} catch (Throwable $throwable) {
    $photoRow = null;
    $exifData = [];
}

if ($photoRow === null) {
    http_response_code(404);
    die('Photo not found in the library.');
}

/**
 * @param mixed $value
 */
$exifValueToString = static function ($value): string {
    if ($value === null) {
        return '';
    }

    if (is_array($value)) {
        $parts = [];
        foreach ($value as $piece) {
            if (is_scalar($piece)) {
                $part = trim((string) $piece);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
        }
        return trim(implode(' ', $parts));
    }

    if (is_scalar($value)) {
        return trim((string) $value);
    }

    return '';
};

$filename = $photoRow['filename'] ?? null;

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
$photoIdString = (string) ($photoRow['id'] ?? $photoId);

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

$title = $photoRow['title'] !== '' ? $photoRow['title'] : pathinfo($filename, PATHINFO_FILENAME);
$description = $photoRow['description'] ?? '';
$dateTaken = $photoRow['date_taken'] ?? '';
$width = $photoRow['width'] ?? null;
$height = $photoRow['height'] ?? null;
$author = $photoRow['author'] ?? 'Waldo Jaquith';
$license = $photoRow['license'] ?? 'CC BY-NC-SA 4.0';

$libraryMetadata = null;
$libraryMetadataPath = $appRoot . '/library.yml';
if (is_file($libraryMetadataPath) && is_readable($libraryMetadataPath)) {
    try {
        $parsedLibrary = Yaml::parseFile($libraryMetadataPath);
        if (is_array($parsedLibrary) && isset($parsedLibrary[$photoIdString]) && is_array($parsedLibrary[$photoIdString])) {
            $libraryMetadata = $parsedLibrary[$photoIdString];
        }
    } catch (Throwable $libraryException) {
        $libraryMetadata = null;
    }
}

if (is_array($libraryMetadata)) {
    if (isset($libraryMetadata['title']) && is_string($libraryMetadata['title']) && trim($libraryMetadata['title']) !== '') {
        $title = trim($libraryMetadata['title']);
    }
    if (isset($libraryMetadata['description']) && is_string($libraryMetadata['description']) && trim($libraryMetadata['description']) !== '') {
        $description = trim($libraryMetadata['description']);
    }
    if (isset($libraryMetadata['date_taken']) && is_string($libraryMetadata['date_taken']) && trim($libraryMetadata['date_taken']) !== '') {
        $dateTaken = trim($libraryMetadata['date_taken']);
    }
    if (isset($libraryMetadata['width']) && $libraryMetadata['width'] !== null) {
        $widthCandidate = is_numeric($libraryMetadata['width']) ? (int) $libraryMetadata['width'] : null;
        if ($widthCandidate !== null) {
            $width = $widthCandidate;
        }
    }
    if (isset($libraryMetadata['height']) && $libraryMetadata['height'] !== null) {
        $heightCandidate = is_numeric($libraryMetadata['height']) ? (int) $libraryMetadata['height'] : null;
        if ($heightCandidate !== null) {
            $height = $heightCandidate;
        }
    }
    if (isset($libraryMetadata['author']) && is_string($libraryMetadata['author']) && trim($libraryMetadata['author']) !== '') {
        $author = trim($libraryMetadata['author']);
    }
    if (isset($libraryMetadata['license']) && is_string($libraryMetadata['license']) && trim($libraryMetadata['license']) !== '') {
        $license = trim($libraryMetadata['license']);
    }
}

$cameraMake = $exifValueToString($exifData['Make'] ?? null);
$cameraModel = $exifValueToString($exifData['Model'] ?? null);
$cameraLabel = null;

if ($cameraMake !== '' && $cameraModel !== '') {
    if (stripos($cameraModel, $cameraMake) !== false) {
        $cameraLabel = $cameraModel;
    } else {
        $cameraLabel = $cameraMake . ' ' . $cameraModel;
    }
} elseif ($cameraModel !== '') {
    $cameraLabel = $cameraModel;
} elseif ($cameraMake !== '') {
    $cameraLabel = $cameraMake;
}

$mapLat = isset($photoRow['gps_latitude']) ? (float) $photoRow['gps_latitude'] : null;
$mapLon = isset($photoRow['gps_longitude']) ? (float) $photoRow['gps_longitude'] : null;
$mapDirectionAngle = null;
$mapDirectionLabel = null;
$mapLinkUrl = null;
$defaultMapLat = 38.030727;
$defaultMapLon = -78.481511;

if (isset($photoRow['gps_img_direction'])) {
    $directionAngle = (float) $photoRow['gps_img_direction'];
    $directionAngle = fmod($directionAngle, 360.0);
    if ($directionAngle < 0) {
        $directionAngle += 360.0;
    }
    $compassPoints = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
    $index = ((int) round($directionAngle / 45)) % 8;
    if ($index < 0) {
        $index += 8;
    }
    $mapDirectionLabel = $compassPoints[$index];
    $mapDirectionAngle = $index * 45;
}

if ($mapLat !== null && $mapLon !== null) {
    $mapLinkUrl = sprintf(
        'https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=%d/%s/%s',
        rawurlencode(number_format($mapLat, 6, '.', '')),
        rawurlencode(number_format($mapLon, 6, '.', '')),
        16,
        rawurlencode(number_format($mapLat, 6, '.', '')),
        rawurlencode(number_format($mapLon, 6, '.', ''))
    );
}

$detailFields = [
    'Width' => $width,
    'Height' => $height,
    'Author' => $author,
];

if ($cameraLabel !== null) {
    $detailFields['Camera'] = $cameraLabel;
}

$detailFields['License'] = $license;

$renderer = new GalleryTemplateRenderer();

echo $renderer->render('view.html.twig', [
    'title' => $title,
    'description' => $description,
    'date_taken' => $dateTaken,
    'photo_path' => $photoUrl,
    'photo_id' => $photoIdString,
    'raw_description' => $description,
    'detail_fields' => $detailFields,
    'download_url' => $downloadUrl,
    'map_lat' => $mapLat,
    'map_lon' => $mapLon,
    'map_direction_angle' => $mapDirectionAngle,
    'map_direction_label' => $mapDirectionLabel,
    'map_link_url' => $mapLinkUrl,
    'default_latitude' => $defaultMapLat,
    'default_longitude' => $defaultMapLon,
    'exif' => $exifData,
    'photo_metadata_url' => gallery_public_url_path('photo-metadata'),
    'show_editor' => !gallery_is_static_export(),
]);
