<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

$appRoot = __DIR__;
require_once $appRoot . '/settings.inc.php';
require_once $appRoot . '/functions.inc.php';

$databasePath = rtrim(str_replace('\\', '/', $appRoot), '/') . '/' . ltrim($database_path ?? 'gallery.db', '/\\');
$photosDir = rtrim($appRoot, '/\\') . '/' . trim($photos_dir, '/\\');
$thumbnailsDir = rtrim($appRoot, '/\\') . '/' . trim($thumbnails_dir, '/\\');

if (!is_dir($photosDir)) {
    die("Photos directory not found: {$photosDir}\n");
}

$db = new SQLite3($databasePath);
$db->enableExceptions(true);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('BEGIN TRANSACTION');

$existingPhotos = [];
$existingHashes = [];
$result = $db->query('SELECT id, filename, hash FROM photos');
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $existingPhotos[$row['id']] = $row;
    if (!empty($row['hash'])) {
        $existingHashes[$row['hash']] = $row['id'];
    }
}
$result->finalize();

$processedIds = [];
$duplicates = [];
$unsupportedFiles = [];
$thumbnailsMissing = [];

$processor = new GalleryImageProcessor();
$photosDirFs = rtrim($photosDir, '/\\') . '/';
$thumbnailsDirFs = rtrim($thumbnailsDir, '/\\') . '/';

$insertPhoto = $db->prepare(<<<'SQL'
INSERT INTO photos (
    id, filename, title, description, date_taken,
    width, height, hash, author, license,
    gps_latitude, gps_longitude, gps_img_direction, gps_img_direction_ref,
    updated_at
) VALUES (
    :id, :filename, :title, :description, :date_taken,
    :width, :height, :hash, :author, :license,
    :gps_lat, :gps_lon, :gps_dir, :gps_dir_ref,
    strftime('%s', 'now')
)
ON CONFLICT(id) DO UPDATE SET
    filename = excluded.filename,
    width = excluded.width,
    height = excluded.height,
    hash = excluded.hash,
    author = excluded.author,
    license = excluded.license,
    updated_at = excluded.updated_at
SQL
);

