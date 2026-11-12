#!/usr/bin/env bash
set -euo pipefail

cd /home/trikio/roodos_v4/nexo
mkdir -p logs

LOG="logs/car_landings_embeddings.log"
BATCH="${BATCH:-2000}"
EMBED_BATCH="${EMBED_BATCH:-128}"

set -a
[ -f .env ] && source .env
set +a

echo "===== $(date -u '+%Y-%m-%d %H:%M:%S UTC') START car_landings embeddings =====" >> "$LOG"

while true; do
  pending=$(MYSQL_PWD="$MYSQL_PASSWORD" mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" -N -B -D "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM car_landings WHERE embedding_json IS NULL;")
  echo "pending=$pending" >> "$LOG"
  if [ "$pending" -le 0 ]; then
    break
  fi

  python3 scripts/fill_landing_embeddings_ollama.py --table car_landings --limit "$BATCH" --embed-batch-size "$EMBED_BATCH" >> "$LOG" 2>&1 || true
done

echo "===== $(date -u '+%Y-%m-%d %H:%M:%S UTC') END car_landings embeddings =====" >> "$LOG"
