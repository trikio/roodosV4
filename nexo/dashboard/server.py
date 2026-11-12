from __future__ import annotations

import os
import subprocess
from datetime import datetime
from pathlib import Path

import mysql.connector
from dotenv import load_dotenv
from flask import Flask, jsonify, request, send_from_directory


ROOT = Path(__file__).resolve().parents[1]
STATIC_DIR = Path(__file__).resolve().parent / "static"
LOG_PATH = ROOT / "logs" / "autos_emergency_refresh_latest.log"
REFRESH_SCRIPT = ROOT / "run_autos_emergency_refresh.sh"
REFRESH_SERVICE = "roodos-autos-emergency-refresh.service"

load_dotenv(ROOT / ".env")

app = Flask(__name__, static_folder=str(STATIC_DIR), static_url_path="")


def db_conn():
    return mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "localhost"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        user=os.getenv("MYSQL_USER", "root"),
        password=os.getenv("MYSQL_PASSWORD", ""),
        database=os.getenv("MYSQL_DATABASE", "roodos"),
    )


def fetch_all(query: str, params: tuple = ()):
    conn = db_conn()
    try:
        cur = conn.cursor(dictionary=True)
        cur.execute(query, params)
        rows = cur.fetchall()
        cur.close()
        return rows
    finally:
        conn.close()


def fetch_one(query: str, params: tuple = ()):
    rows = fetch_all(query, params)
    return rows[0] if rows else {}


def run_systemctl(*args: str):
    try:
        proc = subprocess.run(
            ["systemctl", "--user", *args],
            cwd=ROOT,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=8,
        )
        return {
            "ok": proc.returncode == 0,
            "code": proc.returncode,
            "stdout": proc.stdout.strip(),
            "stderr": proc.stderr.strip(),
        }
    except Exception as exc:
        return {"ok": False, "code": -1, "stdout": "", "stderr": str(exc)}


def service_state():
    active = run_systemctl("is-active", REFRESH_SERVICE)
    status = run_systemctl(
        "show",
        REFRESH_SERVICE,
        "--property=ActiveState,SubState,MainPID,ExecMainStartTimestamp",
        "--no-page",
    )
    data = {
        "active": active["stdout"] == "active",
        "active_label": active["stdout"] or "unknown",
        "raw": status["stdout"],
        "main_pid": None,
        "started_at": None,
    }
    for line in status["stdout"].splitlines():
        if "=" not in line:
            continue
        key, value = line.split("=", 1)
        if key == "MainPID":
            data["main_pid"] = value
        elif key == "ExecMainStartTimestamp":
            data["started_at"] = value
        elif key == "SubState":
            data["sub_state"] = value
        elif key == "ActiveState":
            data["active_state"] = value
    return data


def tail_log(max_lines: int = 180):
    if not LOG_PATH.exists():
        return []
    lines = LOG_PATH.read_text(errors="replace").splitlines()
    return lines[-max_lines:]


def extract_steps(lines: list[str]):
    steps = []
    for line in lines:
        if "===== " not in line:
            continue
        if " START " in line or " END " in line:
            steps.append(line)
    return steps[-40:]


@app.get("/")
def index():
    return send_from_directory(STATIC_DIR, "index.html")