$deleteExif = $db->prepare('DELETE FROM photo_exif WHERE photo_id = :id');
$insertExif = $db->prepare('INSERT INTO photo_exif (photo_id, tag, value, value_num, sequence)
    VALUES (:photo_id, :tag, :value, :value_num, :sequence)');

$files = scandir($photosDirFs);
if ($files === false) {
    $files = [];
}

foreach ($files as $file) {
    if ($file === '.' || $file === '..') {
        continue;
    }

    if (!GalleryImageProcessor::isPhotoFile($file)) {
        $unsupportedFiles[] = $file;
        continue;
    }

    $photoPath = $photosDirFs . $file;
    if (!is_file($photoPath)) {
        continue;
    }

    $photoId = substr(hash('sha1', $file), -6);
    $hash = sha1_file($photoPath) ?: null;
    if ($hash !== null && isset($existingHashes[$hash]) && $existingHashes[$hash] !== $photoId) {
        $duplicates[] = ['filename' => $file, 'hash' => $hash];
        continue;
    }

    $exif = GalleryExifHelper::read($photoPath);
    $processor->normalizeOrientation($photoPath, $exif);
    [$width, $height] = $processor->getDimensions($photoPath);

    $title = $file;
    $description = '';
    $dateTaken = $exif['DateTimeOriginal'] ?? '';
    if ($dateTaken !== '') {
        $dateTaken = (string) $dateTaken;
    }

    $gpsLat = null;
    $gpsLon = null;
    $gpsDir = null;
    $gpsDirRef = null;

    if (isset($exif['GPSLatitude'], $exif['GPSLatitudeRef'])) {
        $gpsLat = GalleryExifHelper::coordinateToDecimal($exif['GPSLatitude'], (string) $exif['GPSLatitudeRef']);
    }
    if (isset($exif['GPSLongitude'], $exif['GPSLongitudeRef'])) {
        $gpsLon = GalleryExifHelper::coordinateToDecimal($exif['GPSLongitude'], (string) $exif['GPSLongitudeRef']);
    }
    if (isset($exif['GPSImgDirection'])) {
        $gpsDir = GalleryExifHelper::fractionToFloat($exif['GPSImgDirection']);
    }
    if (isset($exif['GPSImgDirectionRef'])) {
        $gpsDirRef = (string) $exif['GPSImgDirectionRef'];
    }

    $insertPhoto->reset();
    $insertPhoto->bindValue(':id', $photoId, SQLITE3_TEXT);
    $insertPhoto->bindValue(':filename', $file, SQLITE3_TEXT);
    $insertPhoto->bindValue(':title', $title, SQLITE3_TEXT);
    $insertPhoto->bindValue(':description', $description, SQLITE3_TEXT);
    $insertPhoto->bindValue(':date_taken', $dateTaken, SQLITE3_TEXT);
    $insertPhoto->bindValue(':width', $width, $width === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $insertPhoto->bindValue(':height', $height, $height === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $insertPhoto->bindValue(':hash', $hash, $hash === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $insertPhoto->bindValue(':author', 'Waldo Jaquith', SQLITE3_TEXT);
    $insertPhoto->bindValue(':license', 'CC BY-NC-SA 4.0', SQLITE3_TEXT);
    $insertPhoto->bindValue(':gps_lat', $gpsLat, $gpsLat === null ? SQLITE3_NULL : SQLITE3_FLOAT);
    $insertPhoto->bindValue(':gps_lon', $gpsLon, $gpsLon === null ? SQLITE3_NULL : SQLITE3_FLOAT);
    $insertPhoto->bindValue(':gps_dir', $gpsDir, $gpsDir === null ? SQLITE3_NULL : SQLITE3_FLOAT);
    $insertPhoto->bindValue(':gps_dir_ref', $gpsDirRef, $gpsDirRef === null || $gpsDirRef === '' ? SQLITE3_NULL : SQLITE3_TEXT);
    $insertPhoto->execute();

    $deleteExif->reset();
    $deleteExif->bindValue(':id', $photoId, SQLITE3_TEXT);
    $deleteExif->execute();

    $sequenceMap = [];
    foreach ($exif as $tag => $value) {
        if (is_array($value) && array_values($value) === $value) {
            foreach ($value as $index => $item) {
                $insertExif->reset();
                $insertExif->bindValue(':photo_id', $photoId, SQLITE3_TEXT);
                $insertExif->bindValue(':tag', (string) $tag, SQLITE3_TEXT);
                $insertExif->bindValue(':value', is_scalar($item) ? (string) $item : json_encode($item), SQLITE3_TEXT);
                $valNum = GalleryExifHelper::fractionToFloat($item);
                $insertExif->bindValue(':value_num', $valNum, $valNum === null ? SQLITE3_NULL : SQLITE3_FLOAT);
                $insertExif->bindValue(':sequence', $index, SQLITE3_INTEGER);
                $insertExif->execute();
            }
        } else {
            $insertExif->reset();
            $insertExif->bindValue(':photo_id', $photoId, SQLITE3_TEXT);
            $insertExif->bindValue(':tag', (string) $tag, SQLITE3_TEXT);
            $insertExif->bindValue(':value', is_scalar($value) ? (string) $value : json_encode($value), SQLITE3_TEXT);
            $valNum = GalleryExifHelper::fractionToFloat($value);
            $insertExif->bindValue(':value_num', $valNum, $valNum === null ? SQLITE3_NULL : SQLITE3_FLOAT);
            $insertExif->bindValue(':sequence', 0, SQLITE3_INTEGER);
            $insertExif->execute();
        }
    }

    $processor->ensureThumbnails($photosDirFs, $file, $thumbnailsDirFs, $sizes, $photoId, pathinfo($file, PATHINFO_EXTENSION));

    foreach ($sizes as $sizeName => $_) {
        $clean = preg_replace('/[^a-z0-9_\-]/i', '', (string) $sizeName);
        $expected = $thumbnailsDirFs . $photoId . '_' . $clean . '.' . strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        if (!is_file($expected)) {
            $thumbnailsMissing[] = [
                'filename' => $file,
                'id' => $photoId,
                'paths' => [$expected],
            ];
        }
    }

    $processedIds[] = $photoId;
    if ($hash !== null) {
        $existingHashes[$hash] = $photoId;
    }
}

// Remove photos no longer present.
$idsToRemove = array_diff(array_keys($existingPhotos), $processedIds);
if (!empty($idsToRemove)) {
    $deleteStmt = $db->prepare('DELETE FROM photos WHERE id = :id');
    foreach ($idsToRemove as $id) {
        $deleteStmt->reset();
        $deleteStmt->bindValue(':id', $id, SQLITE3_TEXT);
        $deleteStmt->execute();
    }
}

$db->exec('COMMIT');
$db->close();

if (!empty($duplicates)) {
    echo "Duplicate photos detected:\n";
    foreach ($duplicates as $dup) {
        echo sprintf("- %s (hash: %s)\n", $dup['filename'], $dup['hash']);
    }
}

if (!empty($thumbnailsMissing)) {
    echo "The following thumbnails could not be generated:\n";
    foreach ($thumbnailsMissing as $failure) {
        $filename = $failure['filename'] ?? '(unknown)';
        echo sprintf("- %s (id %s)\n", $filename, (string) ($failure['id'] ?? 'n/a'));
        foreach ($failure['paths'] ?? [] as $path) {
            echo sprintf("    missing: %s\n", $path);
        }
    }
}

if (!empty($unsupportedFiles)) {
    echo "Unsupported image files were skipped:\n";
    foreach ($unsupportedFiles as $file) {
        echo sprintf("- %s\n", $file);
    }
}

echo "Library updated.\n";
