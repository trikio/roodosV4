#!/usr/bin/env bash
# Corre todas las fuentes de autos activas, una tras otra, con el runner de nexo (cada una queda
# registrada en nexo.runs). Lo lanzan el botón "Run" del dashboard y
# scripts/kick_autos_refresh_if_stale.sh cuando el servidor arranca con los datos atrasados.
set -u

cd /home/trikio/roodos_v4/nexo || exit 1
mkdir -p logs

LOCK_FILE="/tmp/roodos_autos_emergency_refresh.lock"
LOG_FILE="${1:-logs/autos_emergency_refresh_$(date -u '+%Y%m%d_%H%M%S').log}"

(
  flock -n 9 || {
    echo "Emergency refresh already running: $LOCK_FILE"
    exit 0
  }

  echo "===== $(date -u '+%Y-%m-%d %H:%M:%S UTC') EMERGENCY AUTOS REFRESH START =====" >> "$LOG_FILE"
  for source in $(bin/nexo sources --ids); do
    echo "===== $(date -u '+%Y-%m-%d %H:%M:%S UTC') START $source =====" >> "$LOG_FILE"
    bin/nexo run "$source" >> "$LOG_FILE" 2>&1
    echo "===== $(date -u '+%Y-%m-%d %H:%M:%S UTC') END $source exit_code=$? =====" >> "$LOG_FILE"
  done
  echo "===== $(date -u '+%Y-%m-%d %H:%M:%S UTC') EMERGENCY AUTOS REFRESH END =====" >> "$LOG_FILE"
) 9>"$LOCK_FILE"
