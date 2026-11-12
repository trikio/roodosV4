#!/usr/bin/env bash
set -u

cd /home/trikio/roodos_v4/nexo || exit 1
mkdir -p logs

set -a
[ -f .env ] && source .env
set +a

LOCK_FILE="/tmp/roodos_peru_content_refresh.lock"
LOG_FILE="${1:-logs/peru_content_refresh_$(date -u '+%Y%m%d_%H%M%S').log}"

run_step() {
  local name="$1"
  shift
  echo "===== $(date -u '+%Y-%m-%d %H:%M:%S UTC') START $name =====" >> "$LOG_FILE"
  "$@" >> "$LOG_FILE" 2>&1
  local code=$?
  echo "===== $(date -u '+%Y-%m-%d %H:%M:%S UTC') END $name exit_code=$code =====" >> "$LOG_FILE"
  return 0
}

run_scrapy() {
  local spider="$1"
  shift
  run_step "$spider" timeout 4h /home/trikio/.local/bin/scrapy crawl "$spider" -L INFO "$@"
}

run_python() {
  local name="$1"
  shift
  run_step "$name" timeout 4h /usr/bin/python3 "$@"
}

(
  flock -n 9 || {
    echo "Peru content refresh already running: $LOCK_FILE"
    exit 0
  }

  echo "===== $(date -u '+%Y-%m-%d %H:%M:%S UTC') PERU CONTENT REFRESH START =====" >> "$LOG_FILE"

  run_scrapy autos_peruautos_pe
  run_scrapy autos_koopa_pe
  run_python sync_peru_auto_sitemap_status_koopa scripts/sync_peru_auto_sitemap_status.py --source Koopa
  run_scrapy autos_neoauto_pe
  run_scrapy autos_autoland_pe
  run_scrapy autos_tuauto_pe
  run_scrapy autos_autosell_pe
  run_scrapy autocosmos_peru
  run_python sync_peru_auto_sitemap_status_autocosmos scripts/sync_peru_auto_sitemap_status.py --source Autocosmos
  run_scrapy autos_wigo_pe
  run_python sync_peru_auto_sitemap_status_wigo scripts/sync_peru_auto_sitemap_status.py --source Wigo
  run_scrapy autos_leonford_pe
  run_python sync_peru_auto_sitemap_status_leonford scripts/sync_peru_auto_sitemap_status.py --source LeonFord
  run_scrapy autos_autosummit_pe
  run_python sync_peru_auto_sitemap_status_autosummit scripts/sync_peru_auto_sitemap_status.py --source AutoSummit


  echo "===== $(date -u '+%Y-%m-%d %H:%M:%S UTC') PERU CONTENT REFRESH END =====" >> "$LOG_FILE"
) 9>"$LOCK_FILE"
