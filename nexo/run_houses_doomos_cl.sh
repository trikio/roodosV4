#!/usr/bin/env bash
set -euo pipefail

cd /home/trikio/roodos_v4/nexo
mkdir -p logs

set -a
[ -f .env ] && source .env
set +a

LOG_FILE="/home/trikio/roodos_v4/nexo/logs/houses_doomos_cl.log"
LOCK_FILE="/tmp/houses_doomos_cl.lock"

flock -n "$LOCK_FILE" bash -lc '
  echo "===== $(date -u "+%Y-%m-%d %H:%M:%S UTC") START doomos_cl =====" >> "$0"
  timeout 4h /usr/bin/python3 /home/trikio/roodos_v4/nexo/scripts/import_houses_doomos_cl.py >> "$0" 2>&1 || true
  # Push house thumbs to Cloudflare CDN after import
  timeout 4h /usr/bin/python3 /home/trikio/roodos_v4/nexo/scripts/process_and_push_house_thumbs.py --country cl --limit 200000 >> "$0" 2>&1 || true
  timeout 4h /usr/bin/python3 /home/trikio/roodos_v4/nexo/scripts/process_and_push_house_thumbs.py --country lab --limit 200000 >> "$0" 2>&1 || true
  echo "===== $(date -u "+%Y-%m-%d %H:%M:%S UTC") END doomos_cl =====" >> "$0"
' "$LOG_FILE"
