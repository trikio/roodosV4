#!/usr/bin/env bash
set -euo pipefail
cd /home/trikio/roodos_v4/nexo
mkdir -p logs

set -a
[ -f .env ] && source .env
set +a

while true; do
  pending=$(MYSQL_PWD="$MYSQL_PASSWORD" mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" -N -B -D "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM cars WHERE id_country='EC' AND ((image LIKE 'http%' AND image NOT LIKE 'https://img.roodos.com/%') OR ((image IS NULL OR image='') AND image_big LIKE 'http%' AND image_big NOT LIKE 'https://img.roodos.com/%'));")
  if [ "${pending}" -le 0 ]; then
    echo "$(date -u) done"
    break
  fi
  echo "$(date -u) pending=${pending}"
  python3 scripts/process_and_push_thumbs.py --limit 400 --country EC --only-absolute
  sleep 2
done
