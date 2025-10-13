#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "==> Installing PHP dependencies"
composer install --no-interaction --prefer-dist --working-dir "${ROOT_DIR}"

echo "==> Running test suite"
php "${ROOT_DIR}/tests/run.php"

LEAFLET_VERSION="1.9.4"
LEAFLET_DIR="${ROOT_DIR}/assets/vendor/leaflet"
echo "==> Fetching Leaflet assets (v${LEAFLET_VERSION})"
mkdir -p "${LEAFLET_DIR}/images"
curl -sfLo "${LEAFLET_DIR}/leaflet.css" "https://unpkg.com/leaflet@${LEAFLET_VERSION}/dist/leaflet.css"
curl -sfLo "${LEAFLET_DIR}/leaflet.js" "https://unpkg.com/leaflet@${LEAFLET_VERSION}/dist/leaflet.js"
curl -sfLo "${LEAFLET_DIR}/images/marker-icon.png" "https://unpkg.com/leaflet@${LEAFLET_VERSION}/dist/images/marker-icon.png"
curl -sfLo "${LEAFLET_DIR}/images/marker-icon-2x.png" "https://unpkg.com/leaflet@${LEAFLET_VERSION}/dist/images/marker-icon-2x.png"
curl -sfLo "${LEAFLET_DIR}/images/marker-shadow.png" "https://unpkg.com/leaflet@${LEAFLET_VERSION}/dist/images/marker-shadow.png"

echo "==> Verifying GD/Imagick availability"
if ! php -r "if (!function_exists('imagecreatefromjpeg') || !function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) { exit(1); }"; then
  echo "ERROR: Required GD functions (imagecreatefromjpeg, imagecreatetruecolor, imagecopyresampled) are missing." >&2
  echo "Install/enable the PHP GD extension with JPEG support before running the build." >&2
  exit 1
fi

if ! php -r "if (!class_exists('Imagick')) { exit(1); }"; then
  echo "WARNING: Imagick extension not found. Falling back to GD only." >&2
fi

PHOTOS_DIR="${ROOT_DIR}/photos"
if [ ! -d "${PHOTOS_DIR}" ]; then
  mkdir -p "${PHOTOS_DIR}"
fi

if [ ! -w "${PHOTOS_DIR}" ]; then
  echo "ERROR: Directory '${PHOTOS_DIR}' is not writable. Thumbnails cannot be generated." >&2
  exit 1
fi

echo "==> Updating library metadata"
php "${ROOT_DIR}/updater.php"

ORIGINALS_DIR="${ROOT_DIR}/originals"
original_sample="$(find "${ORIGINALS_DIR}" -maxdepth 1 -type f -print -quit 2>/dev/null || true)"
if [ -n "${original_sample}" ]; then
  thumbnail_sample="$(find "${PHOTOS_DIR}" -maxdepth 1 -type f -print -quit 2>/dev/null || true)"
  if [ -z "${thumbnail_sample}" ]; then
    echo "ERROR: No thumbnails found in '${PHOTOS_DIR}' after updater ran." >&2
    echo "Ensure originals are readable and that GD/Imagick can process them." >&2
    exit 1
  fi
fi

echo "Build completed successfully."
