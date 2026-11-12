#!/usr/bin/env python3
"""
AutoSusados (Chile): https://autosusados.cl/vehiculos

El listado es Next.js. Se lee el JSON de datos de la página
(/_next/data/{buildId}/vehiculos.json?category=vehiculos&page=N), cuyo
pageProps.initialPosts (desde nov-2026) es un objeto:
  {"pagination": {actualPage, pageSize, totalPages, totalRows, seed},
   "totals": {vehicles, dealers, privateOwners},
   "items": [ {carID, brandName, modelName, description, year, price,
               currency, kilometers, fuelName, transmissionName, region,
               photo, table, ...}, ... ]}
(antes era directamente la lista de avisos).

Sin "order" el sitio baraja los avisos con una semilla que cambia cada hora y
repite los destacados (exposureMultiplier), así que un recorrido largo pierde
y duplica avisos. Con order=VCHPRECIO desc el orden es estable y cada aviso
sale una sola vez (totalRows == totals.vehicles), así que se pide así.

URL del aviso (igual que las filas existentes):
  https://autosusados.cl/vehiculos/{marca}/{modelo}/{table}/{carID}
"""
import argparse
import os
import re
import time
import unicodedata
from typing import Optional, Tuple

import requests

BASE = "https://autosusados.cl"
LIST_URL = f"{BASE}/vehiculos"
ORDER = "VCHPRECIO desc"
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36"


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
    if isinstance(value, float):
        value = int(value)
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


def get_build_id(session: requests.Session) -> str:
    html = session.get(LIST_URL, timeout=40, headers={"Accept": "text/html"}).text
    m = re.search(r'"buildId":"([^"]+)"', html)
    if not m:
        raise RuntimeError("buildId not found")
    return m.group(1)


def parse_posts(js: dict):
    """Devuelve (items, pagination, totals) para el formato nuevo y el viejo."""
    posts = (js.get("pageProps") or {}).get("initialPosts")
    if isinstance(posts, dict):
        items = posts.get("items") or []
        return items, posts.get("pagination") or {}, posts.get("totals") or {}
    if isinstance(posts, list):
        return posts, {}, {}
    return [], {}, {}


def build_row(x: dict):
    car_id = str(x.get("carID") or "").strip()
    if not car_id:
        return None

    brand = (x.get("brandName") or "").strip()
    model = (x.get("modelName") or "").strip()

    pub_slug = (x.get("publicationSlug") or "").strip()
    if pub_slug:
        url = f"{BASE}/usados/{pub_slug}"
        slug = pub_slug[:255]
    else:
        b = norm(brand).replace(" ", "-")
        mo = norm(model).replace(" ", "-")
        table = to_int(x.get("table")) or 1
        url = f"{BASE}/vehiculos/{b}/{mo}/{table}/{car_id}"
        slug = car_id

    desc = (x.get("description") or "").strip()
    year = to_int(x.get("year"))
    title = desc or f"{brand} {model} {year or ''}".strip()

    raw_price = x.get("price")
    if isinstance(raw_price, dict):
        price = to_int(raw_price.get("CLP"))
    else:
        price = to_int(raw_price)
    currency = (x.get("currency") or "$").strip()
    if currency not in ("$", "CLP"):
        # Precio en otra moneda (p. ej. UF): no se guarda como CLP.
        price = None
    if not price:
        price = None

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

    return {
        "car_id": car_id,
        "url": url[:250],
        "slug": slug,
        "title": title[:250],
        "image": image,
        "brand": brand,
        "model": model,
        "details": details,
        "price": price,
        "year": year,
        "km": km,
    }


