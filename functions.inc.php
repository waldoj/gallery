<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

function gallery_public_url_path(string $path): string
{
    $normalized = trim($path);

    if ($normalized === '') {
        return '/';
    }

    $firstChar = $normalized[0];
    if ($firstChar === '/' || $firstChar === '#') {
        return $normalized;
    }

    if (preg_match('/^[a-z][a-z0-9+\-.]*:/i', $normalized) === 1) {
        return $normalized;
    }

    return '/' . ltrim($normalized, '/\\');
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

final class GalleryYamlRepository
{
    public function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new RuntimeException(
                'Unable to parse YAML file: ' . $path . '. ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if ($data === null) {
            return [];
        }

        if (!is_array($data)) {
            throw new RuntimeException('Unexpected YAML structure in file: ' . $path);
        }

        return $data;
    }

    public function dump(array $data): string
    {
        return Yaml::dump($data, 4, 2);
    }

    public function save(string $path, array $data): void
    {
        $yaml = $this->dump($data);
        if (!str_ends_with($yaml, "\n")) {
            $yaml .= "\n";
        }

        if (@file_put_contents($path, $yaml) === false) {
            throw new RuntimeException('Unable to write library file: ' . $path);
        }
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

            if (!is_file($thumbnailPath)) {
                $this->generateThumbnail($photoPath, $thumbnailPath, $dimension);
            }
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

final class GalleryLibraryNormalizer
{
    public static function normalize(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $record) {
            if (!is_array($record)) {
                $record = [
                    'title' => is_scalar($record) ? (string) $record : '',
                ];
            }

            $filename = isset($record['filename']) && $record['filename'] !== ''
                ? (string) $record['filename']
                : (string) $key;

            $id = isset($record['id']) && $record['id'] !== ''
                ? (string) $record['id']
                : substr(hash('sha1', $filename), -6);

            $record['title'] = isset($record['title']) && $record['title'] !== ''
                ? (string) $record['title']
                : pathinfo($filename, PATHINFO_FILENAME);

            $record['description'] = array_key_exists('description', $record)
                ? (string) $record['description']
                : '';

            $record['date_taken'] = array_key_exists('date_taken', $record)
                ? (string) $record['date_taken']
                : '';

            if ($record['date_taken'] === '' && isset($record['exif']['Model'], $record['exif']['DateTimeOriginal'])) {
                $parsed = self::formatExifDate($record['exif']['DateTimeOriginal']);
                if ($parsed !== null) {
                    $record['date_taken'] = $parsed;
                }
            }

            $record['author'] = array_key_exists('author', $record) && $record['author'] !== ''
                ? (string) $record['author']
                : 'Waldo Jaquith';

            $record['license'] = array_key_exists('license', $record) && $record['license'] !== ''
                ? (string) $record['license']
                : 'CC BY-NC-SA 4.0';

            $record['width'] = self::normalizeDimension($record['width'] ?? null);
            $record['height'] = self::normalizeDimension($record['height'] ?? null);

            if (!isset($record['exif']) || !is_array($record['exif'])) {
                $record['exif'] = [];
            }

            $record['filename'] = $filename;
            $record['id'] = $id;

            $normalized[$id] = $record;
        }

        return $normalized;
    }

    private static function normalizeDimension(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $intValue = (int) $value;
            return $intValue >= 0 ? $intValue : null;
        }

        if (is_string($value) && strtolower($value) === 'null') {
            return null;
        }

        if (is_array($value) && empty($value)) {
            return null;
        }

        return $value === null ? null : null;
    }

    private static function formatExifDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $raw = str_replace(['.', '\\', ' '], [':', ':', ' '], $raw);
        $dateTime = DateTime::createFromFormat('Y:m:d H:i:s', $raw);
        if ($dateTime === false) {
            return null;
        }

        return $dateTime->format('F j, Y');
    }
}

final class GalleryLibraryManager
{
    private string $photosDir;
    private string $thumbnailsDir;
    private array $libraryData = [];

    public function __construct(
        private readonly string $libraryPath,
        string $photosDir,
        string $thumbnailsDir,
        private readonly array $sizes = [],
        private ?GalleryYamlRepository $repository = null,
        private ?GalleryImageProcessor $imageProcessor = null
    ) {
        $this->repository = $this->repository ?? new GalleryYamlRepository();
        $this->imageProcessor = $this->imageProcessor ?? new GalleryImageProcessor();

        $photosDir = rtrim($photosDir, "/\\");
        $this->photosDir = $photosDir === '' ? '' : $photosDir . DIRECTORY_SEPARATOR;

        $thumbnailsDir = rtrim($thumbnailsDir, "/\\");
        $this->thumbnailsDir = $thumbnailsDir === '' ? '' : $thumbnailsDir . DIRECTORY_SEPARATOR;
    }

    public function load(): array
    {
        $rawData = $this->repository->load($this->libraryPath);
        $this->libraryData = GalleryLibraryNormalizer::normalize($rawData);
        return $this->libraryData;
    }

