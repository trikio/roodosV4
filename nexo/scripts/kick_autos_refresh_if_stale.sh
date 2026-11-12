#!/usr/bin/env bash
set -euo pipefail

cd /home/trikio/roodos_v4/nexo
mkdir -p logs

LOG_FILE="logs/autos_stale_guard.log"
REFRESH_LOG="logs/autos_emergency_refresh_latest.log"
SERVICE_NAME="roodos-autos-emergency-refresh"
STALE_DAYS="${ROODOS_AUTOS_STALE_DAYS:-3}"

set -a
[ -f .env ] && source .env
set +a

stamp() {
  date -u '+%Y-%m-%d %H:%M:%S UTC'
}

if systemctl --user is-active --quiet "$SERVICE_NAME.service"; then
  echo "$(stamp) refresh already running" >> "$LOG_FILE"
  exit 0
fi

max_updated="$(
  MYSQL_PWD="$MYSQL_PASSWORD" mysql \
    -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" \
    -N -B -D "$MYSQL_DATABASE" \
    -e "SELECT COALESCE(MAX(updated), '1970-01-01 00:00:00') FROM cars WHERE id_country IN ('CL','PE','MX','ec','lab');"
)"

stale="$(
  MYSQL_PWD="$MYSQL_PASSWORD" mysql \
    -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" \
    -N -B -D "$MYSQL_DATABASE" \
    -e "SELECT COALESCE(MAX(updated), '1970-01-01 00:00:00') < (NOW() - INTERVAL ${STALE_DAYS} DAY) FROM cars WHERE id_country IN ('CL','PE','MX','ec','lab');"
)"

if [ "$stale" != "1" ]; then
  echo "$(stamp) fresh enough max_updated=$max_updated stale_days=$STALE_DAYS" >> "$LOG_FILE"
  exit 0
fi

echo "$(stamp) stale autos detected max_updated=$max_updated stale_days=$STALE_DAYS; starting refresh" >> "$LOG_FILE"
export XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"
systemd-run --user --unit="$SERVICE_NAME" --same-dir \
  /home/trikio/roodos_v4/nexo/run_autos_emergency_refresh.sh "$REFRESH_LOG" \
  >> "$LOG_FILE" 2>&1
