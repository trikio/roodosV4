"""Consultas sobre roodos.cars acotadas a una fuente (su país y los hosts de sus URLs)."""

from . import db

HOST = "SUBSTRING_INDEX(SUBSTRING_INDEX(url, '/', 3), '/', -1)"


def hosts(conn, source):
    """Los hosts de la fuente; para las automotoras, los sitios activos del país (con y sin www.)."""
    if not source.dealers:
        return source.hosts
    rows = db.all(conn, "SELECT host FROM nexo.dealer_sites WHERE country=%s AND status='active'", (source.country,))
    return tuple(h for row in rows for h in (row["host"], f"www.{row['host']}")) or ("-",)


def where(conn, source):
    source_hosts = hosts(conn, source)
    placeholders = ",".join(["%s"] * len(source_hosts))
    return f"id_country = %s AND {HOST} IN ({placeholders})", (source.country, *source_hosts)


def touched_since(conn, source, since):
    """(vistos, nuevos) desde `since`: todo scraper pone updated=NOW() en cada aviso que guarda."""
    condition, params = where(conn, source)
    row = db.one(
        conn,
        f"SELECT COALESCE(SUM(updated >= %s), 0) AS seen, COALESCE(SUM(created >= %s), 0) AS inserted "
        f"FROM cars WHERE {condition}",
        (since, since, *params),
    )
    return int(row["seen"]), int(row["inserted"])


def snapshot(conn, source, expire_days):
    """Activos, vistos y nuevos en 24 h, activos sin verse en `expire_days` y la última vez que se vio uno."""
    condition, params = where(conn, source)
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
    """Desactiva los activos sin verse en `expire_days`, pero solo en los hosts que la fuente sí está
    viendo: si un sitio de automotora falla, sus avisos no se dan por vencidos."""
    condition, params = where(conn, source)
    affected, _ = db.execute(
        conn,
        f"""
        UPDATE cars c
        JOIN (
          SELECT {HOST} AS host FROM cars WHERE {condition}
          GROUP BY host HAVING MAX(updated) >= NOW() - INTERVAL %s DAY
        ) fresh ON {HOST.replace('url', 'c.url')} = fresh.host
        SET c.status = 0
        WHERE c.id_country = %s AND c.status = 1 AND COALESCE(c.updated, c.created) < NOW() - INTERVAL %s DAY
        """,
        (*params, source.max_age_days, source.country, expire_days),
    )
    return affected


def sample_active(conn, source, limit):
    condition, params = where(conn, source)
    return db.all(
        conn,
        f"SELECT id, image, image_big FROM cars WHERE {condition} AND status = 1 ORDER BY RAND() LIMIT %s",
        (*params, limit),
    )


def by_host(conn, source):
    """Activos y última vez visto por host (para el detalle de las automotoras)."""
    condition, params = where(conn, source)
    return db.all(
        conn,
        f"SELECT {HOST} AS host, SUM(status = 1) AS active, MAX(updated) AS last_seen "
        f"FROM cars WHERE {condition} GROUP BY host",
        params,
    )