@app.get("/api/status")
def api_status():
    country_rows = fetch_all(
        """
        SELECT
          id_country AS country,
          COUNT(*) AS total,
          SUM(updated >= NOW() - INTERVAL 1 DAY) AS fresh_1d,
          SUM(updated >= NOW() - INTERVAL 3 DAY) AS fresh_3d,
          SUM(updated >= NOW() - INTERVAL 7 DAY) AS fresh_7d,
          SUM(updated < NOW() - INTERVAL 7 DAY) AS stale_7d,
          MAX(updated) AS max_updated
        FROM cars
        GROUP BY id_country
        ORDER BY id_country
        """
    )
    source_rows = fetch_all(
        """
        SELECT
          COALESCE(NULLIF(nexo_id, ''), 'Sin fuente') AS source,
          id_country AS country,
          COUNT(*) AS total,
          SUM(updated >= NOW() - INTERVAL 1 DAY) AS fresh_1d,
          SUM(updated >= NOW() - INTERVAL 7 DAY) AS fresh_7d,
          MAX(updated) AS max_updated
        FROM cars
        GROUP BY source, country
        ORDER BY fresh_1d DESC, total DESC
        LIMIT 80
        """
    )
    summary = fetch_one(
        """
        SELECT
          COUNT(*) AS total,
          SUM(updated >= NOW() - INTERVAL 1 DAY) AS fresh_1d,
          SUM(updated >= NOW() - INTERVAL 7 DAY) AS fresh_7d,
          SUM(updated < NOW() - INTERVAL 7 DAY) AS stale_7d,
          MAX(updated) AS max_updated
        FROM cars
        """
    )
    lines = tail_log()
    return jsonify(
        {
            "generated_at": datetime.now().isoformat(timespec="seconds"),
            "service": service_state(),
            "summary": summary,
            "countries": country_rows,
            "sources": source_rows,
            "steps": extract_steps(lines),
            "log_tail": lines[-80:],
        }
    )


@app.get("/api/nexo")
def api_nexo():
    """Fuentes con su última foto diaria y ejecución, alertas abiertas y candidatas (base nexo)."""
    sources = fetch_all(
        """
        SELECT s.id, s.name, s.country, s.schedule, s.full_crawl,
               d.active, d.seen_24h, d.new_24h, d.stale, d.images_checked, d.images_broken,
               DATE_FORMAT(d.last_seen, '%Y-%m-%d') AS last_seen,
               DATE_FORMAT(r.started_at, '%Y-%m-%d %H:%i') AS last_run, r.outcome, r.seen AS last_run_seen,
               r.error_excerpt
        FROM nexo.sources s
        LEFT JOIN nexo.daily_stats d
          ON d.source_id = s.id AND d.day = (SELECT MAX(day) FROM nexo.daily_stats)
        LEFT JOIN nexo.runs r ON r.id = (
          SELECT id FROM nexo.runs WHERE source_id = s.id AND finished_at IS NOT NULL
          ORDER BY started_at DESC LIMIT 1
        )
        WHERE s.enabled = 1
        ORDER BY s.country, d.active DESC
        """
    )
    alerts = fetch_all(
        """
        SELECT kind, source_id, message, DATE_FORMAT(opened_at, '%Y-%m-%d %H:%i') AS opened_at
        FROM nexo.alerts WHERE resolved_at IS NULL ORDER BY opened_at DESC
        """
    )
    candidates = fetch_all(
        """
        SELECT host, country, url, score, status, DATE_FORMAT(first_seen, '%Y-%m-%d') AS first_seen
        FROM nexo.candidates WHERE status <> 'rejected' ORDER BY first_seen DESC LIMIT 50
        """
    )
    return jsonify({"sources": sources, "alerts": alerts, "candidates": candidates})


@app.post("/api/refresh")
def api_refresh():
    state = service_state()
    if state["active"]:
        return jsonify({"ok": True, "message": "Refresh already running", "service": state})

    unit = request.json.get("unit") if request.is_json else None
    service = unit or REFRESH_SERVICE.replace(".service", "")
    proc = subprocess.run(
        [
            "systemd-run",
            "--user",
            f"--unit={service}",
            "--same-dir",
            str(REFRESH_SCRIPT),
            str(LOG_PATH),
        ],
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        timeout=10,
    )
    return jsonify(
        {
            "ok": proc.returncode == 0,
            "code": proc.returncode,
            "stdout": proc.stdout.strip(),
            "stderr": proc.stderr.strip(),
            "service": service_state(),
        }
    )


if __name__ == "__main__":
    host = os.getenv("ROODOS_DASHBOARD_HOST", "0.0.0.0")
    port = int(os.getenv("ROODOS_DASHBOARD_PORT", "8765"))
    app.run(host=host, port=port, debug=False)
