"""Ejecuta una fuente, registra la ejecución en nexo.runs y alerta si algo salió mal."""

import fcntl
import os
import re
import statistics
import subprocess
from datetime import datetime, timezone

from . import alerts, cars, db
from .config import DROP_RATIO, LOG_DIR, ROOT
from .fmt import label, num

TIMEOUT_EXIT = 124


def run(source):
    conn = db.connect()
    no_sites = source.dealers and cars.hosts(conn, source) == ("-",)
    conn.close()
    if no_sites:
        print(f"{source.id}: no hay sitios activos")
        return None

    lock = open(f"/tmp/nexo-run-{source.id}.lock", "w")
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        print(f"{source.id} ya está corriendo")
        return None

    conn = db.connect()
    started = db.one(conn, "SELECT NOW() AS now")["now"]
    _, run_id = db.execute(
        conn, "INSERT INTO nexo.runs (source_id, started_at) VALUES (%s, %s)", (source.id, started)
    )
    # Una ejecución puede durar horas; no dejar la conexión ociosa mientras tanto.
    conn.close()

    LOG_DIR.mkdir(exist_ok=True)
    log_path = LOG_DIR / f"{source.id}.log"
    with log_path.open("a") as log:
        offset = log.tell()
        _mark(log, f"START {source.id}")
        exit_code = _call(source.command, log, source.timeout_hours * 3600)
        for step in source.after:
            _step(step, log)
        _mark(log, f"END {source.id} exit_code={exit_code}")
    with log_path.open(errors="replace") as log:
        log.seek(offset)
        output = log.read()

    finish_reason, log_errors, excerpt = _parse_output(output)
    if exit_code == TIMEOUT_EXIT:
        finish_reason = "timeout"

    conn = db.connect()
    seen, inserted = cars.touched_since(conn, source, started)
    usual = _usual_seen(conn, source)
    outcome = _classify(exit_code, seen, usual)
    db.execute(
        conn,
        """
        UPDATE nexo.runs SET finished_at=NOW(), exit_code=%s, outcome=%s, seen=%s, inserted=%s,
          log_errors=%s, finish_reason=%s, error_excerpt=%s
        WHERE id=%s
        """,
        (exit_code, outcome, seen, inserted, log_errors, finish_reason, excerpt[:500] or None, run_id),
    )
    _notify(conn, source, outcome, exit_code, seen, usual, excerpt)
    conn.close()

    print(f"{source.id}: {outcome} seen={seen} inserted={inserted} exit_code={exit_code}")
    return outcome


def _mark(log, text):
    log.write(f"===== {datetime.now(timezone.utc):%Y-%m-%d %H:%M:%S} UTC {text} =====\n")
    log.flush()


def _call(command, log, timeout):
    try:
        return subprocess.run(command, cwd=ROOT, stdout=log, stderr=subprocess.STDOUT, timeout=timeout).returncode
    except subprocess.TimeoutExpired:
        log.write(f"nexo: se cortó por superar {timeout // 3600} h\n")
        return TIMEOUT_EXIT


def _step(step, log):
    kind, *args = step
    _mark(log, f"STEP {' '.join(args)}")
    if kind == "mysql-file":
        env = {**os.environ, "MYSQL_PWD": os.getenv("MYSQL_PASSWORD", "")}
        with open(ROOT / args[0]) as sql:
            subprocess.run(
                ["mysql", "-h", os.getenv("MYSQL_HOST", "localhost"), "-P", os.getenv("MYSQL_PORT", "3306"),
                 "-u", os.getenv("MYSQL_USER", "trikio"), "-D", os.getenv("MYSQL_DATABASE", "roodos")],
                stdin=sql, stdout=log, stderr=subprocess.STDOUT, env=env, timeout=1800,
            )
    else:
        _call(args, log, 1800)


def _parse_output(output):
    """finish_reason y cantidad de errores de las estadísticas de Scrapy, y la última línea de error."""
    finish = re.findall(r"'finish_reason': '([^']+)'", output)
    errors = re.findall(r"'log_count/ERROR': (\d+)", output)
    error_lines = [
        line.strip() for line in output.splitlines()
        if " ERROR: " in line or re.match(r"^\w*(Error|Exception)\b", line.strip())
    ]
    return (
        finish[-1] if finish else None,
        int(errors[-1]) if errors else None,
        error_lines[-1] if error_lines else "",
    )


def _usual_seen(conn, source):
    """Mediana de avisos vistos en las últimas 3 ejecuciones buenas, o None si no hay."""
    rows = db.all(
        conn,
        "SELECT seen FROM nexo.runs WHERE source_id=%s AND outcome='ok' ORDER BY started_at DESC LIMIT 3",
        (source.id,),
    )
    return statistics.median(row["seen"] for row in rows) if rows else None


def _classify(exit_code, seen, usual):
    # Un corte por tiempo con avisos guardados es una ejecución parcial, no una falla.
    if exit_code not in (0, TIMEOUT_EXIT) or (exit_code == TIMEOUT_EXIT and seen == 0):
        return "failed"
    if seen == 0:
        return "empty"
    if usual and seen < DROP_RATIO * usual:
        return "drop"
    return "ok"


def _notify(conn, source, outcome, exit_code, seen, usual, excerpt):
    name = label(source)
    if outcome == "ok":
        alerts.resolve(conn, "run", source.id, f"{name} volvió a funcionar: {num(seen)} avisos.")
        return
    detail = f"\n{excerpt[:300]}" if excerpt else ""
    if outcome == "failed":
        message = f"{name}: el scraper falló (código {exit_code}).{detail}"
    elif outcome == "empty":
        message = f"{name}: terminó sin traer avisos.{detail}"
    else:
        message = f"{name}: trajo {num(seen)} avisos, menos de la mitad de lo habitual ({num(usual)})."
    alerts.open_alert(conn, "run", source.id, message)
