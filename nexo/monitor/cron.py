"""Bloque de crontab de nexo, generado desde el registro de fuentes.

Solo se toca lo que está entre BEGIN NEXO y END NEXO; el resto del crontab del usuario queda igual.
"""

import subprocess
from datetime import datetime

from . import sources
from .config import LOG_DIR, ROOT

BEGIN = "# BEGIN NEXO (generado por bin/nexo cron; se edita en nexo/monitor/cron.py y sources.py)"
END = "# END NEXO"

# Líneas del crontab anterior (OpenClaw) que este bloque reemplaza.
LEGACY_MARKER = "/.openclaw/workspace/roodos_scraper/"
LEGACY_KEEP = "send_trikio_"
LEGACY_COMMENTS = {
    "# --- Houses (daily) ---",
    "# --- Autos (weekly diversified across Mon-Sat, CRON_TZ=America/Santiago) ---",
    "# --- Autos stale guard after reboot ---",
    "# --- Roodos autos dashboard after reboot ---",
}


def block():
    nexo = f"{ROOT}/bin/nexo"
    log = f">> {LOG_DIR}/nexo-cron.log 2>&1"
    lines = [BEGIN, "# Autos: una línea por fuente"]
    for source in sources.SOURCES:
        prefix = "" if source.enabled else "# (desactivada) "
        lines.append(f"{prefix}{source.schedule} {nexo} run {source.id} {log}")
    lines += [
        "# Monitoreo",
        f"0 6 * * * {nexo} images {log}",
        f"0 8 * * * {nexo} daily {log}",
        f"30 9 * * * {nexo} hunt {log}",
        f"*/10 * * * * {nexo} update {log}",
        "# Casas y mantenimiento",
        f"20 2 * * * {ROOT}/run_houses_doomos_cl.sh",
        f"0 2 * * 1 {ROOT}/run_thumbs_batches.sh >> {LOG_DIR}/thumbs_batch.log 2>&1",
        f"*/15 * * * * {ROOT}/watchdog_houses_thumbs_cl.sh",
        f"*/5 * * * * {ROOT}/scripts/roodos_log_guard.sh >/dev/null 2>&1",
        f"@reboot sleep 120; {ROOT}/scripts/kick_autos_refresh_if_stale.sh >> {LOG_DIR}/autos_stale_guard.log 2>&1",
        END,
    ]
    return lines


def merged(current):
    """El crontab actual sin el bloque anterior ni las líneas de OpenClaw que reemplaza, más el bloque nuevo."""
    kept, inside = [], False
    for line in current.splitlines():
        if line == BEGIN:
            inside = True
        elif line == END:
            inside = False
        elif not inside and line not in LEGACY_COMMENTS and (LEGACY_MARKER not in line or LEGACY_KEEP in line):
            kept.append(line)
    while kept and not kept[-1].strip():
        kept.pop()
    return "\n".join(kept + [""] + block()) + "\n"


def install():
    current = subprocess.run(["crontab", "-l"], capture_output=True, text=True).stdout
    LOG_DIR.mkdir(exist_ok=True)
    (LOG_DIR / f"crontab.{datetime.now():%Y%m%d-%H%M%S}.bak").write_text(current)
    subprocess.run(["crontab", "-"], input=merged(current), text=True, check=True)
    print("crontab actualizado")
