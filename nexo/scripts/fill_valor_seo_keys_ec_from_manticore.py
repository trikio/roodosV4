#!/usr/bin/env python3
import argparse
import os
import re
import unicodedata

import mysql.connector


def norm(s: str) -> str:
    if not s:
        return ""
    s = str(s).strip().lower()
    s = unicodedata.normalize("NFKD", s)
    s = "".join(ch for ch in s if not unicodedata.combining(ch))
    s = re.sub(r"[^a-z0-9]+", " ", s)
    return " ".join(s.split())


def build_match_q2(text: str):
    tokens = [t for t in norm(text).split() if len(t) >= 2]
    if len(tokens) < 2:
        return None
    phrase = " ".join(tokens[:16])
    return f'"{phrase}"/2'


def count_matches(mcur, query_text: str) -> int:
    mq = build_match_q2(query_text)
    if not mq:
        return 0
    try:
        mcur.execute("SELECT COUNT(*) AS c FROM car_ec WHERE MATCH(%s)", (mq,))
        row = mcur.fetchone()
        return int(row["c"] if row and row.get("c") is not None else 0)
    except Exception:
        return 0


def main(batch_size=1000, max_batches=1, reset_picked=False):
    db = mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "localhost"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        user=os.getenv("MYSQL_USER", "trikio"),
        password=os.getenv("MYSQL_PASSWORD", ""),
        database=os.getenv("MYSQL_DATABASE", "roodos"),
    )
    cur = db.cursor(dictionary=True)

    mc = mysql.connector.connect(host="127.0.0.1", port=9306)
    mcur = mc.cursor(dictionary=True)

    if reset_picked:
        cur.execute("UPDATE seo_keys_EC SET picked=0")
        db.commit()

    total_rows = 0
    batches = 0

    while True:
        cur.execute(
            """
            SELECT id, nombre
            FROM seo_keys_EC
            WHERE picked=0
            ORDER BY id
            LIMIT %s
            """,
            (batch_size,),
        )
        rows = cur.fetchall()
        if not rows:
            break

        for r in rows:
            rid = r["id"]
            nombre = r.get("nombre") or ""
            c = count_matches(mcur, nombre)
            cur.execute("UPDATE seo_keys_EC SET valor=%s, picked=1 WHERE id=%s", (c, rid))
            total_rows += 1

        db.commit()
        batches += 1

        cur.execute("SELECT COUNT(*) AS c FROM seo_keys_EC WHERE picked=0")
        pending = cur.fetchone()["c"]
        print(f"progress batches={batches} processed={total_rows} pending={pending}")

        if max_batches is not None and batches >= max_batches:
            print("stopping due to max_batches")
            break

    print(f"done processed={total_rows}")

    mcur.close()
    mc.close()
    cur.close()
    db.close()


if __name__ == "__main__":
    p = argparse.ArgumentParser()
    p.add_argument("--batch-size", type=int, default=1000)
    p.add_argument("--max-batches", type=int, default=1)
    p.add_argument("--reset-picked", action="store_true")
    args = p.parse_args()
    main(batch_size=args.batch_size, max_batches=args.max_batches, reset_picked=args.reset_picked)
