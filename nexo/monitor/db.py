import os

import mysql.connector


def connect():
    """Conexión a la base `roodos`; las tablas de monitoreo se nombran como `nexo.<tabla>`."""
    return mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "localhost"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        user=os.getenv("MYSQL_USER", "trikio"),
        password=os.getenv("MYSQL_PASSWORD", ""),
        database=os.getenv("MYSQL_DATABASE", "roodos"),
        autocommit=True,
    )


def all(conn, sql, params=()):
    cur = conn.cursor(dictionary=True)
    cur.execute(sql, params)
    rows = cur.fetchall()
    cur.close()
    return rows


def one(conn, sql, params=()):
    rows = all(conn, sql, params)
    return rows[0] if rows else None


def execute(conn, sql, params=()):
    """Ejecuta una escritura y devuelve (filas afectadas, último id insertado)."""
    cur = conn.cursor()
    cur.execute(sql, params)
    result = (cur.rowcount, cur.lastrowid)
    cur.close()
    return result
