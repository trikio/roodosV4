#!/usr/bin/env bash
set -euo pipefail
cd /home/trikio/roodos_v4/nexo
mkdir -p logs
set -a
[ -f .env ] && source .env
set +a
LOG=logs/houses_thumbs_lab.log
MAX_LOG_BYTES=$((50 * 1024 * 1024))
if [ -f "$LOG" ] && [ "$(stat -c%s "$LOG")" -gt "$MAX_LOG_BYTES" ]; then
  mv "$LOG" "${LOG}.1"
fi
echo "===== $(date -u '+%Y-%m-%d %H:%M:%S UTC') START houses thumbs lab =====" >> "$LOG"
last_pending=""
while true; do
  pending=$(MYSQL_PWD="$MYSQL_PASSWORD" mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" -N -B -D "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM houses WHERE id_country='lab' AND image IS NOT NULL AND image<>'' AND image LIKE 'http%' AND image NOT LIKE 'https://img.roodos.com/%';")
  echo "pending=$pending" >> "$LOG"
  if [ "$pending" -le 0 ]; then
    break
  fi
  python3 -u scripts/process_and_push_house_thumbs.py --country lab --limit 5000 >> "$LOG" 2>&1 || true
  next_pending=$(MYSQL_PWD="$MYSQL_PASSWORD" mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" -N -B -D "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM houses WHERE id_country='lab' AND image IS NOT NULL AND image<>'' AND image LIKE 'http%' AND image NOT LIKE 'https://img.roodos.com/%';")
  if [ "$next_pending" = "$pending" ] && [ "$last_pending" = "$pending" ]; then
    echo "no progress pending=$pending; stopping to avoid endless retries" >> "$LOG"
    break
  fi
  last_pending="$pending"
  sleep 1
done
echo "===== $(date -u '+%Y-%m-%d %H:%M:%S UTC') END houses thumbs lab =====" >> "$LOG"
