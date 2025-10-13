<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

function gallery_base_path(): string
{
    $basePath = $GLOBALS['__gallery_base_path__'] ?? '';
    if (!is_string($basePath)) {
        return '';
    }

    if ($basePath === '/' || $basePath === '') {
        return '';
    }

    return rtrim($basePath, '/');
}

function gallery_public_url_path(string $path): string
{
    $normalized = trim($path);
    $basePath = gallery_base_path();

    if ($normalized === '') {
        return $basePath === '' ? '/' : $basePath . '/';
    }

    $firstChar = $normalized[0];
    if ($firstChar === '#') {
        return $normalized;
    }

    if (preg_match('/^[a-z][a-z0-9+\-.]*:/i', $normalized) === 1) {
        return $normalized;
    }

    if ($firstChar === '/') {
        $normalizedPath = '/' . ltrim($normalized, '/');
    } else {
        $normalizedPath = '/' . ltrim($normalized, '/\\');
    }

    return $basePath === '' ? $normalizedPath : $basePath . $normalizedPath;
}

final class GalleryExifHelper
{
    public static function read(string $imagePath): array
    {
        if ($imagePath === '' || !is_readable($imagePath) || !function_exists('exif_read_data')) {
            return [];
        }

        $exif = @exif_read_data($imagePath, null, true);
        if ($exif === false || !is_array($exif)) {
            return [];
        }

        $flattened = [];
        foreach ($exif as $section) {
            if (!is_array($section)) {
                continue;
            }
            foreach ($section as $key => $value) {
                if (!array_key_exists($key, $flattened)) {
                    $flattened[$key] = $value;
                }
            }
        }

        return $flattened;
    }

    public static function fractionToFloat(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $parts = explode('/', $value);
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1]) && (float) $parts[1] !== 0.0) {
                return (float) $parts[0] / (float) $parts[1];
            }
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        if (is_array($value) && isset($value['num'], $value['den']) && (float) $value['den'] !== 0.0) {
            return (float) $value['num'] / (float) $value['den'];
        }

        return null;
    }

    public static function coordinateToDecimal(mixed $coordinate, string $hemisphere): ?float
    {
        if (!is_array($coordinate) || count($coordinate) < 3) {
            return null;
        }

        $degrees = self::fractionToFloat($coordinate[0]);
        $minutes = self::fractionToFloat($coordinate[1]);
        $seconds = self::fractionToFloat($coordinate[2]);

        if ($degrees === null || $minutes === null || $seconds === null) {
            return null;
        }

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
        $hemisphere = strtoupper($hemisphere);
        if ($hemisphere === 'S' || $hemisphere === 'W') {
            $decimal *= -1;
        }

        return $decimal;
    }

    public static function extractGpsCoordinates(array $exif): ?array
    {
        if (
            !isset($exif['GPSLatitude'], $exif['GPSLatitudeRef'], $exif['GPSLongitude'], $exif['GPSLongitudeRef'])
        ) {
            return null;
        }

        $latitude = self::coordinateToDecimal($exif['GPSLatitude'], (string) $exif['GPSLatitudeRef']);
        $longitude = self::coordinateToDecimal($exif['GPSLongitude'], (string) $exif['GPSLongitudeRef']);

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }
}

final class GalleryImageProcessor
{
    private const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif'];

    public static function isPhotoFile(string $filename): bool
    {
        if ($filename === '') {
            return false;
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, self::SUPPORTED_EXTENSIONS, true);
    }

    public function getDimensions(string $photoPath): array
    {
        $imageInfo = @getimagesize($photoPath);
        if ($imageInfo === false) {
            return [null, null];
        }

        return [(int) $imageInfo[0], (int) $imageInfo[1]];
    }

    public function ensureThumbnails(
        string $photosDir,
        string $filename,
        string $thumbnailsDir,
        array $sizes,
        string $photoId,
        string $extension
    ): void {
        if ($thumbnailsDir === '' || empty($sizes)) {
            return;
        }

        $photoPath = $photosDir . $filename;
        if (!is_file($photoPath)) {
            return;
        }

        if (!is_dir($thumbnailsDir) && !@mkdir($thumbnailsDir, 0755, true) && !is_dir($thumbnailsDir)) {
            return;
        }

        $extension = ltrim(strtolower($extension), '.');
        $extensionSuffix = $extension !== '' ? '.' . $extension : '';

        foreach ($sizes as $sizeName => $dimension) {
            $dimension = (int) $dimension;
            if ($dimension <= 0) {
                continue;
            }

            $sanitizedName = preg_replace('/[^a-z0-9_\-]/i', '', (string) $sizeName);
            $thumbnailPath = $thumbnailsDir . $photoId . '_' . $sanitizedName . $extensionSuffix;

            if (is_file($thumbnailPath)) {
                continue;
            }

            if ($sanitizedName === 'thumbsquare') {
                $this->generateSquareThumbnail($photoPath, $thumbnailPath, $dimension, 144);
                continue;
            }

            $this->generateThumbnail($photoPath, $thumbnailPath, $dimension);
        }
    }

