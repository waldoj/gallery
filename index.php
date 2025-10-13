<?php

declare(strict_types=1);

if (!isset($GLOBALS['__gallery_root__'])) {
    $GLOBALS['__gallery_root__'] = __DIR__;
}

$appRoot = $GLOBALS['__gallery_root__'];

if (!isset($GLOBALS['__gallery_base_path__'])) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $derivedBasePath = '';

    if (is_string($docRoot) && $docRoot !== '') {
        $realDocRoot = realpath($docRoot);
        $realAppRoot = realpath($appRoot);

        if ($realDocRoot !== false && $realAppRoot !== false) {
            $normalizedDocRoot = rtrim(str_replace('\\', '/', $realDocRoot), '/');
            $normalizedAppRoot = rtrim(str_replace('\\', '/', $realAppRoot), '/');

            if ($normalizedDocRoot !== '' && strncmp($normalizedAppRoot, $normalizedDocRoot, strlen($normalizedDocRoot)) === 0) {
                $suffix = substr($normalizedAppRoot, strlen($normalizedDocRoot));
                if ($suffix !== false && $suffix !== '') {
                    $derivedBasePath = '/' . ltrim($suffix, '/');
                }
            }
        }
    }

    $GLOBALS['__gallery_base_path__'] = $derivedBasePath;
}

$basePath = $GLOBALS['__gallery_base_path__'];
if (!is_string($basePath)) {
    $basePath = '';
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if ($basePath !== '') {
    if ($uri === $basePath || $uri === $basePath . '/') {
        $uri = '/';
    } elseif (strncmp($uri, $basePath . '/', strlen($basePath) + 1) === 0) {
        $uri = substr($uri, strlen($basePath));
        if ($uri === false || $uri === '') {
            $uri = '/';
        }
    }
}

if ($uri !== '/' && is_file($appRoot . $uri)) {
    return false;
}

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
    case 'descriptions':
        require $appRoot . '/pages/descriptions.php';
        break;
    case 'photo-metadata':
        require $appRoot . '/pages/photo-metadata.php';
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
