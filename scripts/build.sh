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

if [ -f "${ROOT_DIR}/library.yml" ]; then
  echo "==> Updating library metadata"
  php "${ROOT_DIR}/updater.php"
fi

echo "Build completed successfully."
