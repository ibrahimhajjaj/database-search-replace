#!/usr/bin/env bash
# Builds the distributable plugin zip with production dependencies only.
#
# The staging tree is assembled from an explicit allowlist so development and
# build tooling can never leak into a release. Run from a clean checkout.
set -euo pipefail

cd "$( dirname "$0" )/.."

SLUG="database-search-replace"
VERSION="$( grep -m1 "Stable tag:" readme.txt | awk '{print $3}' )"
BUILD_DIR="dist/build"
STAGE_DIR="${BUILD_DIR}/${SLUG}"
ZIP_PATH="dist/${SLUG}-${VERSION}.zip"

echo "Packaging ${SLUG} ${VERSION}"

rm -rf "${BUILD_DIR}" "${ZIP_PATH}"
mkdir -p "${STAGE_DIR}"

echo "Compiling assets"
npm run build >/dev/null

echo "Copying plugin files"
cp "${SLUG}.php" uninstall.php readme.txt LICENSE composer.json composer.lock "${STAGE_DIR}/"
cp -R includes "${STAGE_DIR}/includes"
cp -R build "${STAGE_DIR}/build"
cp -R languages "${STAGE_DIR}/languages"
cp -R assets "${STAGE_DIR}/assets"

echo "Installing production dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --working-dir="${STAGE_DIR}" >/dev/null
# composer.json stays so the shipped vendor directory is self-describing;
# the lock file is development-only.
rm -f "${STAGE_DIR}/composer.lock"

# Action Scheduler ships tests and docs that have no place in a release.
find "${STAGE_DIR}/vendor" -type d \( -name tests -o -name docs -o -name .github \) -prune -exec rm -rf {} + 2>/dev/null || true
find "${STAGE_DIR}/vendor" -type f \( -name "*.md" -o -name "phpunit.xml*" -o -name ".gitignore" \) -delete 2>/dev/null || true

echo "Creating ${ZIP_PATH}"
( cd "${BUILD_DIR}" && zip -rq "${SLUG}.zip" "${SLUG}" -x "*.DS_Store" )
mv "${BUILD_DIR}/${SLUG}.zip" "${ZIP_PATH}"

SIZE="$( du -h "${ZIP_PATH}" | awk '{print $1}' )"
echo "Done: ${ZIP_PATH} (${SIZE})"
