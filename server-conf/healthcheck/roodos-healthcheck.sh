#!/usr/bin/env bash
# Chequeo de salud de Roodos (cron cada 15 minutos). Avisa por Telegram y/o ntfy cuando algo falla,
# cuando se recupera, y cada REMIND_AFTER segundos mientras siga fallando. Corre en el servidor web,
# así que avisa aunque nexo (la máquina de la casa, que manda sus propias alertas) esté caído.
#
# Configuración en /etc/roodos-healthcheck.env (fuera del repo, las claves son privadas):
#   TELEGRAM_BOT_TOKEN=...       el mismo bot y canal que usa nexo (están en nexo/.env)
#   TELEGRAM_CHAT_ID=...
#   NTFY_TOPIC=roodos-...        opcional: tema de ntfy.sh al que se suscribe la app del celular
#   COUNTRIES="pe cl ec mx"      opcional
#   MAX_INDEX_AGE=10800          opcional: segundos sin reindexar antes de alertar

set -u

CONFIG=/etc/roodos-healthcheck.env
STATE_DIR=/var/lib/roodos-healthcheck
REMIND_AFTER=21600

[ -f "$CONFIG" ] && . "$CONFIG"
NTFY_TOPIC=${NTFY_TOPIC:-}
TELEGRAM_BOT_TOKEN=${TELEGRAM_BOT_TOKEN:-}
TELEGRAM_CHAT_ID=${TELEGRAM_CHAT_ID:-}
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
# Que el puerto esté abierto no basta: sshd puede mantenerlo escuchando con la sesión muerta (nexo sin
# red). MySQL manda su saludo apenas se conecta uno, así que se exige recibirlo.
mysql_greets() {
    timeout 8 bash -c "exec 3<>/dev/tcp/$1/$2 && head -c 5 <&3 | wc -c" 2>/dev/null | tr -d ' ' | grep -q '^5$'
}
mysql_greets 127.0.0.1 3307 || failures+=("Nexo no responde por el túnel MySQL (127.0.0.1:3307): ¿se cayó el wifi de la casa? La reindexación horaria falla y los avisos se congelan")

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
    if [ -n "$TELEGRAM_BOT_TOKEN" ] && [ -n "$TELEGRAM_CHAT_ID" ]; then
        curl -s -m 15 -o /dev/null "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
            --data-urlencode "chat_id=${TELEGRAM_CHAT_ID}" \
            --data-urlencode "text=${title}"$'\n'"${body}"
    fi
    if [ -n "$NTFY_TOPIC" ]; then
        curl -s -m 15 -o /dev/null \
            -H "Title: ${title}" -H "Priority: ${priority}" -H "Tags: ${tags}" \
            --data-binary "$body" "${NTFY_SERVER}/${NTFY_TOPIC}"
    fi
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
