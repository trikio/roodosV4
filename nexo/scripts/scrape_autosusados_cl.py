#!/usr/bin/env python3
import argparse
import os
import re
import unicodedata
from typing import Optional, Tuple

import mysql.connector
import requests
from dotenv import load_dotenv


def norm(text: str) -> str:
    if not text:
        return ""
    t = str(text).strip().lower()
    t = unicodedata.normalize("NFKD", t)
    t = "".join(ch for ch in t if not unicodedata.combining(ch))
    t = re.sub(r"[^a-z0-9]+", " ", t)
    return " ".join(t.split())


def to_int(value) -> Optional[int]:
    if value in (None, ""):
        return None
    d = "".join(ch for ch in str(value) if ch.isdigit())
    if not d:
        return None
    n = int(d)
    return n if n <= 2147483647 else None


class Resolver:
    def __init__(self, cur):
        self.cur = cur
        self.make_by_norm = {}
        self.make_names = []
        self.models_by_make = {}
        self._load()

    def _load(self):
        self.cur.execute("SELECT id_car_make, name FROM car_make")
        for r in self.cur.fetchall():
            n = norm(r["name"] or "")
            if n and n not in self.make_by_norm:
                self.make_by_norm[n] = r["id_car_make"]
        self.make_names = sorted(self.make_by_norm.keys(), key=len, reverse=True)

        self.cur.execute("SELECT id_car_model, id_car_make, name FROM car_model")
        for r in self.cur.fetchall():
            mk = r["id_car_make"]
            self.models_by_make.setdefault(mk, []).append((norm(r["name"] or ""), r["id_car_model"]))
        for mk in self.models_by_make:
            self.models_by_make[mk].sort(key=lambda x: len(x[0]), reverse=True)

    def resolve_make_model(self, title: str, brand_hint: str = "", model_hint: str = "") -> Tuple[Optional[int], Optional[int]]:
        t = norm(f"{title} {brand_hint} {model_hint}")
        make_id = None
        for m in self.make_names:
            if re.search(rf"(^|\s){re.escape(m)}(\s|$)", t):
                make_id = self.make_by_norm[m]
                break

        model_id = None
        if make_id:
            for mn, mid in self.models_by_make.get(make_id, []):
                if mn and re.search(rf"(^|\s){re.escape(mn)}(\s|$)", t):
                    model_id = mid
                    break
        return make_id, model_id


def main(max_pages: int = 100):
    load_dotenv()

    db = mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "localhost"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        user=os.getenv("MYSQL_USER", "trikio"),
        password=os.getenv("MYSQL_PASSWORD", ""),
        database=os.getenv("MYSQL_DATABASE", "roodos"),
        autocommit=False,
    )
    cur = db.cursor(dictionary=True)
    resolver = Resolver(cur)

    sql = """
    INSERT INTO cars (
      id_country, url, slug, title, image, image_big,
      id_car_make, id_car_model, id_state, id_city, details,
      price, year, km, id_fuel, id_body, id_transmission,
      status, created, updated, nexo_id, nexo_flag
    ) VALUES (
      'CL', %s, %s, %s, %s, %s,
      %s, %s, NULL, NULL, %s,
      %s, %s, %s, NULL, NULL, NULL,
      1, NOW(), NOW(), 'AutoSusados', 0
    )
    ON DUPLICATE KEY UPDATE
      slug=VALUES(slug), title=VALUES(title), image=VALUES(image), image_big=VALUES(image_big),
      id_car_make=VALUES(id_car_make), id_car_model=VALUES(id_car_model),
      details=VALUES(details), price=VALUES(price), year=VALUES(year), km=VALUES(km), updated=VALUES(updated),
      nexo_id=VALUES(nexo_id), status=1
    """

    build = requests.get("https://autosusados.cl/vehiculos", timeout=40, headers={"User-Agent": "Mozilla/5.0"}).text
    m = re.search(r'"buildId":"([^"]+)"', build)
    if not m:
        raise RuntimeError("buildId not found")
    build_id = m.group(1)
    api = f"https://autosusados.cl/_next/data/{build_id}/vehiculos.json"

    s = requests.Session()
    s.headers.update({"User-Agent": "Mozilla/5.0", "Accept": "application/json"})

    inserted = updated = failed = 0
    seen_ids = set()

    for page in range(1, max_pages + 1):
        try:
            r = s.get(api, params={"category": "vehiculos", "page": page}, timeout=45)
            r.raise_for_status()
            js = r.json()
            posts = (js.get("pageProps") or {}).get("initialPosts") or []
            print(f"page={page} items={len(posts)}")
            if not posts:
                break

            new_in_page = 0
            for x in posts:
                try:
                    car_id = str(x.get("carID") or "").strip()
                    if not car_id or car_id in seen_ids:
                        continue
                    seen_ids.add(car_id)
                    new_in_page += 1

                    pub_slug = (x.get("publicationSlug") or "").strip()
                    if pub_slug:
                        url = f"https://autosusados.cl/usados/{pub_slug}"
                        slug = pub_slug[:255]
                    else:
                        b = norm(x.get("brandName") or "").replace(" ", "-")
                        mo = norm(x.get("modelName") or "").replace(" ", "-")
                        url = f"https://autosusados.cl/vehiculos/{b}/{mo}/1/{car_id}"
                        slug = car_id

                    brand = (x.get("brandName") or "").strip()
                    model = (x.get("modelName") or "").strip()
                    desc = (x.get("description") or "").strip()
                    year = to_int(x.get("year"))
                    title = desc or f"{brand} {model} {year or ''}".strip()

                    price = None
                    if isinstance(x.get("price"), dict):
                        price = to_int((x.get("price") or {}).get("CLP"))
                    else:
                        price = to_int(x.get("price"))
                    km = to_int(x.get("kilometers"))
                    image = (x.get("mainImage") or x.get("photo") or "")[:255]

                    details = []
                    if x.get("fuelName"):
                        details.append(f"fuel:{x.get('fuelName')}")
                    if x.get("transmissionName"):
                        details.append(f"trans:{x.get('transmissionName')}")
                    if x.get("region"):
                        details.append(f"region:{x.get('region')}")
                    details = " | ".join(details)[:1900] if details else None

                    cur.execute("SELECT id FROM cars WHERE id_country='CL' AND url=%s LIMIT 1", (url[:250],))
                    exists = cur.fetchone() is not None

                    mk, md = resolver.resolve_make_model(title, brand, model)

                    cur.execute(
                        sql,
                        (
                            url[:250], slug, title[:250], image, image,
                            mk, md, details, price, year, km,
                        ),
                    )

                    if exists:
                        updated += 1
                    else:
                        inserted += 1
                except Exception as e:
                    failed += 1
                    print(f"row_error page={page} err={e}")

            db.commit()
            if new_in_page == 0:
                break
        except Exception as e:
            failed += 1
            print(f"page_error page={page} err={e}")

    cur.close()
    db.close()
    print({"inserted": inserted, "updated": updated, "failed": failed})


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--max-pages", type=int, default=100)
    args = ap.parse_args()
    main(max_pages=args.max_pages)
