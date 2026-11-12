"""Alertas con estado: se avisa por Telegram al abrirse y al resolverse, no cada vez que se revisan."""

from . import db, telegram


def open_alert(conn, kind, source_id, message, notify=True):
    """Abre la alerta si no había una abierta del mismo tipo y fuente. Devuelve True si es nueva.

    Las que detecta la revisión diaria no se mandan solas (notify=False): van en el resumen.
    """
    existing = db.one(
        conn,
        "SELECT id FROM nexo.alerts WHERE kind=%s AND source_id=%s AND resolved_at IS NULL",
        (kind, source_id),
    )
    if existing:
        db.execute(conn, "UPDATE nexo.alerts SET message=%s WHERE id=%s", (message, existing["id"]))
        return False
    db.execute(
        conn,
        "INSERT INTO nexo.alerts (kind, source_id, message) VALUES (%s, %s, %s)",
        (kind, source_id, message),
    )
    if notify:
        telegram.send(f"⚠️ {message}")
    return True


def resolve(conn, kind, source_id, message, notify=True):
    """Cierra la alerta abierta, si la hay, y avisa que se recuperó. Devuelve True si había una."""
    affected, _ = db.execute(
        conn,
        "UPDATE nexo.alerts SET resolved_at=NOW() WHERE kind=%s AND source_id=%s AND resolved_at IS NULL",
        (kind, source_id),
    )
    if affected and notify:
        telegram.send(f"✅ {message}")
    return bool(affected)


def open_alerts(conn):
    return db.all(conn, "SELECT * FROM nexo.alerts WHERE resolved_at IS NULL ORDER BY opened_at")
