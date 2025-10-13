<?php

declare(strict_types=1);

/**
 * Lightweight test harness for the gallery library classes.
 */

error_reporting(E_ALL);

require_once __DIR__ . '/../functions.inc.php';

class TestFailure extends Exception
{
}

function assertTrue(mixed $condition, string $message = ''): void
{
    if (!$condition) {
        throw new TestFailure($message !== '' ? $message : 'Failed asserting that condition is true.');
    }
}

function assertFalse(mixed $condition, string $message = ''): void
{
    if ($condition) {
        throw new TestFailure($message !== '' ? $message : 'Failed asserting that condition is false.');
    }
}

function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $baseMessage = sprintf(
            'Failed asserting that %s matches expected %s.',
            var_export($actual, true),
            var_export($expected, true)
        );
        throw new TestFailure($message !== '' ? $message . ' ' . $baseMessage : $baseMessage);
    }
}

function assertArrayHasKey(mixed $key, array $array, string $message = ''): void
{
    if (!array_key_exists($key, $array)) {
        $baseMessage = sprintf('Failed asserting that array has key %s.', var_export($key, true));
        throw new TestFailure($message !== '' ? $message . ' ' . $baseMessage : $baseMessage);
    }
}

function fail(string $message): void
{
    throw new TestFailure($message);
}

function with_temp_dir(callable $callback): void
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gallery_test_' . uniqid('', true);
    if (!mkdir($base, 0700, true)) {
        throw new RuntimeException('Unable to create temporary directory: ' . $base);
    }

    try {
        $callback($base);
    } finally {
        rrmdir($base);
    }
}

