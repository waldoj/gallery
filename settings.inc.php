<?php

$database_path = 'gallery.db';
$sizes = array(
    'thumbnail' => 150,
    'thumbsquare' => 400,
    'medium' => 600,
    'large' => 1200,
);
$photos_dir = 'originals/';
$thumbnails_dir = 'photos/';


// For example, $deployment_host = 'user@example.com';
$deployment_host = $deployment_host ?? null;

// For example, $deployment_path = '/var/www/gallery';
$deployment_path = $deployment_path ?? null;
