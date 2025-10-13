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
    'exif_helper_returns_empty_array_for_missing_file' => function (): void {
        $result = GalleryExifHelper::read(__DIR__ . '/nonexistent.jpg');
        assertTrue(is_array($result), 'Exif helper should return an array');
        assertEquals([], $result);
    },
    'yaml_repository_dump_matches_expected' => function (): void {
        $repository = new GalleryYamlRepository();
        $input = [
            'photo1' => ['title' => 'Example', 'published' => true],
            'photo2' => 'value',
        ];
        $expected = "photo1:\n  title: Example\n  published: true\nphoto2: value\n";
        assertEquals($expected, $repository->dump($input));
    },
    'image_processor_generates_thumbnail' => function (): void {
        $requiredFunctions = [
            'imagecreatetruecolor',
            'imagecopyresampled',
            'imagecreatefrompng',
            'imagepng',
        ];
        foreach ($requiredFunctions as $fn) {
            if (!function_exists($fn)) {
                // Skip silently when GD is unavailable.
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
            assertTrue($image !== false, 'Failed to create source image');
            $red = imagecolorallocate($image, 255, 0, 0);
            imagefill($image, 0, 0, $red);
            assertTrue(imagepng($image, $originalPath) === true, 'Failed to write source PNG');
            imagedestroy($image);

            $processor = new GalleryImageProcessor();
            $sizes = ['thumbnail' => 1];
            $processor->ensureThumbnails($originalsDir . '/', $originalFilename, $thumbnailsDir . '/', $sizes, 'abc123', 'png');

            $thumbPath = $thumbnailsDir . '/abc123_thumbnail.png';
            assertTrue(is_file($thumbPath), 'Thumbnail file should be created');
            $info = getimagesize($thumbPath);
            assertTrue($info !== false, 'Generated thumbnail should be a valid image');
            assertTrue((int) $info[0] <= 1 && (int) $info[1] <= 1, 'Thumbnail dimensions should match requested size');
        });
    },
    'yaml_repository_load_parses_yaml' => function (): void {
        with_temp_dir(function (string $dir): void {
            $yamlPath = $dir . '/library.yml';
            file_put_contents($yamlPath, "photo1:\n  title: Example\nphoto2: value\n");

            $repository = new GalleryYamlRepository();
            $data = $repository->load($yamlPath);

            assertArrayHasKey('photo1', $data);
            assertEquals('Example', $data['photo1']['title']);
            assertEquals('value', $data['photo2']);
        });
    },
    'library_normalizer_converts_old_format' => function (): void {
        $input = [
            'legacy.jpg' => [
                'title' => 'Legacy',
            ],
        ];
        $normalized = GalleryLibraryNormalizer::normalize($input);
        $id = substr(hash('sha1', 'legacy.jpg'), -6);
        assertArrayHasKey($id, $normalized);
        assertEquals('legacy.jpg', $normalized[$id]['filename']);
        assertEquals('Legacy', $normalized[$id]['title']);
        assertEquals($id, $normalized[$id]['id']);
    },
    'library_manager_find_missing_photos_detects_missing_files' => function (): void {
        with_temp_dir(function (string $dir): void {
            $photosDir = $dir . '/photos';
            mkdir($photosDir);
            file_put_contents($photosDir . '/image1.jpg', 'data');
            file_put_contents($photosDir . '/image2.jpg', 'data');

            $libraryPath = $dir . '/library.yml';
            (new GalleryYamlRepository())->save($libraryPath, [
                'image1.jpg' => ['title' => 'Image 1'],
            ]);

            $manager = new GalleryLibraryManager($libraryPath, $photosDir, $dir . '/photos', []);
            $missing = $manager->findMissingPhotos();
            sort($missing);

            assertEquals(['image2.jpg'], $missing);
        });
    },
    'library_manager_load_returns_array' => function (): void {
        with_temp_dir(function (string $dir): void {
            $libraryPath = $dir . '/library.yml';
            $data = [
                'sample.jpg' => ['title' => 'Sample'],
            ];
            (new GalleryYamlRepository())->save($libraryPath, $data);

            $manager = new GalleryLibraryManager($libraryPath, $dir . '/originals', $dir . '/photos', []);
            $loaded = $manager->load();
            $expectedId = substr(hash('sha1', 'sample.jpg'), -6);

            assertArrayHasKey($expectedId, $loaded);
            assertEquals('Sample', $loaded[$expectedId]['title']);
            assertEquals('sample.jpg', $loaded[$expectedId]['filename']);
        });
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

            $libraryPath = $dir . '/library.yml';
            (new GalleryYamlRepository())->save($libraryPath, [
                'example.png' => [
                    'filename' => 'example.png',
                    'title' => 'Example',
                    'description' => '',
                    'date_taken' => '',
                ],
            ]);

            file_put_contents($dir . '/settings.inc.php', "<?php
" .
                '$library = ' . var_export('library.yml', true) . ";
" .
                '$sizes = ' . var_export(['thumbnail' => 50, 'thumbsquare' => 400], true) . ";
" .
                '$photos_dir = ' . var_export('originals/', true) . ";
" .
                '$thumbnails_dir = ' . var_export('photos/', true) . ";
"
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
    'library_manager_sync_adds_new_files_and_removes_missing' => function (): void {
        if (!function_exists('imagecreatetruecolor')) {
            return;
        }

        with_temp_dir(function (string $dir): void {
            $originalsDir = $dir . '/originals';
            mkdir($originalsDir);
            $image = imagecreatetruecolor(8, 8);
            $blue = imagecolorallocate($image, 0, 0, 255);
            imagefilledrectangle($image, 0, 0, 7, 7, $blue);
            imagepng($image, $originalsDir . '/new.png');
            imagedestroy($image);

            $derivedDir = $dir . '/photos';
            $libraryPath = $dir . '/library.yml';
            (new GalleryYamlRepository())->save($libraryPath, [
                'old.jpg' => [
                    'title' => 'Old',
                    'description' => 'Desc',
                    'date_taken' => 'Yesterday',
                ],
            ]);

            $manager = new GalleryLibraryManager($libraryPath, $originalsDir, $derivedDir, ['thumbnail' => 100, 'thumbsquare' => 400]);
            $result = $manager->sync();
            $synced = $result['library'];
            assertEquals([], $result['duplicates']);
            assertEquals([], $result['thumbnails_missing']);
            assertEquals([], $result['unsupported_files']);

            $newId = substr(hash('sha1', 'new.png'), -6);
            $oldId = substr(hash('sha1', 'old.jpg'), -6);

            assertArrayHasKey($newId, $synced);
            assertEquals('new', $synced[$newId]['title']);
            assertEquals('', $synced[$newId]['description']);
            assertEquals('new.png', $synced[$newId]['filename']);
            assertEquals($newId, $synced[$newId]['id']);
            assertTrue(isset($synced[$newId]['width']) && $synced[$newId]['width'] === 8);
            assertTrue(isset($synced[$newId]['height']) && $synced[$newId]['height'] === 8);
            assertTrue(array_key_exists('exif', $synced[$newId]));
            assertEquals('Waldo Jaquith', $synced[$newId]['author']);
            assertEquals('CC BY-NC-SA 4.0', $synced[$newId]['license']);
            assertFalse(isset($synced[$oldId]), 'Missing files should be removed from library');
            assertTrue(is_dir($derivedDir), 'Thumbnails directory should be created if missing');
            $expectedThumb = $derivedDir . '/' . $newId . '_thumbnail.png';
            assertTrue(is_file($expectedThumb), 'Thumbnail file should be created (or copied)');
            $expectedSquare = $derivedDir . '/' . $newId . '_thumbsquare.png';
            assertTrue(is_file($expectedSquare), 'Thumbsquare image should be created');
            $squareInfo = getimagesize($expectedSquare);
            assertTrue($squareInfo !== false);
            assertEquals(400, (int) $squareInfo[0]);
            assertEquals(400, (int) $squareInfo[1]);
        });
    },
    'library_manager_sync_updates_existing_entry' => function (): void {
        if (!function_exists('imagecreatetruecolor')) {
            return;
        }

        with_temp_dir(function (string $dir): void {
            $originalsDir = $dir . '/originals';
            mkdir($originalsDir);
            $image = imagecreatetruecolor(5, 5);
            $green = imagecolorallocate($image, 0, 255, 0);
            imagefilledrectangle($image, 0, 0, 4, 4, $green);
            imagepng($image, $originalsDir . '/existing.png');
            imagedestroy($image);

            $libraryPath = $dir . '/library.yml';
            (new GalleryYamlRepository())->save($libraryPath, [
                'existing.png' => [
                    'title' => 'Custom title',
                    'description' => 'Already there',
                ],
            ]);

            $manager = new GalleryLibraryManager($libraryPath, $originalsDir, $dir . '/photos', []);
            $result = $manager->sync();
            $synced = $result['library'];
            assertEquals([], $result['duplicates']);
            assertEquals([], $result['thumbnails_missing']);
            assertEquals([], $result['unsupported_files']);

            $existingId = substr(hash('sha1', 'existing.png'), -6);
            assertArrayHasKey($existingId, $synced);
            assertEquals('Custom title', $synced[$existingId]['title']);
            assertEquals('Already there', $synced[$existingId]['description']);
            assertEquals(5, $synced[$existingId]['width']);
            assertEquals(5, $synced[$existingId]['height']);
            assertArrayHasKey('date_taken', $synced[$existingId]);
            assertArrayHasKey('exif', $synced[$existingId]);
            assertEquals($existingId, $synced[$existingId]['id']);
            assertEquals('Waldo Jaquith', $synced[$existingId]['author']);
            assertEquals('CC BY-NC-SA 4.0', $synced[$existingId]['license']);
        });
    },

    'library_manager_sync_reports_unsupported_files' => function (): void {
        with_temp_dir(function (string $dir): void {
            $originalsDir = $dir . '/originals';
            mkdir($originalsDir);
            file_put_contents($originalsDir . '/photo.heic', 'not-a-photo');

            $libraryPath = $dir . '/library.yml';
            (new GalleryYamlRepository())->save($libraryPath, []);

            $manager = new GalleryLibraryManager($libraryPath, $originalsDir, $dir . '/photos', []);
            $result = $manager->sync();

            assertEquals([], $result['library']);
            assertEquals([], $result['thumbnails_missing']);
            assertEquals([], $result['duplicates']);
            assertTrue(in_array('photo.heic', $result['unsupported_files'], true));
        });
    },
    'library_manager_sync_returns_original_when_photos_dir_missing' => function (): void {
        with_temp_dir(function (string $dir): void {
            $libraryPath = $dir . '/library.yml';
            $source = ['photo.jpg' => ['title' => 'Existing']];
            (new GalleryYamlRepository())->save($libraryPath, $source);

            $manager = new GalleryLibraryManager($libraryPath, $dir . '/missing', $dir . '/photos', []);
            $result = $manager->sync();
            $synced = $result['library'];
            assertEquals([], $result['duplicates']);
            assertEquals([], $result['thumbnails_missing']);
            assertEquals([], $result['unsupported_files']);

            $normalized = GalleryLibraryNormalizer::normalize($source);
            assertEquals($normalized, $synced);
        });
    },
    'image_processor_is_photo_file_recognizes_supported_extensions' => function (): void {
        assertTrue(GalleryImageProcessor::isPhotoFile('image.jpg'));
        assertTrue(GalleryImageProcessor::isPhotoFile('image.JPG'));
        assertTrue(GalleryImageProcessor::isPhotoFile('image.png'));
        assertFalse(GalleryImageProcessor::isPhotoFile('document.pdf'));
        assertFalse(GalleryImageProcessor::isPhotoFile('noextension'));
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
    'image_processor_generate_thumbnail_creates_output_file' => function (): void {
        if (!function_exists('imagecreatetruecolor')) {
            return;
        }

        with_temp_dir(function (string $dir): void {
            $processor = new GalleryImageProcessor();
            $source = $dir . '/source.png';
            $thumbnail = $dir . '/thumb.png';
            $image = imagecreatetruecolor(5, 5);
            $color = imagecolorallocate($image, 200, 100, 50);
            imagefilledrectangle($image, 0, 0, 4, 4, $color);
            imagepng($image, $source);
            imagedestroy($image);
            $result = $processor->generateThumbnail($source, $thumbnail, 10);
            assertTrue((bool) $result, 'Thumbnail generation should succeed or fall back to copying');
            assertTrue(is_file($thumbnail), 'Thumbnail file should exist');
            assertTrue(filesize($thumbnail) > 0, 'Thumbnail file should not be empty');
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
    'index_renderer_handles_numeric_photo_ids' => function (): void {
        if (!function_exists('imagecreatetruecolor')) {
            return;
        }

        with_temp_dir(function (string $dir): void {
            $originalsDir = $dir . '/originals';
            mkdir($originalsDir);
            $derivedDir = $dir . '/photos';
            mkdir($derivedDir);

            $filename = 'numeric.png';
            $image = imagecreatetruecolor(5, 5);
            $grey = imagecolorallocate($image, 128, 128, 128);
            imagefilledrectangle($image, 0, 0, 4, 4, $grey);
            imagepng($image, $originalsDir . '/' . $filename);
            imagedestroy($image);

            $libraryPath = $dir . '/library.yml';
            (new GalleryYamlRepository())->save($libraryPath, [
                '810612' => [
                    'id' => '810612',
                    'filename' => $filename,
                    'title' => 'Numeric',
                    'description' => '',
                    'date_taken' => '',
                    'width' => 1,
                    'height' => 1,
                    'exif' => [],
                ],
            ]);

            $manager = new GalleryLibraryManager($libraryPath, $originalsDir, $derivedDir, []);
            $libraryData = $manager->load();

            $photos = [];
            foreach ($libraryData as $photoId => $metadata) {
                if (!is_array($metadata)) {
                    continue;
                }
                $photoIdString = (string) $photoId;
                $file = $metadata['filename'] ?? null;
                if ($file === null) {
                    continue;
                }
                $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
                $extensionSuffix = $extension !== '' ? '.' . $extension : '';
                $idForFile = (string) ($metadata['id'] ?? $photoIdString);
                $thumbnailPath = $derivedDir . '/' . $idForFile . '_thumbnail' . $extensionSuffix;
                if (!is_file($thumbnailPath)) {
                    $thumbnailPath = $originalsDir . '/' . $file;
                }
                if (!is_file($thumbnailPath)) {
                    continue;
                }
                $photos[] = [
                    'id' => $idForFile,
                    'photo_id' => $photoIdString,
                    'title' => $metadata['title'] ?? 'Untitled',
                    'date_taken' => $metadata['date_taken'] ?? 'Unknown date',
                    'thumbnail_path' => $thumbnailPath,
                    'link' => 'view.php?id=' . rawurlencode($photoIdString),
                ];
            }

            $renderer = new GalleryTemplateRenderer();
            $html = $renderer->render('index.html.twig', ['photos' => $photos]);
            assertTrue(strpos($html, '<div class="photo"') !== false, 'Index template should render photo entries');
        });
    },
    'library_manager_corrects_orientation_when_supported' => function (): void {
        if (!class_exists('Imagick')) {
            return;
        }

        with_temp_dir(function (string $dir): void {
            $originalsDir = $dir . '/originals';
            mkdir($originalsDir);
            $derivedDir = $dir . '/photos';
            mkdir($derivedDir);

            $imagePath = $originalsDir . '/rotated.jpg';
            $imagick = new \Imagick();
            $imagick->newImage(30, 10, new \ImagickPixel('blue'));
            $imagick->setImageFormat('jpeg');
            $imagick->writeImage($imagePath);
            $imagick->clear();
            $imagick->destroy();

            // If Imagick doesn't embed orientation, short-circuit this test.
            $exif = @exif_read_data($imagePath);
            if (!is_array($exif) || !isset($exif['Orientation'])) {
                return;
            }

            $libraryPath = $dir . '/library.yml';
            (new GalleryYamlRepository())->save($libraryPath, []);

            $manager = new GalleryLibraryManager($libraryPath, $originalsDir, $derivedDir, ['thumbnail' => 12]);
            $result = $manager->sync();
            $synced = $result['library'];
            assertEquals([], $result['duplicates']);

            $id = substr(hash('sha1', 'rotated.jpg'), -6);
            assertArrayHasKey($id, $synced);
            $record = $synced[$id];

            assertEquals(10, $record['width']);
            assertEquals(30, $record['height']);

            if (isset($record['exif']['Orientation'])) {
                assertEquals(1, (int) $record['exif']['Orientation']);
            }

            $thumbnailPath = $derivedDir . '/' . $id . '_thumbnail.jpg';
            assertTrue(is_file($thumbnailPath), 'Thumbnail should exist after orientation correction');
            $thumbInfo = getimagesize($thumbnailPath);
            assertTrue($thumbInfo !== false);
            assertEquals(12, (int) $thumbInfo[0]);
        });
    },
    'map_page_renders_geolocated_photos' => function (): void {
        with_temp_dir(function (string $dir): void {
            $libraryPath = $dir . '/library.yml';
            $originalsDir = $dir . '/originals';
            $derivedDir = $dir . '/photos';
            mkdir($originalsDir);
            mkdir($derivedDir);

            $libraryData = [
                'a.jpg' => [
                    'id' => 'aaaaaa',
                    'filename' => 'a.jpg',
                    'title' => 'Has GPS',
                    'exif' => [
                        'GPSLatitude' => ['37/1', '48/1', '3000/100'],
                        'GPSLatitudeRef' => 'N',
                        'GPSLongitude' => ['122/1', '25/1', '1200/100'],
                        'GPSLongitudeRef' => 'W',
                    ],
                ],
                'b.jpg' => [
                    'id' => 'bbbbbb',
                    'filename' => 'b.jpg',
                    'title' => 'No GPS',
                    'exif' => [],
                ],
            ];

            (new GalleryYamlRepository())->save($libraryPath, $libraryData);

            // Provide a mock thumbnail so the map can reference it.
            file_put_contents($derivedDir . '/aaaaaa_thumbnail.jpg', 'thumb');

            file_put_contents($dir . '/settings.inc.php', "<?php\n" .
                '$library = ' . var_export('library.yml', true) . ";\n" .
                '$sizes = ' . var_export(['thumbnail' => 150, 'thumbsquare' => 400], true) . ";\n" .
                '$photos_dir = ' . var_export('originals/', true) . ";\n" .
                '$thumbnails_dir = ' . var_export('photos/', true) . ";\n"
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
            assertTrue(strpos($html, '_thumbnail') !== false, 'Map popup should include thumbnail image reference');
            assertTrue(strpos($html, 'class="main-nav"') !== false, 'Map page should include navigation menu');
        });
    },
    'geolocator_page_contains_map_container' => function (): void {
        $dir = sys_get_temp_dir() . '/geolocator_settings_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/settings.inc.php', "<?php\n" .
            '$library = ' . var_export('library.yml', true) . ";\n" .
            '$sizes = ' . var_export(['thumbnail' => 150, 'thumbsquare' => 400], true) . ";\n" .
            '$photos_dir = ' . var_export('originals/', true) . ";\n" .
            '$thumbnails_dir = ' . var_export('photos/', true) . ";\n"
        );
        file_put_contents($dir . '/functions.inc.php', "<?php require_once '" . addslashes(__DIR__ . '/../functions.inc.php') . "';");

        $previousRoot = $GLOBALS['__gallery_root__'] ?? null;
        $previousCwd = getcwd();
        $GLOBALS['__gallery_root__'] = $dir;
        chdir($dir);

        ob_start();
        include __DIR__ . '/../pages/geolocator.php';
        $html = ob_get_clean();

        chdir($previousCwd);
        if ($previousRoot === null) {
            unset($GLOBALS['__gallery_root__']);
        } else {
            $GLOBALS['__gallery_root__'] = $previousRoot;
        }

        assertTrue(strpos($html, 'id="geolocator-map"') !== false, 'Geolocator page should include map container');
        assertTrue(strpos($html, 'GPSLatitudeRef') !== false, 'Geolocator instructions should mention GPS YAML fields');
        assertTrue(strpos($html, 'class="main-nav"') !== false, 'Geolocator page should include navigation menu');
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
echo sprintf("Ran %d tests: %d passed, %d failed.\n", count($tests), $passed, $failed);

if ($failed > 0) {
    echo "Failures:\n";
    foreach ($failures as [$testName, $throwable]) {
        echo sprintf("- %s: %s\n", $testName, $throwable->getMessage());
    }
    exit(1);
}

exit(0);
