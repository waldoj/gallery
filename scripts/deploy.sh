#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build"

if [ ! -d "${BUILD_DIR}" ]; then
  echo "ERROR: Build directory '${BUILD_DIR}' not found. Run 'composer export' first." >&2
  exit 1
fi

readarray -t deploy_config < <(
  php -r "
    require '${ROOT_DIR}/settings.inc.php';
    \$host = isset(\$deployment_host) ? trim(\$deployment_host) : '';
    \$path = isset(\$deployment_path) ? trim(\$deployment_path) : '';
    if (\$host === '' || \$path === '') {
        exit(1);
    }
    echo \$host, PHP_EOL, \$path;
  " 2>/dev/null
) || {
  echo "ERROR: Set \$deployment_host and \$deployment_path in settings.inc.php before deploying." >&2
  exit 1
}

DEPLOY_HOST="${deploy_config[0]}"
DEPLOY_PATH="${deploy_config[1]}"

if [ -z "${DEPLOY_HOST}" ] || [ -z "${DEPLOY_PATH}" ]; then
  echo "ERROR: Invalid deployment configuration in settings.inc.php." >&2
  exit 1
fi

echo "==> Creating remote directory ${DEPLOY_PATH}"
ssh "${DEPLOY_HOST}" "mkdir -p '${DEPLOY_PATH}'"

echo "==> Copying contents of build/ to ${DEPLOY_HOST}:${DEPLOY_PATH}"
scp -r "${BUILD_DIR}/." "${DEPLOY_HOST}:${DEPLOY_PATH}/"

echo "Deployment complete."
