"""Revisión diaria: foto por fuente, avisos vencidos, alertas y resumen por Telegram."""

import shutil
import subprocess
from collections import defaultdict
from datetime import datetime, timedelta

from . import alerts, cars, db, sources, telegram
from .config import DASHBOARD_URL, EXPIRE_DAYS
from .fmt import day, label, num

IMAGES_BROKEN_RATIO = 0.2
IMAGES_MIN_CHECKED = 10
DISK_MAX_USED = 0.9
WEEKDAYS = ["lunes", "martes", "miércoles", "jueves", "viernes", "sábado", "domingo"]


def daily():
    conn = db.connect()
    sources.sync(conn)
    now = datetime.now()

    stats = {}
    for source in sources.SOURCES:
        if source.enabled:
            stats[source.id] = _source_day(conn, source, now)

    paused = [source.id for source in sources.SOURCES if not source.enabled]
    if paused:
        # Una fuente en pausa ya no se revisa: sus alertas abiertas no tienen cómo cerrarse solas.
        db.execute(
            conn,
            f"UPDATE nexo.alerts SET resolved_at=NOW() WHERE resolved_at IS NULL "
            f"AND source_id IN ({','.join(['%s'] * len(paused))})",
            tuple(paused),
        )

    system = _system_checks(conn)
    db.execute(conn, "DELETE FROM nexo.image_checks WHERE checked_at < NOW() - INTERVAL 30 DAY")

    text = _summary(conn, now, stats, system)
    telegram.send(text)
    conn.close()
    print(text)


def _source_day(conn, source, now):
    snap = cars.snapshot(conn, source, EXPIRE_DAYS)
    # Al día = vio avisos dentro de su frecuencia. Se mide en los datos y no en nexo.runs para
    # cubrir también lo que se carga fuera del runner.
    up_to_date = bool(snap["last_seen"] and snap["last_seen"] >= now - timedelta(days=source.max_age_days))

    deactivated = 0
    if source.full_crawl and up_to_date and snap["stale"]:
        deactivated = cars.deactivate_stale(conn, source, EXPIRE_DAYS)

    images = db.one(
        conn,
        "SELECT COUNT(*) AS checked, COALESCE(SUM(ok = 0), 0) AS broken FROM nexo.image_checks "
        "WHERE source_id=%s AND checked_at >= NOW() - INTERVAL 1 DAY",
        (source.id,),
    )
    checked, broken = int(images["checked"]), int(images["broken"])

    db.execute(
        conn,
        """
        REPLACE INTO nexo.daily_stats
          (day, source_id, active, seen_24h, new_24h, stale, deactivated, images_checked, images_broken, last_seen)
        VALUES (CURDATE(), %s, %s, %s, %s, %s, %s, %s, %s, %s)
        """,
        (source.id, snap["active"], snap["seen_24h"], snap["new_24h"], snap["stale"], deactivated, checked, broken,
         snap["last_seen"]),
    )

    name = label(source)
    if up_to_date:
        alerts.resolve(conn, "overdue", source.id, f"{name} volvió a traer avisos.")
    else:
        alerts.open_alert(
            conn, "overdue", source.id,
            f"{name}: sin avisos nuevos desde {day(snap['last_seen'])} ({num(snap['active'])} activos).",
            notify=False,
        )

    if checked >= IMAGES_MIN_CHECKED:
        if broken / checked > IMAGES_BROKEN_RATIO:
            alerts.open_alert(
                conn, "images", source.id,
                f"{name}: {broken} de {checked} imágenes revisadas están rotas.", notify=False,
            )
        else:
            alerts.resolve(conn, "images", source.id, f"{name}: las imágenes volvieron a cargar.")

    return {**snap, "up_to_date": up_to_date, "deactivated": deactivated, "checked": checked, "broken": broken}


def _system_checks(conn):
    problems = []

    tunnel = subprocess.run(
        ["systemctl", "is-active", "mysql-reverse-tunnel"], capture_output=True, text=True
    ).stdout.strip()
    if tunnel == "active":
        alerts.resolve(conn, "tunnel", "", "El túnel MySQL a producción volvió.")
    else:
        message = f"El túnel MySQL a producción no está activo ({tunnel}): producción no ve avisos nuevos."
        alerts.open_alert(conn, "tunnel", "", message)
        problems.append(message)

    usage = shutil.disk_usage("/")
    used = usage.used / usage.total
    if used > DISK_MAX_USED:
        message = f"Disco al {used:.0%}."
        alerts.open_alert(conn, "disk", "", message)
        problems.append(message)
    else:
        alerts.resolve(conn, "disk", "", f"Disco de vuelta al {used:.0%}.")

    return problems


def _summary(conn, now, stats, system):
    by_country = defaultdict(int)
    for source in sources.SOURCES:
        if source.id in stats:
            by_country[source.country] += stats[source.id]["active"]

    totals = {key: sum(s[key] for s in stats.values()) for key in ("active", "seen_24h", "new_24h", "stale", "deactivated")}
    late = [s for s in sources.SOURCES if s.id in stats and not stats[s.id]["up_to_date"]]
    late.sort(key=lambda s: -stats[s.id]["active"])
    new_alerts = {
        (a["kind"], a["source_id"]) for a in alerts.open_alerts(conn) if a["opened_at"] >= now - timedelta(hours=1)
    }

    lines = [
        f"📊 Nexo · {WEEKDAYS[now.weekday()]} {now:%d-%m}",
        f"Autos activos: {num(totals['active'])}",
        "  " + " · ".join(f"{c.upper()} {num(n)}" for c, n in sorted(by_country.items(), key=lambda i: -i[1])),
        f"Últimas 24 h: {num(totals['seen_24h'])} vistos · {num(totals['new_24h'])} nuevos"
        f" · {num(totals['deactivated'])} desactivados",
        "",
        f"Fuentes al día: {len(stats) - len(late)} de {len(stats)}",
    ]
    if late:
        lines.append("Atrasadas:")
        for source in late:
            s = stats[source.id]
            new = " 🆕" if ("overdue", source.id) in new_alerts else ""
            lines.append(f"• {label(source)} desde {day(s['last_seen'])} · {num(s['active'])} activos{new}")

    broken = [s for s in sources.SOURCES if s.id in stats and stats[s.id]["checked"] >= IMAGES_MIN_CHECKED
              and stats[s.id]["broken"] / stats[s.id]["checked"] > IMAGES_BROKEN_RATIO]
    if broken:
        lines.append("")
        lines.append("🖼️ Imágenes rotas:")
        for source in broken:
            s = stats[source.id]
            lines.append(f"• {label(source)}: {s['broken']} de {s['checked']}")

    if system:
        lines.append("")
        lines.extend(f"⚠️ {problem}" for problem in system)

    lines.append("")
    lines.append(f"Sin verse en {EXPIRE_DAYS} días: {num(totals['stale'])} avisos activos"
                 " (solo se desactivan en fuentes con recorrido completo).")
    lines.append(DASHBOARD_URL)
    return "\n".join(lines)
