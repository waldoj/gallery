<?php

declare(strict_types=1);

$appRoot = $GLOBALS['__gallery_root__'] ?? dirname(__DIR__);

require_once $appRoot . '/settings.inc.php';
require_once $appRoot . '/functions.inc.php';

$libraryPath = rtrim($appRoot, '/\\') . '/' . ltrim($library, '/\\');
$photosDirFs = rtrim($appRoot, '/\\') . '/' . trim($photos_dir, '/\\') . '/';
$thumbnailsDirFs = rtrim($appRoot, '/\\') . '/' . trim($thumbnails_dir, '/\\') . '/';

$libraryManager = new GalleryLibraryManager($libraryPath, $photosDirFs, $thumbnailsDirFs, $sizes);
$libraryData = $libraryManager->load();

$entries = [];

foreach ($libraryData as $photoId => $record) {
    $description = isset($record['description']) ? (string) $record['description'] : '';
    if (trim($description) !== '') {
        continue;
    }

    $filename = $record['filename'] ?? null;
    if ($filename === null || $filename === '') {
        continue;
    }

    $id = (string) ($record['id'] ?? $photoId);
    $thumbnailUrl = gallery_public_url_path(findThumbnailPath(
        $id,
        $filename,
        $photosDirFs,
        $thumbnailsDirFs,
        (string) $photos_dir,
        (string) $thumbnails_dir,
        $sizes
    ));

    $entries[] = [
        'id' => $id,
        'filename' => $filename,
        'thumbnail_url' => $thumbnailUrl,
        'yaml_snippet' => buildYamlSnippet(
            (string) ($record['title'] ?? ''),
            (string) ($record['description'] ?? ''),
            (string) ($record['date_taken'] ?? '')
        ),
    ];
}

$renderer = new GalleryTemplateRenderer();

echo $renderer->render('missing_descriptions.html.twig', [
    'entries' => $entries,
]);

/**
 * @param array<string, mixed> $sizes
 */
function findThumbnailPath(
    string $photoId,
    string $filename,
    string $photosDirFs,
    string $thumbnailsDirFs,
    string $photosDirRelative,
    string $thumbnailsDirRelative,
    array $sizes
): string {
    $photosDirRelative = rtrim((string) $photosDirRelative, '/\\') . '/';
    $thumbnailsDirRelative = rtrim((string) $thumbnailsDirRelative, '/\\') . '/';
    $thumbnailsDirFs = rtrim($thumbnailsDirFs, '/\\') . '/';

    $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    $extensionSuffix = $extension !== '' ? '.' . $extension : '';

    $preferredSizes = [];
    if (array_key_exists('thumbsquare', $sizes)) {
        $preferredSizes[] = 'thumbsquare';
    }
    if (array_key_exists('thumbnail', $sizes)) {
        $preferredSizes[] = 'thumbnail';
    }
    foreach (array_keys($sizes) as $sizeName) {
        if (!in_array($sizeName, $preferredSizes, true)) {
            $preferredSizes[] = $sizeName;
        }
    }

    foreach ($preferredSizes as $sizeName) {
        $cleanName = preg_replace('/[^a-z0-9_\-]/i', '', (string) $sizeName);
        $candidateFs = $thumbnailsDirFs . $photoId . '_' . $cleanName . $extensionSuffix;
        if (is_file($candidateFs)) {
            return $thumbnailsDirRelative . $photoId . '_' . $cleanName . $extensionSuffix;
        }
    }

    $globMatches = glob($thumbnailsDirFs . $photoId . '_*');
    if ($globMatches !== false && !empty($globMatches)) {
        $match = $globMatches[0];
        if (is_file($match)) {
            return $thumbnailsDirRelative . basename($match);
        }
    }

    return $photosDirRelative . $filename;
}

function buildYamlSnippet(string $title, string $description, string $dateTaken): string
{
    $lines = [
        '  title: ' . escapeYamlValue($title),
        "  description: ''",
        '  date_taken: ' . escapeYamlValue($dateTaken),
    ];

    return implode("\n", $lines);
}

function escapeYamlValue(string $value): string
{
    if ($value === '') {
        return "''";
    }

    if (preg_match('/^[A-Za-z0-9 _\\-:]+$/', $value) === 1 && !str_contains($value, '  ')) {
        return $value;
    }

    $escaped = str_replace("'", "''", $value);
    return "'" . $escaped . "'";
}
