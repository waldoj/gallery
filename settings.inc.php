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
$site_base_url = 'http://waldo.jaquith.org/gallery/';

// Load environment variables from a local .env file if present.
$envPath = __DIR__ . '/.env';
if (is_file($envPath)) {
    $envData = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    if (is_array($envData)) {
        foreach ($envData as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $stringValue = (string) $value;
            putenv("{$key}={$stringValue}");
            $_ENV[$key] = $stringValue;
            $_SERVER[$key] = $stringValue;
        }
    }
}

/**
 * Set the OpenAI API key to generate alt text for photos. Prefer environment
 * variable OPENAI_API_KEY, optionally configured via a local .env file.
 */
$openai_api_key = getenv('OPENAI_API_KEY') ?: '';
$openai_alt_text_model = 'gpt-4o-mini';
