#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="/home/trikio/roodos_v4/nexo"
LOG_DIR="$ROOT_DIR/logs"
MAX_BYTES="${ROODOS_LOG_MAX_BYTES:-26214400}"
KEEP_BYTES="${ROODOS_LOG_KEEP_BYTES:-5242880}"
TOTAL_MAX_KB="${ROODOS_LOG_TOTAL_MAX_KB:-1048576}"
GUARD_LOG="$LOG_DIR/log_guard.log"
LOCK_FILE="/tmp/roodos_log_guard.lock"

mkdir -p "$LOG_DIR"

exec 9>"$LOCK_FILE"
flock -n 9 || exit 0

disk_used_pct="$(df -P "$ROOT_DIR" | awk 'NR==2 {gsub(/%/, "", $5); print $5}')"
if [ "${disk_used_pct:-0}" -ge 90 ]; then
  MAX_BYTES=5242880
  KEEP_BYTES=1048576
fi

log() {
  printf '%s %s\n' "$(date -u '+%Y-%m-%d %H:%M:%S UTC')" "$*" >> "$GUARD_LOG"
}

trim_file() {
  local file="$1"
  local size tmp
  size="$(stat -c%s "$file" 2>/dev/null || echo 0)"
  if [ "$size" -le "$MAX_BYTES" ]; then
    return 0
  fi

  tmp="$(mktemp "$LOG_DIR/.log_guard.XXXXXX")"
  if tail -c "$KEEP_BYTES" "$file" > "$tmp"; then
    {
      printf '===== log_guard trimmed %s from %s bytes to last %s bytes =====\n' \
        "$(date -u '+%Y-%m-%d %H:%M:%S UTC')" "$size" "$KEEP_BYTES"
      cat "$tmp"
    } > "$file"
    log "trimmed file=$file old_bytes=$size keep_bytes=$KEEP_BYTES"
  fi
  rm -f "$tmp"
}

find "$LOG_DIR" -type f \( -name '*.log' -o -name '*.out' -o -name '*.err' \) -print0 |
  while IFS= read -r -d '' file; do
    trim_file "$file"
  done

total_kb="$(du -sk "$LOG_DIR" | awk '{print $1}')"
if [ "$total_kb" -gt "$TOTAL_MAX_KB" ]; then
  log "warning total_log_dir_kb=$total_kb exceeds limit_kb=$TOTAL_MAX_KB"
fi

trim_file "$GUARD_LOG"
