#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "==> Installing PHP dependencies"
composer install --no-interaction --prefer-dist --working-dir "${ROOT_DIR}"

echo "==> Running test suite"
php "${ROOT_DIR}/tests/run.php"

if [ -f "${ROOT_DIR}/library.yml" ]; then
  echo "==> Updating library metadata"
  php "${ROOT_DIR}/updater.php"
fi

echo "Build completed successfully."