SQL = """
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


def main(max_pages: int = 400, dry_run: bool = False, delay: float = 1.0):
    db = cur = resolver = None
    if not dry_run:
        import mysql.connector
        from dotenv import load_dotenv

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

    s = requests.Session()
    s.headers.update({"User-Agent": UA, "Accept": "application/json"})

    state = {"build_id": get_build_id(s), "site_total": None, "total_pages": None}
    stats = {"inserted": 0, "updated": 0, "failed": 0}
    seen_ids = set()

    def fetch(page: int):
        """Items de la página, o None si vino vacía antes del final."""
        for attempt in range(3):
            api = f"{BASE}/_next/data/{state['build_id']}/vehiculos.json"
            r = s.get(api, params={"category": "vehiculos", "page": page, "order": ORDER}, timeout=45)
            if r.status_code == 404:
                # Nuevo deploy de Next.js: el buildId cambió a mitad de corrida.
                new_id = get_build_id(s)
                if new_id == state["build_id"]:
                    r.raise_for_status()
                print(f"buildId changed {state['build_id']} -> {new_id}")
                state["build_id"] = new_id
                continue
            r.raise_for_status()
            items, pagination, totals = parse_posts(r.json())
            if pagination.get("totalPages"):
                state["total_pages"] = int(pagination["totalPages"])
            if totals.get("vehicles") is not None:
                state["site_total"] = totals.get("vehicles")
            elif pagination.get("totalRows") is not None:
                state["site_total"] = pagination.get("totalRows")
            print(f"page={page}/{state['total_pages'] or '?'} items={len(items)}")
            if items:
                return items
            if not state["total_pages"] or page >= state["total_pages"]:
                return []  # fin real del listado
            # El backend a veces devuelve una página vacía en medio del
            # listado (dura unos segundos); se reintenta.
            time.sleep(3 * (attempt + 1))
        return None

    def save(page: int, items) -> int:
        new_in_page = 0
        for x in items:
            try:
                if not isinstance(x, dict):
                    raise ValueError(f"unexpected item type {type(x).__name__}")
                row = build_row(x)
                if not row or row["car_id"] in seen_ids:
                    continue
                seen_ids.add(row["car_id"])
                new_in_page += 1

                if dry_run:
                    print("item", {k: row[k] for k in ("url", "slug", "title", "price", "year", "km", "image", "details")})
                    continue

                cur.execute("SELECT id FROM cars WHERE id_country='CL' AND url=%s LIMIT 1", (row["url"],))
                exists = cur.fetchone() is not None
                mk, md = resolver.resolve_make_model(row["title"], row["brand"], row["model"])
                cur.execute(
                    SQL,
                    (
                        row["url"], row["slug"], row["title"], row["image"], row["image"],
                        mk, md, row["details"], row["price"], row["year"], row["km"],
                    ),
                )
                stats["updated" if exists else "inserted"] += 1
            except Exception as e:
                stats["failed"] += 1
                print(f"row_error page={page} err={e}")
        if db is not None:
            db.commit()
        return new_in_page

    reached_end = False
    pages_done = 0
    pending = []  # páginas que vinieron vacías antes del final
    page = 1
    while page <= max_pages:
        try:
            items = fetch(page)
            if items is None:
                print(f"page_empty page={page}; se reintenta al final")
                pending.append(page)
                if len(pending) >= 3 and pending[-3:] == list(range(page - 2, page + 1)):
                    print("3 páginas vacías seguidas; se corta el recorrido")
                    break
            elif not items:
                reached_end = True
                break
            else:
                pages_done += 1
                new_in_page = save(page, items)
                if state["total_pages"] and page >= state["total_pages"]:
                    reached_end = True
                    break
                if new_in_page == 0 and not state["total_pages"]:
                    print(f"page={page} sin avisos nuevos; se detiene")
                    break
        except Exception as e:
            stats["failed"] += 1
            print(f"page_error page={page} err={e}")
            pending.append(page)
        page += 1
        if delay:
            time.sleep(delay)

    for rnd in range(2):
        if not pending:
            break
        print(f"reintentando páginas {pending} (ronda {rnd + 1})")
        time.sleep(30)
        still = []
        for p in pending:
            try:
                items = fetch(p)
                if items:
                    pages_done += 1
                    save(p, items)
                elif items is None:
                    still.append(p)
            except Exception as e:
                print(f"page_error page={p} err={e}")
                still.append(p)
            time.sleep(delay)
        pending = still
    stats["failed"] += len(pending)

    if cur is not None:
        cur.close()
    if db is not None:
        db.close()
    print({
        **stats,
        "unique_seen": len(seen_ids),
        "site_total": state["site_total"],
        "pages_done": pages_done,
        "total_pages": state["total_pages"],
        "pages_lost": pending,
        "reached_end": reached_end,
        "dry_run": dry_run,
    })


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--max-pages", type=int, default=400,
                    help="tope de páginas (20 avisos c/u); ~363 cubren todo el sitio en nov-2026")
    ap.add_argument("--dry-run", action="store_true", help="imprime los avisos sin escribir en MySQL")
    ap.add_argument("--delay", type=float, default=1.0, help="segundos entre páginas")
    args = ap.parse_args()
    main(max_pages=args.max_pages, dry_run=args.dry_run, delay=args.delay)