    public function save(?array $data = null): void
    {
        $data = $data ?? $this->libraryData;
        $this->repository->save($this->libraryPath, $data);
    }

    public function sync(): array
    {
        $library = $this->load();
        $existingHashes = [];
        foreach ($library as $record) {
            if (isset($record['hash']) && $record['hash'] !== '') {
                $existingHashes[$record['hash']] = true;
            }
        }
        $duplicates = [];

        if ($this->photosDir === '' || !is_dir($this->photosDir)) {
            return ['library' => $library, 'duplicates' => $duplicates];
        }

        $photoFiles = $this->getPhotoFiles();

        $filenameToId = [];
        foreach ($library as $id => $record) {
            if (isset($record['filename'])) {
                $filenameToId[$record['filename']] = $id;
            }
        }

        foreach ($photoFiles as $filename) {
            $photoPath = $this->photosDir . $filename;
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $id = $this->generateId($filename);
            $hash = sha1_file($photoPath) ?: null;
            if ($hash !== null && isset($existingHashes[$hash])) {
                $duplicates[] = [
                    'filename' => $filename,
                    'hash' => $hash,
                ];
                continue;
            }

            $exif = GalleryExifHelper::read($photoPath);
            $orientationAdjusted = $this->imageProcessor->normalizeOrientation($photoPath, $exif);
            if ($orientationAdjusted) {
                $this->imageProcessor->clearThumbnails($id, $this->thumbnailsDir, $this->sizes, $extension, $filename);
            }
            [$width, $height] = $this->imageProcessor->getDimensions($photoPath);

            if (!isset($library[$id]) && isset($filenameToId[$filename])) {
                $existingId = $filenameToId[$filename];
                $library[$id] = $library[$existingId];
                if ($existingId !== $id) {
                    unset($library[$existingId]);
                }
            }

            $current = $library[$id] ?? [];
            $library[$id] = $this->applyRecordDefaults($current, $filename, $width, $height, $exif, $id, $hash);
            if ($hash !== null) {
                $existingHashes[$hash] = true;
            }

            $this->imageProcessor->ensureThumbnails($this->photosDir, $filename, $this->thumbnailsDir, $this->sizes, $id, $extension);
        }

        foreach ($library as $id => $record) {
            $filename = $record['filename'] ?? null;
            if ($filename === null || !in_array($filename, $photoFiles, true)) {
                unset($library[$id]);
            }
        }

        $this->libraryData = $library;

        return ['library' => $library, 'duplicates' => $duplicates];
    }

    public function findMissingPhotos(): array
    {
        $library = $this->load();

        if ($this->photosDir === '' || !is_dir($this->photosDir)) {
            return [];
        }

        $photoFiles = $this->getPhotoFiles();
        $libraryFiles = array_map(
            static fn (array $record): string => (string) ($record['filename'] ?? ''),
            $library
        );

        return array_values(array_diff($photoFiles, $libraryFiles));
    }

    private function applyRecordDefaults(
        array $record,
        string $filename,
        ?int $width,
        ?int $height,
        array $exif,
        string $id,
        ?string $hash = null
    ): array {
        $record['title'] = isset($record['title']) && $record['title'] !== ''
            ? (string) $record['title']
            : pathinfo($filename, PATHINFO_FILENAME);

        $record['description'] = array_key_exists('description', $record)
            ? (string) $record['description']
            : '';

        $record['date_taken'] = array_key_exists('date_taken', $record)
            ? (string) $record['date_taken']
            : '';

        if ($record['date_taken'] === '' && isset($exif['Model'], $exif['DateTimeOriginal'])) {
            $parsed = self::formatExifDate($exif['DateTimeOriginal']);
            if ($parsed !== null) {
                $record['date_taken'] = $parsed;
            }
        }

        if ($width !== null) {
            $record['width'] = $width;
        } elseif (!isset($record['width'])) {
            $record['width'] = null;
        }

        if ($height !== null) {
            $record['height'] = $height;
        } elseif (!isset($record['height'])) {
            $record['height'] = null;
        }

        $record['exif'] = $exif;
        $record['filename'] = $filename;
        $record['id'] = $id;
        if ($hash !== null) {
            $record['hash'] = $hash;
        }

        if (!isset($record['author']) || $record['author'] === '') {
            $record['author'] = 'Waldo Jaquith';
        }

        if (!isset($record['license']) || $record['license'] === '') {
            $record['license'] = 'CC BY-NC-SA 4.0';
        }

        return $record;
    }

    private function getPhotoFiles(): array
    {
        if ($this->photosDir === '' || !is_dir($this->photosDir)) {
            return [];
        }

        $files = scandir($this->photosDir);
        if ($files === false) {
            return [];
        }

        $photos = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (GalleryImageProcessor::isPhotoFile($file)) {
                $photos[] = $file;
            }
        }

        return $photos;
    }

    private function generateId(string $filename): string
    {
        return substr(hash('sha1', $filename), -6);
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
    }

    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }
}
