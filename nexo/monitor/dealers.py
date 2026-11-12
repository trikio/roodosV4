"""Automotoras chicas: prueba de candidatas con el spider genérico y aprobación en lote.

Cada día se prueban las candidatas nuevas de nexo.candidates con `dealers` sin escribir en la base.
Las que entregan al menos MIN_TEST_ITEMS avisos válidos quedan en nexo.dealer_sites como `review`;
las demás pasan a `manual` (se adaptan a pedido, con un spider propio). Los lunes llega por Telegram
la lista para aprobar, que se aprueba desde el dashboard o con `bin/nexo dealers approve`.
"""

import json
import subprocess
import tempfile
from pathlib import Path

from . import db, telegram
from .config import DASHBOARD_URL, ROOT, SCRAPY

MIN_TEST_ITEMS = 5
PROBE_PER_DAY = 25
PROBE_SECONDS = 180
PROBE_ITEMS = 30


def probe(limit=PROBE_PER_DAY):
    conn = db.connect()
    rows = db.all(
        conn,
        "SELECT host, country FROM nexo.candidates WHERE status='new' ORDER BY score DESC, first_seen LIMIT %s",
        (limit,),
    )
    # De a un sitio: en una pasada conjunta un sitio grande se lleva todo el tiempo de la prueba.
    for row in rows:
        host, country = row["host"], row["country"]
        found = _test_crawl(country, host)
        if len(found) >= MIN_TEST_ITEMS:
            sample = [{k: item.get(k) for k in ("title", "price", "year", "km", "url")} for item in found[:3]]
            db.execute(
                conn,
                """
                INSERT INTO nexo.dealer_sites (host, country, name, status, test_items, sample, tested_at)
                VALUES (%s, %s, %s, 'review', %s, %s, NOW())
                ON DUPLICATE KEY UPDATE test_items=VALUES(test_items), sample=VALUES(sample), tested_at=NOW()
                """,
                (host, country, (found[0].get("source_label") or host)[:100], len(found),
                 json.dumps(sample, ensure_ascii=False)),
            )
            status = "dealer"
        else:
            status = "manual"
        db.execute(conn, "UPDATE nexo.candidates SET status=%s WHERE host=%s", (status, host))
        print(f"{host} ({country}): {len(found)} avisos -> {status}")
    conn.close()


def _test_crawl(country, host):
    """Avisos válidos (sin repetir URL) de una pasada de prueba, sin pipeline (no escribe en la base)."""
    with tempfile.TemporaryDirectory() as tmp:
        output = Path(tmp) / "items.json"
        try:
            subprocess.run(
                [SCRAPY, "crawl", "dealers", "-a", f"country={country}", "-a", f"hosts={host}",
                 "-s", "ITEM_PIPELINES={}", "-s", f"CLOSESPIDER_TIMEOUT={PROBE_SECONDS}",
                 "-s", f"CLOSESPIDER_ITEMCOUNT={PROBE_ITEMS}", "-O", str(output), "-L", "WARNING"],
                cwd=ROOT, capture_output=True, timeout=PROBE_SECONDS + 120,
            )
            items = json.loads(output.read_text())
        except (subprocess.TimeoutExpired, OSError, ValueError):
            items = []
    return list({item["url"]: item for item in items}.values())


def review():
    """Lista de automotoras por aprobar, por Telegram."""
    conn = db.connect()
    rows = db.all(conn, "SELECT * FROM nexo.dealer_sites WHERE status='review' ORDER BY country, test_items DESC")
    manual = db.all(
        conn,
        "SELECT host, country FROM nexo.candidates WHERE status='manual' AND updated_at >= NOW() - INTERVAL 7 DAY "
        "ORDER BY score DESC LIMIT 15",
    )
    conn.close()
    if not rows and not manual:
        return
    lines = []
    if rows:
        lines.append(f"🏪 {len(rows)} automotoras listas para aprobar:")
        lines += [f"• [{r['country'].upper()}] {r['name']} ({r['host']}) · {r['test_items']} autos en la prueba"
                  for r in rows]
        lines += ["", f"Se aprueban en el dashboard: {DASHBOARD_URL}"]
    if manual:
        lines += ["", "🔧 Sitios de la semana que necesitan un spider propio (se adaptan a pedido):"]
        lines += [f"• [{r['country'].upper()}] {r['host']}" for r in manual]
    telegram.send("\n".join(lines))


def set_status(hosts, status):
    conn = db.connect()
    for host in hosts:
        affected, _ = db.execute(
            conn,
            "UPDATE nexo.dealer_sites SET status=%s, approved_at=IF(%s='active', NOW(), approved_at) WHERE host=%s",
            (status, status, host),
        )
        print(f"{host}: {status}" if affected else f"{host} no está en nexo.dealer_sites")
    conn.close()


def approve_all_review():
    conn = db.connect()
    hosts = [row["host"] for row in db.all(conn, "SELECT host FROM nexo.dealer_sites WHERE status='review'")]
    conn.close()
    set_status(hosts, "active")


def list_sites():
    conn = db.connect()
    for row in db.all(conn, "SELECT * FROM nexo.dealer_sites ORDER BY country, status, host"):
        print(f"{row['country']} {row['status']:<9} {row['test_items']:>4} {row['host']:<32} {row['name'] or ''}")
    conn.close()