    public function clearThumbnails(string $photoId, string $thumbnailsDir, array $sizes, string $extension, ?string $legacyFilename = null): void
    {
        if ($thumbnailsDir === '' || empty($sizes)) {
            return;
        }

        $extension = ltrim(strtolower($extension), '.');
        $extensionSuffix = $extension !== '' ? '.' . $extension : '';

        foreach ($sizes as $sizeName => $_) {
            $sanitizedName = preg_replace('/[^a-z0-9_\-]/i', '', (string) $sizeName);
            $thumbnailPath = $thumbnailsDir . $photoId . '_' . $sanitizedName . $extensionSuffix;
            if (is_file($thumbnailPath)) {
                @unlink($thumbnailPath);
            }
            if ($legacyFilename !== null) {
                $legacyPath = $thumbnailsDir . $sanitizedName . '_' . $legacyFilename;
                if (is_file($legacyPath)) {
                    @unlink($legacyPath);
                }
            }
        }
    }

    public function normalizeOrientation(string $photoPath, array &$exif): bool
    {
        $orientationValue = $exif['Orientation'] ?? null;
        if ($orientationValue === null) {
            return false;
        }

        $orientation = (int) $orientationValue;
        if ($orientation <= 1) {
            return false;
        }

        $originalData = @file_get_contents($photoPath);
        $exifSegment = $originalData !== false ? $this->extractExifSegment($originalData) : null;

        if (class_exists('Imagick')) {
            try {
                $imagick = new \Imagick($photoPath);
                $imagick->autoOrientImage();
                $imagick->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
                $imagick->writeImage($photoPath);
                $imagick->clear();
                $imagick->destroy();
                $exif = GalleryExifHelper::read($photoPath);
                if (!isset($exif['Orientation'])) {
                    $exif['Orientation'] = 1;
                }
                return true;
            } catch (\Throwable) {
                // Fall back to GD approach below.
            }
        }

        $imageInfo = @getimagesize($photoPath);
        if ($imageInfo === false) {
            return false;
        }
        $type = (int) $imageInfo[2];
        $callbacks = $this->resolveCallbacks($type);
        if ($callbacks === null) {
            return false;
        }

        [$createCallback, $saveCallback] = $callbacks;
        $resource = @$createCallback($photoPath);
        if ($resource === false) {
            return false;
        }

        $transformed = $this->applyOrientationTransform($resource, $orientation);
        if (!$transformed) {
            imagedestroy($resource);
            return false;
        }

        $result = $saveCallback($resource, $photoPath);
        imagedestroy($resource);

        if ($result && $exifSegment !== null) {
            $updatedSegment = $this->updateOrientationInExifSegment($exifSegment);
            if ($updatedSegment !== null) {
                $this->writeExifSegment($photoPath, $updatedSegment);
            }
        }

        if ($result) {
            $exif = GalleryExifHelper::read($photoPath);
            $exif['Orientation'] = 1;
        }

        return (bool) $result;
    }


    public function generateSquareThumbnail(string $photoPath, string $thumbnailPath, int $size, int $dpi = 144): bool
    {
        if (!is_file($photoPath) || $size <= 0) {
            return false;
        }

        $imageInfo = @getimagesize($photoPath);
        if ($imageInfo === false) {
            return false;
        }

        [$width, $height, $type] = $imageInfo;
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $cropSize = min($width, $height);
        $srcX = (int) max(0, floor(($width - $cropSize) / 2));
        $srcY = (int) max(0, floor(($height - $cropSize) / 2));

        $thumbnailDir = dirname($thumbnailPath);
        if (!is_dir($thumbnailDir) && !@mkdir($thumbnailDir, 0755, true) && !is_dir($thumbnailDir)) {
            return false;
        }

        $callbacks = $this->resolveCallbacks((int) $type);
        if ($callbacks === null) {
            return (bool) @copy($photoPath, $thumbnailPath);
        }

        [$createCallback, $saveCallback] = $callbacks;

        $sourceImage = @$createCallback($photoPath);
        if ($sourceImage === false) {
            return false;
        }

        $square = @imagecreatetruecolor($size, $size);
        if ($square === false) {
            imagedestroy($sourceImage);
            return false;
        }

        // Preserve transparency for PNG/GIF
        if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
            imagealphablending($square, false);
            imagesavealpha($square, true);
            $transparent = imagecolorallocatealpha($square, 0, 0, 0, 127);
            imagefilledrectangle($square, 0, 0, $size, $size, $transparent);
        }

