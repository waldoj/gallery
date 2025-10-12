<?php

declare(strict_types=1);

$appRoot = $GLOBALS['__gallery_root__'] ?? dirname(__DIR__);

require $appRoot . '/settings.inc.php';
require_once $appRoot . '/functions.inc.php';

$libraryPath = rtrim($appRoot, '/\\') . '/' . ltrim($library, '/\\');
$photosDirFs = rtrim($appRoot, '/\\') . '/' . trim($photos_dir, '/\\') . '/';
$thumbnailsDirFs = rtrim($appRoot, '/\\') . '/' . trim($thumbnails_dir, '/\\') . '/';

$libraryManager = new GalleryLibraryManager($libraryPath, $photosDirFs, $thumbnailsDirFs, $sizes);
$libraryData = $libraryManager->load();

$renderer = new GalleryTemplateRenderer();
$menuHtml = $renderer->render('_menu.html.twig', []);

$photosWithLocation = [];

foreach ($libraryData as $photoId => $metadata) {
    if (!is_array($metadata) || !isset($metadata['exif']['GPSLatitude'], $metadata['exif']['GPSLongitude'])) {
        continue;
    }

    $latRaw = $metadata['exif']['GPSLatitude'];
    $latRef = $metadata['exif']['GPSLatitudeRef'] ?? 'N';
    $lonRaw = $metadata['exif']['GPSLongitude'];
    $lonRef = $metadata['exif']['GPSLongitudeRef'] ?? 'E';

    if (!is_array($latRaw) || !is_array($lonRaw)) {
        continue;
    }

    $latitude = GalleryExifHelper::coordinateToDecimal($latRaw, (string)$latRef);
    $longitude = GalleryExifHelper::coordinateToDecimal($lonRaw, (string)$lonRef);

    if ($latitude === null || $longitude === null) {
        continue;
    }

    $photoIdString = (string) ($metadata['id'] ?? $photoId);

    $extension = strtolower((string) pathinfo($metadata['filename'] ?? '', PATHINFO_EXTENSION));
    $extensionSuffix = $extension !== '' ? '.' . $extension : '';

    $thumbnailPath = null;
    $preferredSizes = ['thumbnail'];
    foreach ($sizes as $sizeName => $_) {
        if (!in_array($sizeName, $preferredSizes, true)) {
            $preferredSizes[] = $sizeName;
        }
    }

    foreach ($preferredSizes as $sizeName) {
        $cleanName = preg_replace('/[^a-z0-9_\-]/i', '', (string) $sizeName);
        $candidateFs = $thumbnailsDirFs . $photoIdString . '_' . $cleanName . $extensionSuffix;
        if (is_file($candidateFs)) {
            $thumbnailPath = $thumbnails_dir . $photoIdString . '_' . $cleanName . $extensionSuffix;
            break;
        }
    }

    $thumbnailUrl = $thumbnailPath !== null ? gallery_public_url_path($thumbnailPath) : null;

    $photosWithLocation[] = [
        'id' => $photoIdString,
        'title' => $metadata['title'] ?? 'Untitled',
        'lat' => $latitude,
        'lon' => $longitude,
        'url' => gallery_public_url_path('/view/?id=' . rawurlencode((string)$photoId)),
        'thumb' => $thumbnailUrl,
    ];
}

$stylesUrl = gallery_public_url_path('/assets/styles.css');
$leafletCssUrl = gallery_public_url_path('/assets/vendor/leaflet/leaflet.css');
$leafletJsUrl = gallery_public_url_path('/assets/vendor/leaflet/leaflet.js');
$homeUrl = gallery_public_url_path('/');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Photo Map</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($stylesUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($leafletCssUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        body {
            padding: 0;
        }
        #map-wrapper {
            max-width: 960px;
            margin: 0 auto;
            padding: 20px;
        }
        #photo-map {
            width: 100%;
            height: 80vh;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        @media (max-width: 768px) {
            #map-wrapper {
                padding: 16px;
            }
            #photo-map {
                height: 60vh;
            }
        }
        @media (max-width: 480px) {
            #map-wrapper {
                padding: 12px;
            }
            #photo-map {
                height: 55vh;
            }
        }
    </style>
</head>
<body>
    <?php echo $menuHtml; ?>
    <div id="map-wrapper">
        <h1>Photo Map</h1>
        <p><a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>">← Back to gallery</a></p>
        <div id="photo-map"></div>
    </div>

    <script src="<?= htmlspecialchars($leafletJsUrl, ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script>
        const photos = <?php echo json_encode($photosWithLocation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

        if (photos.length === 0) {
            document.getElementById('photo-map').innerHTML = '<p>No geolocated photos found.</p>';
        } else {
            const map = L.map('photo-map');

            const bounds = [];
            photos.forEach(photo => {
                bounds.push([photo.lat, photo.lon]);
            });

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors'
            }).addTo(map);

            bounds.forEach((coord, index) => {
                const photo = photos[index];
                const thumbHtml = photo.thumb
                    ? `<img src="${photo.thumb}" alt="${photo.title}" style="max-width:120px;height:auto;display:block;margin-bottom:8px;">`
                    : '';

                L.marker(coord)
                    .addTo(map)
                    .bindPopup(
                        `${thumbHtml}<a href="${photo.url}">${photo.title}</a>`
                    );
            });

            if (bounds.length === 1) {
                map.setView(bounds[0], 15);
            } else {
                map.fitBounds(bounds, { padding: [40, 40] });
            }
        }
    </script>
</body>
</html>
