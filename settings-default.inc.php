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

/**
 * Set the OpenAI API key to generate alt text for photos.
 */
$openai_api_key = '';
$openai_alt_text_model = 'gpt-4o-mini';
