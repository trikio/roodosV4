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


def build_match_q3(text: str):
    tokens = [t for t in norm(text).split() if len(t) >= 2]
    if len(tokens) < 3:
        return None
    phrase = " ".join(tokens[:16])
    return f'"{phrase}"/3'


def count_matches(mcur, query_text: str) -> int:
    mq = build_match_q3(query_text)
    if not mq:
        return 0
    try:
        mcur.execute("SELECT COUNT(*) AS c FROM car_ec WHERE MATCH(%s)", (mq,))
        row = mcur.fetchone()
        return int(row["c"] if row and row.get("c") is not None else 0)
    except Exception:
        return 0


def main(batch_size=2000, max_batches=None):
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

    processed = 0
    batches = 0
    last_id = 0

    while True:
        cur.execute(
            """
            SELECT id, nombre
            FROM seo_keys_EC
            WHERE id > %s
            ORDER BY id
            LIMIT %s
            """,
            (last_id, batch_size),
        )
        rows = cur.fetchall()
        if not rows:
            break

        for r in rows:
            rid = r["id"]
            nombre = r.get("nombre") or ""
            c = count_matches(mcur, nombre)
            cur.execute("UPDATE seo_keys_EC SET results_3=%s WHERE id=%s", (c, rid))
            processed += 1
            last_id = rid

        db.commit()
        batches += 1
        print(f"progress batches={batches} processed={processed} last_id={last_id}")

        if max_batches is not None and batches >= max_batches:
            print("stopping due to max_batches")
            break

    print(f"done processed={processed}")

    mcur.close(); mc.close(); cur.close(); db.close()


if __name__ == "__main__":
    p = argparse.ArgumentParser()
    p.add_argument("--batch-size", type=int, default=2000)
    p.add_argument("--max-batches", type=int, default=None)
    args = p.parse_args()
    main(batch_size=args.batch_size, max_batches=args.max_batches)