function create_test_database(string $path, array $photos, array $exif = []): void
{
    if (is_file($path)) {
        unlink($path);
    }

    $db = new SQLite3($path);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec(<<<'SQL'
CREATE TABLE photos (
    id TEXT PRIMARY KEY,
    filename TEXT NOT NULL,
    title TEXT DEFAULT '',
    description TEXT DEFAULT '',
    date_taken TEXT DEFAULT '',
    width INTEGER,
    height INTEGER,
    hash TEXT UNIQUE,
    author TEXT DEFAULT 'Waldo Jaquith',
    license TEXT DEFAULT 'CC BY-NC-SA 4.0',
    gps_latitude REAL,
    gps_longitude REAL,
    gps_img_direction REAL,
    gps_img_direction_ref TEXT,
    created_at INTEGER DEFAULT (strftime('%s', 'now')),
    updated_at INTEGER DEFAULT (strftime('%s', 'now'))
)
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE photo_exif (
    photo_id TEXT NOT NULL,
    tag TEXT NOT NULL,
    value TEXT,
    value_num REAL,
    sequence INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (photo_id, tag, sequence),
    FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE CASCADE
)
SQL);

    $photoStmt = $db->prepare('INSERT INTO photos (
        id, filename, title, description, date_taken,
        width, height, hash, author, license,
        gps_latitude, gps_longitude, gps_img_direction, gps_img_direction_ref
    ) VALUES (:id, :filename, :title, :description, :date_taken, :width, :height, :hash, :author, :license, :gps_lat, :gps_lon, :gps_dir, :gps_dir_ref)');

    foreach ($photos as $photo) {
        $photoStmt->reset();
        $photoStmt->bindValue(':id', $photo['id'], SQLITE3_TEXT);
        $photoStmt->bindValue(':filename', $photo['filename'], SQLITE3_TEXT);
        $photoStmt->bindValue(':title', $photo['title'] ?? '', SQLITE3_TEXT);
        $photoStmt->bindValue(':description', $photo['description'] ?? '', SQLITE3_TEXT);
        $photoStmt->bindValue(':date_taken', $photo['date_taken'] ?? '', SQLITE3_TEXT);
        if (array_key_exists('width', $photo) && $photo['width'] !== null) {
            $photoStmt->bindValue(':width', (int) $photo['width'], SQLITE3_INTEGER);
        } else {
            $photoStmt->bindValue(':width', null, SQLITE3_NULL);
        }
        if (array_key_exists('height', $photo) && $photo['height'] !== null) {
            $photoStmt->bindValue(':height', (int) $photo['height'], SQLITE3_INTEGER);
        } else {
            $photoStmt->bindValue(':height', null, SQLITE3_NULL);
        }
        $hashValue = $photo['hash'] ?? null;
        if ($hashValue !== null && $hashValue !== '') {
            $photoStmt->bindValue(':hash', $hashValue, SQLITE3_TEXT);
        } else {
            $photoStmt->bindValue(':hash', null, SQLITE3_NULL);
        }
        $author = $photo['author'] ?? 'Waldo Jaquith';
        $license = $photo['license'] ?? 'CC BY-NC-SA 4.0';
        $photoStmt->bindValue(':author', $author, SQLITE3_TEXT);
        $photoStmt->bindValue(':license', $license, SQLITE3_TEXT);
        if (isset($photo['gps_latitude'])) {
            $photoStmt->bindValue(':gps_lat', $photo['gps_latitude'], SQLITE3_FLOAT);
        } else {
            $photoStmt->bindValue(':gps_lat', null, SQLITE3_NULL);
        }
        if (isset($photo['gps_longitude'])) {
            $photoStmt->bindValue(':gps_lon', $photo['gps_longitude'], SQLITE3_FLOAT);
        } else {
            $photoStmt->bindValue(':gps_lon', null, SQLITE3_NULL);
        }
        if (isset($photo['gps_img_direction'])) {
            $photoStmt->bindValue(':gps_dir', $photo['gps_img_direction'], SQLITE3_FLOAT);
        } else {
            $photoStmt->bindValue(':gps_dir', null, SQLITE3_NULL);
        }
        $dirRef = $photo['gps_img_direction_ref'] ?? null;
        if ($dirRef !== null && $dirRef !== '') {
            $photoStmt->bindValue(':gps_dir_ref', $dirRef, SQLITE3_TEXT);
        } else {
            $photoStmt->bindValue(':gps_dir_ref', null, SQLITE3_NULL);
        }

        $photoStmt->execute();
    }

    if (!empty($exif)) {
        $exifStmt = $db->prepare('INSERT INTO photo_exif (photo_id, tag, value, value_num, sequence)
            VALUES (:photo_id, :tag, :value, :value_num, :sequence)');
        foreach ($exif as $row) {
            $exifStmt->reset();
            $exifStmt->bindValue(':photo_id', $row['photo_id'], SQLITE3_TEXT);
            $exifStmt->bindValue(':tag', $row['tag'], SQLITE3_TEXT);
            $exifStmt->bindValue(':value', $row['value'], SQLITE3_TEXT);
            if (array_key_exists('value_num', $row) && $row['value_num'] !== null) {
                $exifStmt->bindValue(':value_num', $row['value_num'], SQLITE3_FLOAT);
            } else {
                $exifStmt->bindValue(':value_num', null, SQLITE3_NULL);
            }
            $exifStmt->bindValue(':sequence', $row['sequence'] ?? 0, SQLITE3_INTEGER);
            $exifStmt->execute();
        }
    }

    $db->close();
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if (!$items) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$tests = [
    'image_processor_generates_thumbnail' => function (): void {
        $requiredFunctions = ['imagecreatetruecolor', 'imagecopyresampled', 'imagecreatefrompng', 'imagepng'];
        foreach ($requiredFunctions as $fn) {
            if (!function_exists($fn)) {
                return;
            }
        }

        with_temp_dir(function (string $dir): void {
            $originalsDir = $dir . '/originals';
            $thumbnailsDir = $dir . '/photos';
            mkdir($originalsDir);
            mkdir($thumbnailsDir);

            $originalFilename = 'sample.png';
            $originalPath = $originalsDir . '/' . $originalFilename;
            $image = imagecreatetruecolor(4, 4);
            $red = imagecolorallocate($image, 255, 0, 0);
            imagefilledrectangle($image, 0, 0, 3, 3, $red);
            imagepng($image, $originalPath);
            imagedestroy($image);

            $processor = new GalleryImageProcessor();
            $processor->ensureThumbnails($originalsDir . '/', $originalFilename, $thumbnailsDir . '/', ['thumbnail' => 2], 'abc123', 'png');

            $thumbPath = $thumbnailsDir . '/abc123_thumbnail.png';
            assertTrue(is_file($thumbPath), 'Thumbnail file should be created');
            $info = getimagesize($thumbPath);
            assertTrue($info !== false, 'Generated thumbnail should be a valid image');
            assertTrue((int) $info[0] <= 2 && (int) $info[1] <= 2, 'Thumbnail dimensions should match requested size');
        });
    },
    'image_processor_generate_thumbnail_returns_false_for_missing_file' => function (): void {
        $processor = new GalleryImageProcessor();
        $thumbPath = __DIR__ . '/tmp_thumb.jpg';
        if (file_exists($thumbPath)) {
            @unlink($thumbPath);
        }
        $result = $processor->generateThumbnail('/path/to/nowhere.jpg', $thumbPath, 100);
        assertFalse($result);
        assertFalse(file_exists($thumbPath), 'Thumbnail should not be created for missing source');
    },
    'image_processor_generate_square_thumbnail_crops_center' => function (): void {
        if (!function_exists('imagecreatetruecolor')) {
            return;
        }

        with_temp_dir(function (string $dir): void {
            $processor = new GalleryImageProcessor();
            $sourcePath = $dir . '/source.jpg';
            $thumbPath = $dir . '/thumbsquare.jpg';

            $width = 800;
            $height = 400;
            $image = imagecreatetruecolor($width, $height);
            $green = imagecolorallocate($image, 0, 255, 0);
            $red = imagecolorallocate($image, 255, 0, 0);
            imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $green);
            imagefilledrectangle($image, (int) floor($width / 2), 0, $width - 1, $height - 1, $red);
            imagejpeg($image, $sourcePath, 90);
            imagedestroy($image);

            $result = $processor->generateSquareThumbnail($sourcePath, $thumbPath, 400, 144);
            assertTrue($result, 'Square thumbnail generation should succeed');
            assertTrue(is_file($thumbPath));

            $info = getimagesize($thumbPath);
            assertTrue($info !== false);
            assertEquals(400, (int) $info[0]);
            assertEquals(400, (int) $info[1]);

            $thumb = imagecreatefromjpeg($thumbPath);
            $firstPixel = imagecolorsforindex($thumb, imagecolorat($thumb, 0, 0));
            $lastPixel = imagecolorsforindex($thumb, imagecolorat($thumb, 399, 0));
            imagedestroy($thumb);

            assertTrue($firstPixel['green'] > $firstPixel['red'], 'Left edge should retain left-side color');
            assertTrue($lastPixel['red'] > $lastPixel['green'], 'Right edge should retain right-side color');
        });
    },
    'exif_helper_extracts_gps_coordinates' => function (): void {
        $exif = [
            'GPSLatitude' => ['37/1', '48/1', '3000/100'],
            'GPSLatitudeRef' => 'N',
            'GPSLongitude' => ['122/1', '25/1', '1200/100'],
            'GPSLongitudeRef' => 'W',
        ];
        $coords = GalleryExifHelper::extractGpsCoordinates($exif);
        assertTrue(is_array($coords));
        assertTrue(abs($coords['latitude'] - 37.808333) < 0.0001, 'Latitude should be ~37.808333');
        assertTrue(abs($coords['longitude'] + 122.42) < 0.0001, 'Longitude should be ~-122.42');
    },
    'exif_helper_extracts_gps_coordinates_returns_null_when_missing' => function (): void {
        $coords = GalleryExifHelper::extractGpsCoordinates([]);
        assertTrue($coords === null);
    },
    'descriptions_page_lists_missing_photos' => function (): void {
        if (!function_exists('imagecreatetruecolor')) {
            return;
        }

        with_temp_dir(function (string $dir): void {
            $originalsDir = $dir . '/originals';
            mkdir($originalsDir);
            $photosDir = $dir . '/photos';
            mkdir($photosDir);

            $image = imagecreatetruecolor(6, 6);
            $orange = imagecolorallocate($image, 255, 165, 0);
            imagefilledrectangle($image, 0, 0, 5, 5, $orange);
            imagepng($image, $originalsDir . '/example.png');
            imagedestroy($image);

            create_test_database($dir . '/gallery.db', [
                [
                    'id' => 'example',
                    'filename' => 'example.png',
                    'title' => 'Example',
                    'description' => '',
                    'date_taken' => '',
                ],
            ]);

            file_put_contents($dir . '/settings.inc.php', "<?php\n"
                . '$database_path = ' . var_export('gallery.db', true) . ";\n"
                . '$sizes = ' . var_export(['thumbnail' => 50, 'thumbsquare' => 400], true) . ";\n"
                . '$photos_dir = ' . var_export('originals/', true) . ";\n"
                . '$thumbnails_dir = ' . var_export('photos/', true) . ";\n"
            );
            file_put_contents($dir . '/functions.inc.php', "<?php require_once '" . addslashes(__DIR__ . '/../functions.inc.php') . "';");

            $previousRoot = $GLOBALS['__gallery_root__'] ?? null;
            $previousCwd = getcwd();
            $GLOBALS['__gallery_root__'] = $dir;
            chdir($dir);

            ob_start();
            include __DIR__ . '/../pages/descriptions.php';
            $html = ob_get_clean();

            chdir($previousCwd);
            if ($previousRoot === null) {
                unset($GLOBALS['__gallery_root__']);
            } else {
                $GLOBALS['__gallery_root__'] = $previousRoot;
            }

            assertTrue(strpos($html, 'Photos Missing Descriptions') !== false, 'Page heading should be present');
            assertTrue(strpos($html, 'example.png') !== false, 'Filename should be rendered');
            assertTrue(strpos($html, '<textarea>') !== false, 'YAML textarea should be rendered');
        });
    },
    'map_page_renders_geolocated_photos' => function (): void {
        if (!function_exists('imagecreatetruecolor')) {
            return;
        }

        with_temp_dir(function (string $dir): void {
            $originalsDir = $dir . '/originals';
            $derivedDir = $dir . '/photos';
            mkdir($originalsDir);
            mkdir($derivedDir);

            create_test_database($dir . '/gallery.db', [
                [
                    'id' => 'aaaaaa',
                    'filename' => 'a.jpg',
                    'title' => 'Has GPS',
                    'gps_latitude' => 37.808333,
                    'gps_longitude' => -122.420000,
                ],
                [
                    'id' => 'bbbbbb',
                    'filename' => 'b.jpg',
                    'title' => 'No GPS',
                ],
            ]);

            $image = imagecreatetruecolor(10, 10);
            $color = imagecolorallocate($image, 255, 255, 255);
            imagefilledrectangle($image, 0, 0, 9, 9, $color);
            imagejpeg($image, $originalsDir . '/a.jpg');
            imagejpeg($image, $originalsDir . '/b.jpg');
            imagedestroy($image);

            file_put_contents($derivedDir . '/aaaaaa_thumbsquare.jpg', 'thumbsquare');

            file_put_contents($dir . '/settings.inc.php', "<?php\n"
                . '$database_path = ' . var_export('gallery.db', true) . ";\n"
                . '$sizes = ' . var_export(['thumbnail' => 150, 'thumbsquare' => 400], true) . ";\n"
                . '$photos_dir = ' . var_export('originals/', true) . ";\n"
                . '$thumbnails_dir = ' . var_export('photos/', true) . ";\n"
            );
            file_put_contents($dir . '/functions.inc.php', "<?php require_once '" . addslashes(__DIR__ . '/../functions.inc.php') . "';");

            $previousRoot = $GLOBALS['__gallery_root__'] ?? null;
            $previousCwd = getcwd();
            $GLOBALS['__gallery_root__'] = $dir;
            chdir($dir);

            ob_start();
            include __DIR__ . '/../pages/map.php';
            $html = ob_get_clean();

            chdir($previousCwd);
            if ($previousRoot === null) {
                unset($GLOBALS['__gallery_root__']);
            } else {
                $GLOBALS['__gallery_root__'] = $previousRoot;
            }

            assertTrue(strpos($html, 'Has GPS') !== false, 'Map should include photos with GPS data');
            assertTrue(strpos($html, 'No GPS') === false, 'Map should exclude photos without GPS data');
            assertTrue(strpos($html, '/view/?id=aaaaaa') !== false, 'Marker popup should link to view page');
            assertTrue(strpos($html, '_thumbsquare') !== false, 'Map popup should include thumbnail image reference');
            assertTrue(strpos($html, 'class="main-nav"') !== false, 'Map page should include navigation menu');
        });
    },

    'router_serves_view_path_segment' => function (): void {
        $previousRequest = $_SERVER['REQUEST_URI'] ?? null;
        $previousGet = $_GET ?? [];
        $previousRequestArray = $_REQUEST ?? [];

        $_SERVER['REQUEST_URI'] = '/view/3bb2a5';
        $_GET = [];
        $_REQUEST = [];

        ob_start();
        include __DIR__ . '/../index.php';
        $html = ob_get_clean();

        if ($previousRequest === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $previousRequest;
        }
        $_GET = $previousGet;
        $_REQUEST = $previousRequestArray;

        assertTrue(strpos($html, 'Rachel playing cards') !== false, 'View page should render when path contains the id');
    },
    'view_directory_index_serves_photos' => function (): void {
        $originalCwd = getcwd();
        $originalRequest = $_SERVER['REQUEST_URI'] ?? null;
        $originalGet = $_GET ?? [];
        $originalRequestArray = $_REQUEST ?? [];

        chdir(__DIR__ . '/../view');
        $_SERVER['REQUEST_URI'] = '/view/?id=3bb2a5';
        $_GET = ['id' => '3bb2a5'];
        $_REQUEST = $_GET;

        ob_start();
        include 'index.php';
        $html = ob_get_clean();

        chdir($originalCwd);
        if ($originalRequest === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $originalRequest;
        }
        $_GET = $originalGet;
        $_REQUEST = $originalRequestArray;

        assertTrue(strpos($html, 'Rachel playing cards') !== false, 'View directory index should render the photo');
    },
];

$passed = 0;
$failed = 0;
$failures = [];

foreach ($tests as $name => $test) {
    try {
        $test();
        $passed++;
        echo '.';
    } catch (Throwable $throwable) {
        $failed++;
        echo 'F';
        $failures[] = [$name, $throwable];
    }
}

echo PHP_EOL;
echo sprintf("Ran %d tests: %d passed, %d failed.
", count($tests), $passed, $failed);

if ($failed > 0) {
    echo "Failures:
";
    foreach ($failures as [$testName, $throwable]) {
        echo sprintf("- %s: %s
", $testName, $throwable->getMessage());
    }
    exit(1);
}

exit(0);
