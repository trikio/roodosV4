#!/usr/bin/env python3
import argparse
import json
import os
import subprocess
import mysql.connector
from dotenv import load_dotenv


def get_embeddings_batch_with_curl(titles, model: str = "qwen3-embedding"):
    payload = json.dumps({"model": model, "input": titles}, ensure_ascii=False)
    cmd = [
        "curl", "-sS", "http://localhost:11434/api/embed",
        "-d", payload,
    ]
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError(p.stderr.strip() or "curl failed")
    data = json.loads(p.stdout)
    embs = data.get("embeddings")
    if not isinstance(embs, list):
        raise RuntimeError("invalid embeddings response")
    if len(embs) != len(titles):
        raise RuntimeError("embeddings size mismatch")
    return embs


def run_for_table(cur, table: str, limit: int, model: str, embed_batch_size: int = 64):
    cur.execute(
        f"SELECT id, title FROM {table} WHERE embedding_json IS NULL AND title IS NOT NULL AND title<>'' ORDER BY id LIMIT %s",
        (limit,),
    )
    rows = cur.fetchall()
    ok = 0
    fail = 0

    for i in range(0, len(rows), embed_batch_size):
        chunk = rows[i:i + embed_batch_size]
        titles = [r["title"] for r in chunk]
        try:
            vectors = get_embeddings_batch_with_curl(titles, model=model)
            for r, vec in zip(chunk, vectors):
                emb_json = {"model": model, "vector": vec}
                cur.execute(f"UPDATE {table} SET embedding_json=%s WHERE id=%s", (json.dumps(emb_json), r["id"]))
                ok += 1
        except Exception:
            # fallback one-by-one for the failed chunk
            for r in chunk:
                try:
                    vec = get_embeddings_batch_with_curl([r["title"]], model=model)[0]
                    emb_json = {"model": model, "vector": vec}
                    cur.execute(f"UPDATE {table} SET embedding_json=%s WHERE id=%s", (json.dumps(emb_json), r["id"]))
                    ok += 1
                except Exception:
                    fail += 1
    return len(rows), ok, fail


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--table", choices=["car_landings", "house_landings", "both"], default="both")
    ap.add_argument("--limit", type=int, default=100)
    ap.add_argument("--model", default="qwen3-embedding")
    ap.add_argument("--embed-batch-size", type=int, default=64)
    args = ap.parse_args()

    load_dotenv('/home/trikio/roodos_v4/nexo/.env')
    conn = mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "localhost"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        user=os.getenv("MYSQL_USER", "trikio"),
        password=os.getenv("MYSQL_PASSWORD", ""),
        database=os.getenv("MYSQL_DATABASE", "roodos"),
        autocommit=False,
    )
    cur = conn.cursor(dictionary=True)

    tables = [args.table] if args.table != "both" else ["car_landings", "house_landings"]
    report = {}
    for t in tables:
        total, ok, fail = run_for_table(cur, t, args.limit, args.model, embed_batch_size=args.embed_batch_size)
        conn.commit()
        report[t] = {"selected": total, "updated": ok, "failed": fail}

    print(report)
    cur.close()
    conn.close()


if __name__ == "__main__":
    main()
