#!/usr/bin/env python3
import argparse
import json
import re
import unicodedata
from collections import defaultdict

import mysql.connector
from dotenv import load_dotenv


STOPWORDS = {
    "auto", "autos", "carro", "carros", "vehiculo", "vehiculos", "usado", "usados", "usada", "usadas",
    "segunda", "mano", "seminuevo", "seminuevos", "nuevo", "nuevos", "nueva", "nuevas", "barato", "baratos",
    "precio", "precios", "compra", "venta", "vender", "comprar", "online", "con", "sin", "de", "del", "la", "el",
    "los", "las", "y", "en", "por", "para", "a", "al", "tu", "mi", "su", "un", "una", "que", "como", "mejor",
}

COUNTRY_MAP = {"cl": "CL", "mx": "MX", "pe": "PE", "ec": "EC"}


def norm(txt: str) -> str:
    txt = (txt or "").strip().lower()
    txt = unicodedata.normalize("NFKD", txt)
    txt = "".join(ch for ch in txt if not unicodedata.combining(ch))
    txt = re.sub(r"[^a-z0-9]+", " ", txt)
    return " ".join(txt.split())


def slugify(txt: str) -> str:
    txt = norm(txt)
    return txt.replace(" ", "-")


def extract_text(row):
    parts = [row.get("title") or "", row.get("title_h1") or "", row.get("meta_title") or "", row.get("meta_description") or ""]
    c = row.get("content")
    if c:
        try:
            if isinstance(c, str):
                obj = json.loads(c)
            else:
                obj = c
            if isinstance(obj, dict):
                parts.append(obj.get("texto") or "")
        except Exception:
            parts.append(str(c))
    txt = " ".join([p for p in parts if p])
    return norm(txt)


def tokenize(clean_text: str):
    return [t for t in clean_text.split() if t and t not in STOPWORDS and len(t) > 1]


def build_ngrams(tokens, max_n=4):
    out = []
    n = len(tokens)
    for k in range(max_n, 0, -1):
        for i in range(0, n - k + 1):
            out.append(" ".join(tokens[i:i + k]))
    return out


