<?php

declare(strict_types=1);

if (!isset($GLOBALS['__gallery_root__'])) {
    $GLOBALS['__gallery_root__'] = __DIR__;
}

$appRoot = $GLOBALS['__gallery_root__'];

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$trimmed = trim($uri, '/');

if ($trimmed === '') {
    $segments = [];
} else {
    $segments = explode('/', $trimmed);
}

$route = $segments[0] ?? '';

switch ($route) {
    case '':
        require $appRoot . '/pages/home.php';
        break;
    case 'map':
        require $appRoot . '/pages/map.php';
        break;
    case 'geolocator':
        require $appRoot . '/pages/geolocator.php';
        break;
    case 'view':
        if (!isset($_GET['id']) && !empty($segments[1])) {
            $_GET['id'] = $_REQUEST['id'] = $segments[1];
        }
        require $appRoot . '/pages/view.php';
        break;
    default:
        http_response_code(404);
        echo '<h1>404 Not Found</h1>';
        break;
}