        $cropped = @imagecopyresampled(
            $square,
            $sourceImage,
            0,
            0,
            $srcX,
            $srcY,
            $size,
            $size,
            $cropSize,
            $cropSize
        );

        if ($cropped === false) {
            imagedestroy($square);
            imagedestroy($sourceImage);
            return false;
        }

        if (function_exists('imageresolution')) {
            @imageresolution($square, $dpi, $dpi);
        }

        $saved = $saveCallback($square, $thumbnailPath);

        imagedestroy($square);
        imagedestroy($sourceImage);

        return (bool) $saved;
    }

    public function generateThumbnail(string $photoPath, string $thumbnailPath, int $size): bool
    {
        if (!is_file($photoPath) || $size <= 0) {
            return false;
        }

        $imageInfo = @getimagesize($photoPath);
        if ($imageInfo === false) {
            return false;
        }

        [$width, $height, $type] = $imageInfo;
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        if ($photoPath === $thumbnailPath) {
            return true;
        }

        $maxDimension = max($width, $height);
        $scale = $size / $maxDimension;

        if ($scale >= 1) {
            return (bool) @copy($photoPath, $thumbnailPath);
        }

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $thumbnailDir = dirname($thumbnailPath);
        if (!is_dir($thumbnailDir) && !@mkdir($thumbnailDir, 0755, true) && !is_dir($thumbnailDir)) {
            return false;
        }

        $callbacks = $this->resolveCallbacks((int) $type);
        if ($callbacks === null) {
            return (bool) @copy($photoPath, $thumbnailPath);
        }

        [$createCallback, $saveCallback] = $callbacks;

        $sourceImage = @$createCallback($photoPath);
        if ($sourceImage === false) {
            return false;
        }

        $thumbnailImage = @imagecreatetruecolor($targetWidth, $targetHeight);
        if ($thumbnailImage === false) {
            imagedestroy($sourceImage);
            return false;
        }

        $resampled = @imagecopyresampled(
            $thumbnailImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );

        if ($resampled === false) {
            imagedestroy($thumbnailImage);
            imagedestroy($sourceImage);
            return false;
        }

        $saved = $saveCallback($thumbnailImage, $thumbnailPath);

        imagedestroy($thumbnailImage);
        imagedestroy($sourceImage);

        return (bool) $saved;
    }

    private function applyOrientationTransform(&$image, int $orientation): bool
    {
        switch ($orientation) {
            case 2:
                return $this->flipImage($image, IMG_FLIP_HORIZONTAL);
            case 3:
                return $this->rotateImageResource($image, 180);
            case 4:
                return $this->flipImage($image, IMG_FLIP_VERTICAL);
            case 5:
                return $this->flipImage($image, IMG_FLIP_HORIZONTAL) && $this->rotateImageResource($image, 270);
            case 6:
                return $this->rotateImageResource($image, 270);
            case 7:
                return $this->flipImage($image, IMG_FLIP_HORIZONTAL) && $this->rotateImageResource($image, 90);
            case 8:
                return $this->rotateImageResource($image, 90);
        }

        return false;
    }

    private function rotateImageResource(&$image, int $degrees): bool
    {
        if (!function_exists('imagerotate')) {
            return false;
        }

        $background = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $rotated = imagerotate($image, $degrees, $background);
        if ($rotated === false) {
            return false;
        }

        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);

        imagedestroy($image);
        $image = $rotated;

        return true;
    }

    private function flipImage(&$image, int $mode): bool
    {
        if (function_exists('imageflip')) {
            return imageflip($image, $mode);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $flipped = imagecreatetruecolor($width, $height);
        if ($flipped === false) {
            return false;
        }

        imagealphablending($flipped, false);
        imagesavealpha($flipped, true);
        $transparent = imagecolorallocatealpha($flipped, 0, 0, 0, 127);
        imagefill($flipped, 0, 0, $transparent);

        switch ($mode) {
            case IMG_FLIP_HORIZONTAL:
                for ($x = 0; $x < $width; $x++) {
                    imagecopy($flipped, $image, $width - $x - 1, 0, $x, 0, 1, $height);
                }
                break;
            case IMG_FLIP_VERTICAL:
                for ($y = 0; $y < $height; $y++) {
                    imagecopy($flipped, $image, 0, $height - $y - 1, 0, $y, $width, 1);
                }
                break;
            case IMG_FLIP_BOTH:
                for ($x = 0; $x < $width; $x++) {
                    for ($y = 0; $y < $height; $y++) {
                        imagecopy($flipped, $image, $width - $x - 1, $height - $y - 1, $x, $y, 1, 1);
                    }
                }
                break;
            default:
                imagedestroy($flipped);
                return false;
        }

        imagedestroy($image);
        $image = $flipped;

        return true;
    }

    private function extractExifSegment(string $jpegData): ?string
    {
        if (!str_starts_with($jpegData, "\xFF\xD8")) {
            return null;
        }

        $offset = 2;
        $length = strlen($jpegData);

        while ($offset + 4 <= $length && $jpegData[$offset] === "\xFF") {
            $marker = ord($jpegData[$offset + 1]);
            if ($marker === 0xDA) {
                break;
            }

            $segmentLength = (ord($jpegData[$offset + 2]) << 8) + ord($jpegData[$offset + 3]);
            $segmentTotal = 2 + $segmentLength;

            if ($marker === 0xE1) {
                return substr($jpegData, $offset, $segmentTotal);
            }

            $offset += $segmentTotal;
        }

        return null;
    }

    private function writeExifSegment(string $photoPath, string $segment): bool
    {
        $data = @file_get_contents($photoPath);
        if ($data === false || !str_starts_with($data, "\xFF\xD8")) {
            return false;
        }

        $cleanData = $this->removeExifSegment($data);
        $newData = substr($cleanData, 0, 2) . $segment . substr($cleanData, 2);

        return @file_put_contents($photoPath, $newData) !== false;
    }

    private function removeExifSegment(string $jpegData): string
    {
        if (!str_starts_with($jpegData, "\xFF\xD8")) {
            return $jpegData;
        }

        $offset = 2;
        $length = strlen($jpegData);
        $output = substr($jpegData, 0, 2);

        while ($offset + 4 <= $length && $jpegData[$offset] === "\xFF") {
            $marker = ord($jpegData[$offset + 1]);

            if ($marker === 0xDA) {
                $output .= substr($jpegData, $offset);
                return $output;
            }

            $segmentLength = (ord($jpegData[$offset + 2]) << 8) + ord($jpegData[$offset + 3]);
            $segmentTotal = 2 + $segmentLength;

            if ($marker !== 0xE1) {
                $output .= substr($jpegData, $offset, $segmentTotal);
            }

            $offset += $segmentTotal;
        }

        if ($offset < $length) {
            $output .= substr($jpegData, $offset);
        }

        return $output;
    }

    private function updateOrientationInExifSegment(string $segment): ?string
    {
        if (strlen($segment) < 10 || !str_starts_with($segment, "\xFF\xE1")) {
            return null;
        }

        if (substr($segment, 4, 6) !== "Exif\0\0") {
            return $segment;
        }

        $tiffStart = 10;
        if (strlen($segment) < $tiffStart + 8) {
            return $segment;
        }

        $endian = substr($segment, $tiffStart, 2);
        $isLittleEndian = $endian === 'II';

        $unpackShort = $isLittleEndian ? 'v' : 'n';
        $unpackLong = $isLittleEndian ? 'V' : 'N';

        $ifdOffset = unpack($unpackLong, substr($segment, $tiffStart + 4, 4))[1];
        $ifdPos = $tiffStart + $ifdOffset;
        if ($ifdPos + 2 > strlen($segment)) {
            return $segment;
        }

        $numEntries = unpack($unpackShort, substr($segment, $ifdPos, 2))[1];
        $entryPos = $ifdPos + 2;

        for ($i = 0; $i < $numEntries; $i++, $entryPos += 12) {
            if ($entryPos + 12 > strlen($segment)) {
                break;
            }

            $tag = unpack($unpackShort, substr($segment, $entryPos, 2))[1];
            if ($tag === 0x0112) {
                $type = unpack($unpackShort, substr($segment, $entryPos + 2, 2))[1];
                $count = unpack($unpackLong, substr($segment, $entryPos + 4, 4))[1];
                if ($type === 3 && $count >= 1) {
                    $valueOffset = $entryPos + 8;
                    $valueBytes = $isLittleEndian
                        ? pack('v', 1) . "\x00\x00"
                        : "\x00\x01\x00\x00";
                    return substr_replace($segment, $valueBytes, $valueOffset, 4);
                }
                break;
            }
        }

        return $segment;
    }

    private function resolveCallbacks(int $type): ?array
    {
        switch ($type) {
            case IMAGETYPE_JPEG:
                if (function_exists('imagecreatefromjpeg') && function_exists('imagejpeg')) {
                    return [
                        'imagecreatefromjpeg',
                        static fn ($image, $path): bool => imagejpeg($image, $path, 90),
                    ];
                }
                break;
            case IMAGETYPE_PNG:
                if (function_exists('imagecreatefrompng') && function_exists('imagepng')) {
                    return [
                        'imagecreatefrompng',
                        static fn ($image, $path): bool => imagepng($image, $path),
                    ];
                }
                break;
            case IMAGETYPE_GIF:
                if (function_exists('imagecreatefromgif') && function_exists('imagegif')) {
                    return [
                        'imagecreatefromgif',
                        static fn ($image, $path): bool => imagegif($image, $path),
                    ];
                }
                break;
        }

        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) {
            return null;
        }

        return null;
    }
}

