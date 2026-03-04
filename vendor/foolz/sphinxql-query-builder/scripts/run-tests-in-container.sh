#!/usr/bin/env bash
set -euo pipefail

SOURCE_DIR="${SOURCE_DIR:-/src}"
WORK_DIR="$(mktemp -d /tmp/sphinxql-work-XXXXXX)"

cleanup() {
  rm -rf "$WORK_DIR"
}
trap cleanup EXIT

cp -a "$SOURCE_DIR/." "$WORK_DIR"

export SEARCH_BUILD=MANTICORE
export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_HOME="${COMPOSER_HOME:-/tmp/composer}"
export HOME="${HOME:-/tmp/home}"

mkdir -p "$HOME/search"
pushd "$HOME/search" >/dev/null
"$WORK_DIR/tests/install.sh"
popd >/dev/null

cd "$WORK_DIR"
composer install --prefer-dist --no-interaction --no-progress
composer dump-autoload

cd "$WORK_DIR/tests"
./run.sh

cd "$WORK_DIR"
./vendor/bin/phpunit --configuration tests/travis/mysqli.phpunit.xml
./vendor/bin/phpunit --configuration tests/travis/pdo.phpunit.xml
