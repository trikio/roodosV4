#!/usr/bin/env python3
import argparse
import os
import re
import unicodedata

import mysql.connector


def norm(s):
    if not s:
        return ""
    s = str(s).strip().lower()
    s = unicodedata.normalize("NFKD", s)
    s = "".join(ch for ch in s if not unicodedata.combining(ch))
    s = re.sub(r"[^a-z0-9]+", " ", s)
    return " ".join(s.split())


def build_min1_match_query(text):
    tokens = [t for t in norm(text).split() if len(t) >= 2]
    if not tokens:
        return None
    # quorum syntax: at least 1 word must match
    # example: "toyota corolla 2018"/1
    phrase = " ".join(tokens[:12])
    return f'"{phrase}"/1'


def manticore_make_lookup(mcur, text):
    q = build_min1_match_query(text)
    if not q:
        return None
    try:
        mcur.execute(
            """
            SELECT id, WEIGHT() w
            FROM car_make
            WHERE MATCH(%s)
            ORDER BY w DESC
            LIMIT 1 OPTION ranker=bm25
            """,
            (q,),
        )
        row = mcur.fetchone()
        return int(row["id"]) if row else None
    except Exception:
        return None


def manticore_model_lookup(mcur, make_id, text):
    if not make_id:
        return None
    q = build_min1_match_query(text)
    if not q:
        return None
    try:
        mcur.execute(
            """
            SELECT id, WEIGHT() w
            FROM car_model
            WHERE id_car_make=%s AND MATCH(%s)
            ORDER BY w DESC
            LIMIT 1 OPTION ranker=bm25
            """,
            (int(make_id), q),
        )
        row = mcur.fetchone()
        return int(row["id"]) if row else None
    except Exception:
        return None


def manticore_location_lookup(mcur, text, country_code):
    q = build_min1_match_query(text)
    if not q:
        return None, None
    try:
        mcur.execute(
            """
            SELECT id AS id_city, id_state, WEIGHT() w
            FROM location
            WHERE country=%s AND MATCH(%s)
            ORDER BY w DESC
            LIMIT 1 OPTION ranker=bm25
            """,
            (country_code.lower(), q),
        )
        row = mcur.fetchone()
        if not row:
            return None, None
        return int(row["id_city"]), int(row["id_state"])
    except Exception:
        return None, None


def main(table_name="seo_keys_EC", country_code="ec", max_batches=1, batch_size=500, shards=1, shard_id=0):
    db = mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "localhost"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        user=os.getenv("MYSQL_USER", "trikio"),
        password=os.getenv("MYSQL_PASSWORD", ""),
        database=os.getenv("MYSQL_DATABASE", "roodos"),
    )
    cur = db.cursor(dictionary=True)

    # Manticore SQL interface
    mc = mysql.connector.connect(host="127.0.0.1", port=9306)
    mcur = mc.cursor(dictionary=True)

    updated = 0
    upd_make = 0
    upd_model = 0
    upd_state = 0
    upd_city = 0
    batches = 0

    while True:
        cur.execute(
            f"""
            SELECT id, nombre, id_car_make, id_car_model, id_state, id_city
            FROM {table_name}
            WHERE picked=0
              AND MOD(id, %s) = %s
            ORDER BY id
            LIMIT %s
            """,
            (shards, shard_id, batch_size),
        )
        rows = cur.fetchall()
        if not rows:
            break

        for r in rows:
            nombre = r.get("nombre") or ""
            id_make = r.get("id_car_make")
            id_model = r.get("id_car_model")
            id_state = r.get("id_state")
            id_city = r.get("id_city")

            if not id_make:
                id_make = manticore_make_lookup(mcur, nombre)
            if id_make and not id_model:
                id_model = manticore_model_lookup(mcur, id_make, nombre)
            if not id_city or not id_state:
                lc, ls = manticore_location_lookup(mcur, nombre, country_code)
                if lc and not id_city:
                    id_city = lc
                if ls and not id_state:
                    id_state = ls

            changed = False
            if id_make and not r.get("id_car_make"):
                upd_make += 1
                changed = True
            if id_model and not r.get("id_car_model"):
                upd_model += 1
                changed = True
            if id_state and not r.get("id_state"):
                upd_state += 1
                changed = True
            if id_city and not r.get("id_city"):
                upd_city += 1
                changed = True

            cur.execute(
                f"""
                UPDATE {table_name}
                SET id_car_make = COALESCE(id_car_make, %s),
                    id_car_model = COALESCE(id_car_model, %s),
                    id_state = COALESCE(id_state, %s),
                    id_city = COALESCE(id_city, %s),
                    picked = 1
                WHERE id = %s
                """,
                (id_make, id_model, id_state, id_city, r["id"]),
            )

            if changed:
                updated += 1

        db.commit()
        batches += 1
        cur.execute(f"SELECT COUNT(*) c FROM {table_name} WHERE picked=0")
        pending = cur.fetchone()["c"]
        print(f"progress batches={batches} updated_rows={updated} pending={pending}")

        if max_batches is not None and batches >= max_batches:
            print("stopping due to max_batches")
            break

    print(f"done updated={updated} make={upd_make} model={upd_model} state={upd_state} city={upd_city}")
    cur.execute(
        f"""
        SELECT SUM(picked=1) picked_done, SUM(picked=0) picked_pending,
               SUM(id_car_make IS NULL) null_make, SUM(id_car_model IS NULL) null_model,
               SUM(id_state IS NULL) null_state, SUM(id_city IS NULL) null_city
        FROM {table_name}
        """
    )
    print(cur.fetchone())

    mcur.close()
    mc.close()
    cur.close()
    db.close()


if __name__ == "__main__":
    p = argparse.ArgumentParser()
    p.add_argument("--table", default="seo_keys_EC")
    p.add_argument("--country", default="ec")
    p.add_argument("--max-batches", type=int, default=1)
    p.add_argument("--batch-size", type=int, default=500)
    p.add_argument("--shards", type=int, default=1)
    p.add_argument("--shard-id", type=int, default=0)
    args = p.parse_args()

    if not re.match(r"^seo_keys_[A-Za-z]{2}$", args.table):
        raise SystemExit("Invalid --table format. Use like seo_keys_EC")
    if not re.match(r"^[A-Za-z]{2}$", args.country):
        raise SystemExit("Invalid --country format. Use like ec")
    if args.shards < 1:
        raise SystemExit("--shards must be >= 1")
    if args.shard_id < 0 or args.shard_id >= args.shards:
        raise SystemExit("--shard-id must be in [0, shards-1]")

    main(
        table_name=args.table,
        country_code=args.country.lower(),
        max_batches=args.max_batches,
        batch_size=args.batch_size,
        shards=args.shards,
        shard_id=args.shard_id,
    )
