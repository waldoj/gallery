<?php

declare(strict_types=1);

$appRoot = $GLOBALS['__gallery_root__'] ?? dirname(__DIR__);

require_once $appRoot . '/settings.inc.php';
require_once $appRoot . '/functions.inc.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    return;
}

if (gallery_is_static_export()) {
    http_response_code(403);
    echo json_encode(['error' => 'Editing is not available in static exports.']);
    return;
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = [];

if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

if (empty($payload)) {
    $payload = $_POST;
}

$rawId = isset($payload['id']) ? (string) $payload['id'] : '';
$photoId = preg_replace('/[^A-Za-z0-9_\-]/', '', $rawId) ?? '';
$photoId = trim($photoId);

if ($photoId === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Photo id is required.']);
    return;
}

$title = isset($payload['title']) ? (string) $payload['title'] : '';
$title = trim($title);

if ($title === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Title is required.']);
    return;
}

$description = isset($payload['description']) ? (string) $payload['description'] : '';
$dateTakenRaw = isset($payload['date_taken']) ? (string) $payload['date_taken'] : '';
$dateTakenRaw = trim($dateTakenRaw);
$dateTaken = $dateTakenRaw === '' ? null : $dateTakenRaw;

$databasePath = rtrim($appRoot, '/\\') . '/' . ltrim($database_path ?? 'gallery.db', '/\\');

try {
    $updated = gallery_update_photo_metadata($databasePath, $photoId, $title, $description, $dateTaken);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update photo metadata.']);
    return;
}

if ($updated === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Photo not found.']);
    return;
}

http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'photo' => [
        'id' => $updated['id'] ?? $photoId,
        'title' => $updated['title'] ?? $title,
        'description' => $updated['description'] ?? $description,
        'date_taken' => $updated['date_taken'] ?? $dateTakenRaw,
    ],
]);