def main(limit=2000, offset=0, apply=False):
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

    # popularity ranking from cars
    cur.execute("SELECT id_country, id_car_make, COUNT(*) c FROM cars WHERE id_car_make IS NOT NULL GROUP BY id_country,id_car_make")
    make_pop = {(r["id_country"].upper(), int(r["id_car_make"])): int(r["c"]) for r in cur.fetchall()}
    cur.execute("SELECT id_country, id_car_make, id_car_model, COUNT(*) c FROM cars WHERE id_car_make IS NOT NULL AND id_car_model IS NOT NULL GROUP BY id_country,id_car_make,id_car_model")
    model_pop = {(r["id_country"].upper(), int(r["id_car_make"]), int(r["id_car_model"])): int(r["c"]) for r in cur.fetchall()}

    # make dictionary
    cur.execute("SELECT id_car_make, name, slug FROM car_make")
    make_rows = cur.fetchall()
    make_by_phrase = defaultdict(list)
    for r in make_rows:
        mk = int(r["id_car_make"])
        for p in {norm(r["name"] or ""), norm((r.get("slug") or "").replace('-', ' '))}:
            if p:
                make_by_phrase[p].append(mk)

    # model dictionary (group by make)
    cur.execute("SELECT id_car_model, id_car_make, name, slug FROM car_model")
    model_rows = cur.fetchall()
    model_by_make_phrase = defaultdict(list)
    for r in model_rows:
        md = int(r["id_car_model"])
        mk = int(r["id_car_make"])
        for p in {norm(r["name"] or ""), norm((r.get("slug") or "").split('-', 1)[-1].replace('-', ' '))}:
            if p and p not in STOPWORDS:
                model_by_make_phrase[(mk, p)].append(md)

    # location dictionaries
    cur.execute("SELECT id, CC1, ADM1, SHORTFORM, FULLNAME, SLUG FROM cities")
    city_rows = cur.fetchall()
    city_by_country_phrase = defaultdict(list)
    city_to_state = {}
    for r in city_rows:
        cid = int(r["id"])
        cc = (r["CC1"] or "").upper()
        city_to_state[(cc, cid)] = (r.get("ADM1") or "").strip()
        for p in {norm(r.get("SHORTFORM") or ""), norm(r.get("FULLNAME") or ""), norm((r.get("SLUG") or "").replace('-', ' '))}:
            if p:
                city_by_country_phrase[(cc, p)].append(cid)

    cur.execute("SELECT id, CC1, ADM_CODE, SHORTFORM, FULLNAME, SLUG FROM states")
    state_rows = cur.fetchall()
    state_by_country_phrase = defaultdict(list)
    state_by_code = {}
    for r in state_rows:
        sid = int(r["id"])
        cc = (r["CC1"] or "").upper()
        code = (r.get("ADM_CODE") or "").strip()
        state_by_code[(cc, code)] = sid
        phrases = {
            norm(r.get("SHORTFORM") or ""),
            norm(r.get("FULLNAME") or ""),
            norm((r.get("SLUG") or "").replace('-', ' ')),
        }
        for p in list(phrases):
            if p.startswith("region "):
                phrases.add(p.replace("region ", "", 1))
            if p.startswith("estado "):
                phrases.add(p.replace("estado ", "", 1))
        for p in phrases:
            if p:
                state_by_country_phrase[(cc, p)].append(sid)

    cur.execute(
        """
        SELECT id, country_id, title, title_h1, meta_title, meta_description, content,
               id_car_make, id_car_model, id_state, id_city
        FROM car_landings
        WHERE activo=1
        ORDER BY id
        LIMIT %s OFFSET %s
        """,
        (limit, offset),
    )
    landings = cur.fetchall()

    updates = []
    matched = 0
    for row in landings:
        cc = COUNTRY_MAP.get((row.get("country_id") or "").lower())
        if not cc:
            continue

        clean_text = extract_text(row)
        toks = tokenize(clean_text)
        grams = build_ngrams(toks, max_n=4)

        # make candidates
        make_candidates = defaultdict(int)
        for g in grams:
            for mk in make_by_phrase.get(g, []):
                make_candidates[mk] += len(g.split()) * 3

        best_make = None
        best_make_score = -1
        for mk, sc in make_candidates.items():
            score = sc + min(5000, make_pop.get((cc, mk), 0))
            if score > best_make_score:
                best_make_score = score
                best_make = mk

        # model candidates, conditioned by make first
        best_model = None
        if best_make:
            model_candidates = defaultdict(int)
            for g in grams:
                for md in model_by_make_phrase.get((best_make, g), []):
                    model_candidates[md] += len(g.split()) * 4
            best_model_score = -1
            for md, sc in model_candidates.items():
                score = sc + min(10000, model_pop.get((cc, best_make, md), 0))
                if score > best_model_score:
                    best_model_score = score
                    best_model = md

        # location candidates
        best_city = None
        best_state = None
        city_candidates = defaultdict(int)
        state_candidates = defaultdict(int)
        for g in grams:
            for cid in city_by_country_phrase.get((cc, g), []):
                city_candidates[cid] += len(g.split()) * 4
            for sid in state_by_country_phrase.get((cc, g), []):
                state_candidates[sid] += len(g.split()) * 4

        if city_candidates:
            best_city = max(city_candidates.items(), key=lambda x: x[1])[0]
            adm1 = city_to_state.get((cc, best_city))
            if adm1:
                best_state = state_by_code.get((cc, adm1), None)

        if (not best_state) and state_candidates:
            best_state = max(state_candidates.items(), key=lambda x: x[1])[0]

        old = (row.get("id_car_make"), row.get("id_car_model"), row.get("id_state"), row.get("id_city"))
        new = (best_make, best_model, best_state, best_city)
        if any(v is not None for v in new) and new != old:
            updates.append((best_make, best_model, best_state, best_city, int(row["id"])))
            matched += 1

    print({"processed": len(landings), "to_update": len(updates), "matched": matched, "apply": apply})

    if apply and updates:
        cur.executemany(
            "UPDATE car_landings SET id_car_make=%s, id_car_model=%s, id_state=%s, id_city=%s WHERE id=%s",
            updates,
        )
        db.commit()
        print({"updated": cur.rowcount})
    else:
        db.rollback()

    cur.close()
    db.close()


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=2000)
    ap.add_argument("--offset", type=int, default=0)
    ap.add_argument("--apply", action="store_true")
    args = ap.parse_args()
    main(limit=args.limit, offset=args.offset, apply=args.apply)
