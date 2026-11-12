"""Consultas sobre roodos.cars acotadas a una fuente (su país y los hosts de sus URLs)."""

from . import db

HOST = "SUBSTRING_INDEX(SUBSTRING_INDEX(url, '/', 3), '/', -1)"


def where(source):
    hosts = ",".join(["%s"] * len(source.hosts))
    return f"id_country = %s AND {HOST} IN ({hosts})", (source.country, *source.hosts)


def touched_since(conn, source, since):
    """(vistos, nuevos) desde `since`: todo scraper pone updated=NOW() en cada aviso que guarda."""
    condition, params = where(source)
    row = db.one(
        conn,
        f"SELECT COALESCE(SUM(updated >= %s), 0) AS seen, COALESCE(SUM(created >= %s), 0) AS inserted "
        f"FROM cars WHERE {condition}",
        (since, since, *params),
    )
    return int(row["seen"]), int(row["inserted"])


def snapshot(conn, source, expire_days):
    """Activos, vistos y nuevos en 24 h, activos sin verse en `expire_days` y la última vez que se vio uno."""
    condition, params = where(source)
    row = db.one(
        conn,
        f"""
        SELECT COALESCE(SUM(status = 1), 0) AS active,
               COALESCE(SUM(updated >= NOW() - INTERVAL 1 DAY), 0) AS seen_24h,
               COALESCE(SUM(created >= NOW() - INTERVAL 1 DAY), 0) AS new_24h,
               COALESCE(SUM(status = 1 AND COALESCE(updated, created) < NOW() - INTERVAL %s DAY), 0) AS stale,
               MAX(updated) AS last_seen
        FROM cars WHERE {condition}
        """,
        (expire_days, *params),
    )
    return {key: value if key == "last_seen" else int(value) for key, value in row.items()}


def deactivate_stale(conn, source, expire_days):
    condition, params = where(source)
    affected, _ = db.execute(
        conn,
        f"UPDATE cars SET status = 0 WHERE {condition} AND status = 1 "
        f"AND COALESCE(updated, created) < NOW() - INTERVAL %s DAY",
        (*params, expire_days),
    )
    return affected


def sample_active(conn, source, limit):
    condition, params = where(source)
    return db.all(
        conn,
        f"SELECT id, image, image_big FROM cars WHERE {condition} AND status = 1 ORDER BY RAND() LIMIT %s",
        (*params, limit),
    )
