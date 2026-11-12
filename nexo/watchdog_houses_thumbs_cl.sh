#!/usr/bin/env bash
set -euo pipefail

cd /home/trikio/roodos_v4/nexo
mkdir -p logs
set -a
[ -f .env ] && source .env
set +a

LOG="/home/trikio/roodos_v4/nexo/logs/houses_thumbs_cl_watchdog.log"
RUNNER="/home/trikio/roodos_v4/nexo/run_houses_thumbs_cl_until_done.sh"

pending=$(MYSQL_PWD="$MYSQL_PASSWORD" mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" -N -B -D "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM houses WHERE id_country='CL' AND image IS NOT NULL AND image<>'' AND image LIKE 'http%' AND image NOT LIKE 'https://img.roodos.com/%';")

if [ "$pending" -le 0 ]; then
  echo "$(date -u '+%Y-%m-%d %H:%M:%S UTC') pending=0 nothing to do" >> "$LOG"
  exit 0
fi

if pgrep -af "run_houses_thumbs_cl_until_done.sh|process_and_push_house_thumbs.py --country CL" >/dev/null; then
  echo "$(date -u '+%Y-%m-%d %H:%M:%S UTC') pending=$pending already running" >> "$LOG"
  exit 0
fi

echo "$(date -u '+%Y-%m-%d %H:%M:%S UTC') pending=$pending restarting runner" >> "$LOG"
nohup "$RUNNER" >/dev/null 2>&1 &
