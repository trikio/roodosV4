#!/usr/bin/env bash
# Chequeo de salud de Roodos (cron cada 15 minutos). Avisa por ntfy cuando algo falla, cuando
# se recupera, y cada REMIND_AFTER segundos mientras siga fallando.
#
# Configuración en /etc/roodos-healthcheck.env (fuera del repo, el tema de ntfy es privado):
#   NTFY_TOPIC=roodos-...        tema de ntfy.sh al que se suscribe la app del celular
#   COUNTRIES="pe cl ec mx"      opcional
#   MAX_INDEX_AGE=10800          opcional: segundos sin reindexar antes de alertar

set -u

CONFIG=/etc/roodos-healthcheck.env
STATE_DIR=/var/lib/roodos-healthcheck
REMIND_AFTER=21600

[ -f "$CONFIG" ] && . "$CONFIG"
NTFY_TOPIC=${NTFY_TOPIC:-}
NTFY_SERVER=${NTFY_SERVER:-https://ntfy.sh}
COUNTRIES=${COUNTRIES:-"pe cl ec mx"}
MAX_INDEX_AGE=${MAX_INDEX_AGE:-10800}

mkdir -p "$STATE_DIR"
now=$(date +%s)
failures=()

port_open() {
    timeout 5 bash -c "</dev/tcp/$1/$2" 2>/dev/null
}

port_open 127.0.0.1 9306 || failures+=("Manticore no responde en 127.0.0.1:9306: las búsquedas de autos dan 500")
# The roodos database lives on another machine, which opens a reverse SSH tunnel to this port.
port_open 127.0.0.1 3307 || failures+=("Túnel MySQL caído (127.0.0.1:3307): la reindexación horaria falla y los avisos se congelan")

for country in $COUNTRIES; do
    index_file=/var/lib/manticore/car_${country}.spa
    if [ -f "$index_file" ]; then
        age=$(( now - $(stat -c %Y "$index_file") ))
        if [ "$age" -gt "$MAX_INDEX_AGE" ]; then
            failures+=("Índice car_${country} sin actualizar hace $(( age / 3600 )) h")
        fi
    else
        failures+=("No existe el índice car_${country}")
    fi

    code=$(curl -s -o /dev/null -m 20 -w '%{http_code}' -A 'roodos-healthcheck' "https://autos.roodos.${country}/")
    [ "$code" = "200" ] || failures+=("https://autos.roodos.${country}/ responde ${code}")
done

notify() {
    local title=$1 priority=$2 tags=$3 body=$4
    echo "$(date '+%F %T') ${title}: ${body//$'\n'/ | }"
    [ -n "$NTFY_TOPIC" ] || return 0
    curl -s -m 15 -o /dev/null \
        -H "Title: ${title}" -H "Priority: ${priority}" -H "Tags: ${tags}" \
        --data-binary "$body" "${NTFY_SERVER}/${NTFY_TOPIC}"
}

current=$(printf '%s\n' "${failures[@]+"${failures[@]}"}" | sed '/^$/d' | sort)
previous=$(cat "$STATE_DIR/failures" 2>/dev/null || true)
last_alert=$(cat "$STATE_DIR/last_alert" 2>/dev/null || echo 0)

if [ -n "$current" ]; then
    if [ "$current" != "$previous" ] || [ $(( now - last_alert )) -ge "$REMIND_AFTER" ]; then
        notify "Roodos: problema detectado" urgent warning "$current"
        echo "$now" > "$STATE_DIR/last_alert"
    fi
elif [ -n "$previous" ]; then
    notify "Roodos: todo OK de nuevo" default white_check_mark "Se recuperó: ${previous}"
fi

printf '%s' "$current" > "$STATE_DIR/failures"
