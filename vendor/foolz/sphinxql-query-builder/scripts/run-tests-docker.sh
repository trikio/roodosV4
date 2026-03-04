#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMAGE_NAME="${IMAGE_NAME:-sphinxql-test-runner:local}"
BUILD_LOG="$(mktemp)"
USE_ISOLATED_DOCKER_CONFIG=0
TEMP_DOCKER_CONFIG=""

cleanup() {
  rm -f "$BUILD_LOG"
  if [ -n "$TEMP_DOCKER_CONFIG" ] && [ -d "$TEMP_DOCKER_CONFIG" ]; then
    rm -rf "$TEMP_DOCKER_CONFIG"
  fi
}
trap cleanup EXIT

docker_cmd() {
  if [ "$USE_ISOLATED_DOCKER_CONFIG" -eq 1 ]; then
    DOCKER_CONFIG="$TEMP_DOCKER_CONFIG" docker "$@"
  else
    docker "$@"
  fi
}

if ! docker_cmd build -f "$ROOT_DIR/Dockerfile.test" -t "$IMAGE_NAME" "$ROOT_DIR" 2>&1 | tee "$BUILD_LOG"; then
  if grep -Eq "error getting credentials|docker-credential-.*not found" "$BUILD_LOG"; then
    echo "Docker credential helper failure detected, retrying build with isolated anonymous Docker config." >&2
    TEMP_DOCKER_CONFIG="$(mktemp -d)"
    cat >"$TEMP_DOCKER_CONFIG/config.json" <<'JSON'
{
  "auths": {}
}
JSON
    USE_ISOLATED_DOCKER_CONFIG=1
    docker_cmd build -f "$ROOT_DIR/Dockerfile.test" -t "$IMAGE_NAME" "$ROOT_DIR"
  else
    exit 1
  fi
fi

DOCKER_RUN_ARGS=(
  run
  --rm
  -u "$(id -u):$(id -g)"
  -e HOME=/tmp/home
  -e SOURCE_DIR=/src
  -v "$ROOT_DIR:/src:ro"
)

if [ -t 1 ]; then
  DOCKER_RUN_ARGS+=(-t)
fi

docker_cmd "${DOCKER_RUN_ARGS[@]}" "$IMAGE_NAME" bash /src/scripts/run-tests-in-container.sh
