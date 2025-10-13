<?php

declare(strict_types=1);

$appRoot = $GLOBALS['__gallery_root__'] ?? dirname(__DIR__);

require_once $appRoot . '/settings.inc.php';
require_once $appRoot . '/functions.inc.php';

$renderer = new GalleryTemplateRenderer();

echo $renderer->render('about.html.twig');

