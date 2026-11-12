#!/usr/bin/env python3
import os
import re
import unicodedata
import mysql.connector
from dotenv import load_dotenv


def norm(s: str) -> str:
    if not s:
        return ""
    s = str(s).strip().lower()
    s = unicodedata.normalize("NFKD", s)
    s = "".join(ch for ch in s if not unicodedata.combining(ch))
    s = re.sub(r"[^a-z0-9]+", " ", s)
    return " ".join(s.split())


def load_dict(cur, idx_name: str):
    cur.execute(f"SELECT id,name,names FROM {idx_name}")
    rows = cur.fetchall()
    terms = []
    for r in rows:
        rid = int(r["id"])
        bag = []
        if r.get("name"):
            bag.append(r["name"])
        if r.get("names"):
            bag.append(r["names"])
        seen = set()
        for b in bag:
            for t in norm(b).split():
                if len(t) < 3:
                    continue
                if t in seen:
                    continue
                seen.add(t)
                terms.append((t, rid))
    terms.sort(key=lambda x: len(x[0]), reverse=True)
    return terms


def match_from_terms(title_norm: str, terms):
    if not title_norm:
        return None
    for t, rid in terms:
        if re.search(rf"(^|\s){re.escape(t)}(\s|$)", title_norm):
            return rid
    return None


def loc_ids(cur, title_norm: str):
    if not title_norm:
        return (None, None)
    try:
        cur.execute(
            "SELECT id,id_state FROM location WHERE MATCH(%s) ORDER BY puntaje DESC, WEIGHT() DESC LIMIT 1",
            (title_norm,),
        )
        r = cur.fetchone()
        if not r:
            return (None, None)
        city = int(r["id"]) if r.get("id") is not None else None
        state = int(r["id_state"]) if r.get("id_state") is not None else None
        return (city, state)
    except Exception:
        return (None, None)


def main(batch_size=3000):
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

    mc = mysql.connector.connect(host="127.0.0.1", port=9306)
    mcur = mc.cursor(dictionary=True)

    op_terms = load_dict(mcur, "house_operation")
    type_terms = load_dict(mcur, "house_type")

    sql = """
    INSERT INTO house_landings (
      country_id, slug, title, title_h1, meta_title, meta_description, content,
      activo, valor, id_house_operation, id_house_type, id_state, id_city, picked, indexable
    ) VALUES (
      %s, %s, %s, %s, %s, %s, NULL,
      0, 0, %s, %s, %s, %s, 0, NULL
    )
    ON DUPLICATE KEY UPDATE
      title=VALUES(title),
      title_h1=VALUES(title_h1),
      meta_title=VALUES(meta_title),
      meta_description=VALUES(meta_description),
      id_house_operation=VALUES(id_house_operation),
      id_house_type=VALUES(id_house_type),
      id_state=VALUES(id_state),
      id_city=VALUES(id_city)
    """

    last_id = 0
    touched = 0
    loc_cache = {}

    while True:
        cur.execute(
            "SELECT id,id_country,slug,title FROM house_landings_back WHERE id>%s ORDER BY id LIMIT %s",
            (last_id, batch_size),
        )
        rows = cur.fetchall()
        if not rows:
            break

        for r in rows:
            last_id = r["id"]
            country = (r.get("id_country") or "cl").lower()[:3]
            slug = (r.get("slug") or "").strip()[:250]
            title = (r.get("title") or "").strip()
            if not slug or not title:
                continue
            title250 = title[:250]
            tn = norm(title)

            op_id = match_from_terms(tn, op_terms)
            type_id = match_from_terms(tn, type_terms)

            if tn in loc_cache:
                city_id, state_id = loc_cache[tn]
            else:
                city_id, state_id = loc_ids(mcur, tn)
                loc_cache[tn] = (city_id, state_id)

            cur.execute(
                sql,
                (
                    country, slug, title250, title250, title250, title250,
                    op_id, type_id, state_id, city_id,
                ),
            )
            touched += 1

        db.commit()
        print(f"progress last_id={last_id} touched={touched}")

    print({"touched": touched})
    mcur.close(); mc.close(); cur.close(); db.close()


if __name__ == "__main__":
    main()
