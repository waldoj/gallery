<?php

declare(strict_types=1);

$appRoot = $GLOBALS['__gallery_root__'] ?? dirname(__DIR__);

require $appRoot . '/settings.inc.php';
require_once $appRoot . '/functions.inc.php';

$photosDirFs = rtrim($appRoot, '/\\') . '/' . trim($photos_dir, '/\\') . '/';
$thumbnailsDirFs = rtrim($appRoot, '/\\') . '/' . trim($thumbnails_dir, '/\\') . '/';
$databasePath = rtrim($appRoot, '/\\') . '/' . ltrim($database_path ?? 'gallery.db', '/\\');

try {
    $database = new GalleryDatabase($databasePath);
    $libraryData = $database->getPhotosWithLocation();
} catch (Throwable $throwable) {
    $libraryData = [];
}

$renderer = new GalleryTemplateRenderer();
$menuHtml = $renderer->render('_menu.html.twig', []);

$photosWithLocation = [];

foreach ($libraryData as $record) {
    $photoIdString = (string) ($record['id'] ?? '');
    if ($photoIdString === '') {
        continue;
    }

    $latitude = isset($record['gps_latitude']) ? (float) $record['gps_latitude'] : null;
    $longitude = isset($record['gps_longitude']) ? (float) $record['gps_longitude'] : null;
    if ($latitude === null || $longitude === null) {
        continue;
    }

    $extension = strtolower((string) pathinfo($record['filename'] ?? '', PATHINFO_EXTENSION));
    $extensionSuffix = $extension !== '' ? '.' . $extension : '';

    $thumbnailPath = null;
    $preferredSizes = [];
    if (array_key_exists('thumbsquare', $sizes)) {
        $preferredSizes[] = 'thumbsquare';
    }
    $preferredSizes[] = 'thumbnail';
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
        'title' => $record['title'] ?? 'Untitled',
        'lat' => $latitude,
        'lon' => $longitude,
        'url' => gallery_public_url_path('/view/?id=' . rawurlencode($photoIdString)),
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
    <title>Charlottesville Photos Map</title>
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
        <h1>Charlottesville Photos Map</h1>
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
                    ? `<a href="${photo.url}"><img src="${photo.thumb}" alt="${photo.title}" style="width:100px;height:100px;object-fit:cover;display:block;margin: 0 auto 8px auto;border-radius:4px;"></a>`
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
