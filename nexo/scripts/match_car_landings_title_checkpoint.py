#!/usr/bin/env python3
import argparse
import os
import re
import unicodedata
from collections import defaultdict

import mysql.connector
from dotenv import load_dotenv

STOPWORDS = {
    "auto", "autos", "carro", "carros", "vehiculo", "vehiculos", "usado", "usados", "usada", "usadas",
    "segunda", "mano", "seminuevo", "seminuevos", "nuevo", "nuevos", "nueva", "nuevas", "de", "del", "la",
    "el", "los", "las", "y", "en", "por", "para", "a", "al", "con", "sin", "precio", "precios",
}
COUNTRY_MAP = {"cl": "CL", "mx": "MX", "pe": "PE", "ec": "EC"}


def norm(text: str) -> str:
    text = (text or "").strip().lower()
    text = unicodedata.normalize("NFKD", text)
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return " ".join(text.split())


def tokens_from_title(title: str):
    return [t for t in norm(title).split() if t and t not in STOPWORDS and len(t) > 1]


def ngrams(tokens, max_n=4):
    out = []
    n = len(tokens)
    for k in range(max_n, 0, -1):
        for i in range(0, n - k + 1):
            out.append(" ".join(tokens[i:i + k]))
    return out


def main(batch=5000, dry_run=False):
    load_dotenv('/home/trikio/roodos_v4/nexo/.env')
    db = mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "localhost"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        user=os.getenv("MYSQL_USER", "trikio"),
        password=os.getenv("MYSQL_PASSWORD", ""),
        database=os.getenv("MYSQL_DATABASE", "roodos"),
        autocommit=False,
    )
    cur = db.cursor(dictionary=True)

    # dictionaries
    cur.execute("SELECT id_car_make, name, slug, ranking FROM car_make")
    make_rows = cur.fetchall()
    make_by_phrase = defaultdict(set)
    make_rank = {}
    for r in make_rows:
        mk = int(r["id_car_make"])
        make_rank[mk] = int(r.get("ranking") or 0)
        for p in {norm(r.get("name") or ""), norm((r.get("slug") or "").replace('-', ' '))}:
            if p:
                make_by_phrase[p].add(mk)

    cur.execute("SELECT id_car_model, id_car_make, name, slug, ranking FROM car_model")
    model_rows = cur.fetchall()
    model_by_make_phrase = defaultdict(set)
    model_by_phrase = defaultdict(set)
    model_rank = {}
    model_make = {}
    for r in model_rows:
        md = int(r["id_car_model"])
        mk = int(r["id_car_make"])
        model_rank[md] = int(r.get("ranking") or 0)
        model_make[md] = mk
        phrases = {norm(r.get("name") or "")}
        s = (r.get("slug") or "")
        if s:
            parts = s.split("-", 1)
            if len(parts) == 2:
                phrases.add(norm(parts[1].replace('-', ' ')))
        for p in phrases:
            if p and p not in STOPWORDS:
                model_by_make_phrase[(mk, p)].add(md)
                model_by_phrase[p].add(md)

    cur.execute(
        """
        SELECT id, country_id, title
        FROM car_landings
        WHERE activo=1 AND picked=0
        ORDER BY id
        LIMIT %s
        """,
        (batch,),
    )
    rows = cur.fetchall()

    upd = []
    stats = {"processed": len(rows), "make_model": 0, "make_only": 0, "none": 0}

    for r in rows:
        rid = int(r["id"])
        title = r.get("title") or ""
        cc = COUNTRY_MAP.get((r.get("country_id") or "").lower(), "")

        toks = tokens_from_title(title)
        gs = ngrams(toks, 4)

        # explicit make
        make_score = defaultdict(int)
        for g in gs:
            for mk in make_by_phrase.get(g, set()):
                make_score[mk] += len(g.split()) * 10

        best_make = None
        if make_score:
            best_make = max(make_score.keys(), key=lambda mk: (make_score[mk], make_rank.get(mk, 0)))

        best_model = None

        if best_make:
            model_score = defaultdict(int)
            for g in gs:
                for md in model_by_make_phrase.get((best_make, g), set()):
                    model_score[md] += len(g.split()) * 12
            if model_score:
                best_model = max(model_score.keys(), key=lambda md: (model_score[md], model_rank.get(md, 0)))
        else:
            # fallback: model-only, then infer make from model
            model_score = defaultdict(int)
            for g in gs:
                for md in model_by_phrase.get(g, set()):
                    model_score[md] += len(g.split()) * 12
            if model_score:
                best_model = max(model_score.keys(), key=lambda md: (model_score[md], model_rank.get(md, 0), make_rank.get(model_make.get(md), 0)))
                best_make = model_make.get(best_model)

        if best_make and best_model:
            stats["make_model"] += 1
        elif best_make:
            stats["make_only"] += 1
        else:
            stats["none"] += 1

        upd.append((best_make, best_model, rid))

    if not dry_run and upd:
        cur.executemany("UPDATE car_landings SET id_car_make=%s, id_car_model=%s, picked=1 WHERE id=%s", upd)
        db.commit()
    else:
        db.rollback()

    print(stats)
    if not dry_run:
        print({"updated_rows": len(upd)})

    cur.close()
    db.close()


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--batch", type=int, default=5000)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()
    main(batch=args.batch, dry_run=args.dry_run)
