#!/usr/bin/env python3
"""Rellena en roodos.cars la ubicación, el combustible, la transmisión, la carrocería y la versión de los
avisos que ya están, con el mismo normalizador que usa el pipeline (roodos_scraper/normalize.py).

Solo completa campos vacíos: nunca pisa un dato que ya venía del spider.

    scripts/normalize_cars.py                  # mide: cobertura antes/después por fuente y ejemplos
    scripts/normalize_cars.py --apply          # escribe
    scripts/normalize_cars.py --country PE --samples 20
"""
import argparse
import collections
import os
import random
import sys
from pathlib import Path

import mysql.connector

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from roodos_scraper.normalize import Normalizer  # noqa: E402

FIELDS = ("id_state", "id_city", "id_fuel", "id_transmission", "id_body", "version")


def connect():
    return mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "localhost"), port=int(os.getenv("MYSQL_PORT", "3306")),
        user=os.getenv("MYSQL_USER", "trikio"), password=os.getenv("MYSQL_PASSWORD", ""),
        database=os.getenv("MYSQL_DATABASE", "roodos"),
    )


def ensure_version_column(cur):
    cur.execute("SELECT COUNT(*) n FROM information_schema.columns WHERE table_schema=DATABASE() "
                "AND table_name='cars' AND column_name='version'")
    if not cur.fetchone()["n"]:
        print("agregando cars.version")
        cur.execute("ALTER TABLE cars ADD COLUMN version VARCHAR(100) NULL AFTER id_car_model")


def main(args):
    conn = connect()
    cur = conn.cursor(dictionary=True)
    if args.apply:
        ensure_version_column(cur)
    cur.execute("SELECT COUNT(*) n FROM information_schema.columns WHERE table_schema=DATABASE() "
                "AND table_name='cars' AND column_name='version'")
    has_version = bool(cur.fetchone()["n"])

    normalizer = Normalizer(cur)
    countries = [args.country.upper()] if args.country else ["CL", "MX", "PE", "EC"]
    version_col = "version" if has_version else "NULL AS version"
    cur.execute(
        f"""SELECT id, id_country, url, title, details, id_car_make, id_car_model, id_state, id_city, id_fuel,
                   id_transmission, id_body, {version_col}
            FROM cars WHERE status=1 AND id_country IN ({",".join(["%s"] * len(countries))})""",
        countries,
    )
    rows = cur.fetchall()
    print(f"avisos activos: {len(rows)}")

    before = collections.defaultdict(collections.Counter)
    after = collections.defaultdict(collections.Counter)
    totals = collections.Counter()
    samples = collections.defaultdict(list)
    updates = []
    for row in rows:
        host = row["url"].split("/")[2] if row["url"].count("/") >= 2 else "?"
        key = (row["id_country"], host)
        totals[key] += 1
        found = normalizer.extract(row["id_country"], row["title"], row["details"],
                                   make_id=row["id_car_make"], model_id=row["id_car_model"])
        change = {}
        for f in FIELDS:
            if row[f]:
                before[key][f] += 1
                after[key][f] += 1
            elif found[f]:
                after[key][f] += 1
                change[f] = found[f]
        if change:
            updates.append((row["id"], change))
            if len(samples[key]) < 400:
                samples[key].append((row["title"], (row["details"] or "")[:90], change))

    names = {"id_state": "región", "id_city": "ciudad", "id_fuel": "combust", "id_transmission": "transm",
             "id_body": "carroc", "version": "versión"}
    print(f"\n{'fuente':42} {'avisos':>7} " + " ".join(f"{names[f]:>13}" for f in FIELDS))
    for key in sorted(totals, key=lambda k: (k[0], -totals[k])):
        if totals[key] < 30:
            continue
        cells = " ".join(f"{before[key][f] * 100 // totals[key]:>5}%→{after[key][f] * 100 // totals[key]:>4}%" for f in FIELDS)
        print(f"{key[0]} {key[1][:39]:39} {totals[key]:>7} {cells}")

    random.seed(1)
    for key in sorted(samples, key=lambda k: -totals[k])[: args.sources]:
        print(f"\n== {key[0]} {key[1]}")
        for title, details, change in random.sample(samples[key], min(args.samples, len(samples[key]))):
            print(f"  {title[:60]!r} | {details!r}\n     -> {change}")

    if args.apply:
        print(f"\nescribiendo {len(updates)} avisos")
        for i, (car_id, change) in enumerate(updates, 1):
            sets = ", ".join(f"{f}=COALESCE({f}, %s)" for f in change)
            cur.execute(f"UPDATE cars SET {sets} WHERE id=%s", (*change.values(), car_id))
            if i % 2000 == 0:
                conn.commit()
        conn.commit()
        print("listo")
    conn.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--apply", action="store_true", help="escribir en la base (sin esto solo mide)")
    parser.add_argument("--country", help="CL, MX, PE o EC (por defecto los cuatro)")
    parser.add_argument("--samples", type=int, default=4, help="ejemplos por fuente")
    parser.add_argument("--sources", type=int, default=40, help="fuentes con ejemplos")
    main(parser.parse_args())