final class GalleryDatabase
{
    private SQLite3 $connection;

    public function __construct(string $databasePath)
    {
        if (!is_file($databasePath)) {
            throw new RuntimeException('Database not found at ' . $databasePath);
        }

        $this->connection = new SQLite3($databasePath, SQLITE3_OPEN_READONLY);
        $this->connection->enableExceptions(true);
        $this->connection->exec('PRAGMA foreign_keys = ON');
    }

    public function __destruct()
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllPhotos(): array
    {
        $sql = 'SELECT id, filename, title, description, date_taken, width, height, hash, author, license,
                       gps_latitude, gps_longitude, gps_img_direction, gps_img_direction_ref
                FROM photos
                ORDER BY date_taken DESC, id';
        return $this->fetchAllAssoc($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPhotosWithLocation(): array
    {
        $sql = 'SELECT id, filename, title, gps_latitude, gps_longitude, gps_img_direction, gps_img_direction_ref
                FROM photos
                WHERE gps_latitude IS NOT NULL AND gps_longitude IS NOT NULL';
        return $this->fetchAllAssoc($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPhotosMissingDescription(): array
    {
        $sql = 'SELECT id, filename, title, description, date_taken
                FROM photos
                WHERE description IS NULL OR trim(description) = \'\'';
        return $this->fetchAllAssoc($sql);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPhotoById(string $photoId): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, filename, title, description, date_taken,
                    width, height, hash, author, license,
                    gps_latitude, gps_longitude, gps_img_direction, gps_img_direction_ref
             FROM photos WHERE id = :id'
        );
        $stmt->bindValue(':id', $photoId, SQLITE3_TEXT);
        $result = $stmt->execute();
        if ($result === false) {
            return null;
        }
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();
        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExifByPhotoId(string $photoId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT tag, value, sequence
             FROM photo_exif
             WHERE photo_id = :id
             ORDER BY tag, sequence'
        );
        $stmt->bindValue(':id', $photoId, SQLITE3_TEXT);
        $result = $stmt->execute();
        if ($result === false) {
            return [];
        }

        $exif = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $tag = $row['tag'];
            $value = $row['value'];
            $sequence = (int) ($row['sequence'] ?? 0);

            if (!array_key_exists($tag, $exif)) {
                $exif[$tag] = $sequence === 0 ? $value : [$value];
                continue;
            }

            if (!is_array($exif[$tag])) {
                $exif[$tag] = [$exif[$tag]];
            }

            $exif[$tag][$sequence] = $value;
        }
        $result->finalize();

        foreach ($exif as $tag => $value) {
            if (is_array($value)) {
                ksort($value);
                $exif[$tag] = array_values($value);
            }
        }

        return $exif;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllAssoc(string $sql): array
    {
        $result = $this->connection->query($sql);
        if ($result === false) {
            return [];
        }
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        $result->finalize();
        return $rows;
    }
}

final class GalleryTemplateRenderer
{
    private Environment $twig;

    public function __construct(?Environment $environment = null)
    {
        $this->twig = $environment ?? new Environment(
            new FilesystemLoader(__DIR__ . '/templates')
        );

        $this->twig->addGlobal('base_path', gallery_base_path());
    }

    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }
}
