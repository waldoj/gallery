<?php

declare(strict_types=1);

$_SERVER['REQUEST_URI'] = '/map' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
chdir(dirname(__DIR__));
require __DIR__ . '/../index.php';
