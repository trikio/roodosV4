#!/usr/bin/env python3
"""
Patiotuerca Ecuador: https://ecuador.patiotuerca.com/usados/-/autos?fts=usados

Bloqueada por un desafío clásico de Cloudflare (desde antes de nov-2026); con
`cloudscraper` (ya usado para Auto.cl) resuelve el desafío y pasa.

El listado también cambió de markup: ya no hay bloques `<script type="ld+json">`
con `@type: Car` por auto. Ahora hay un único bloque `@type: ItemList` con
`itemListElement[].item` de tipo `Vehicle` (url, name, image, brand.name, model,
vehicleModelDate, mileageFromOdometer.value, offers.price). Sin bodyType ni
fuelType: no se guarda esa parte de `details`.

Paginación: `?page=N` (entero simple; el token base64 viejo del spider de
Scrapy ya no responde nada). Una página sin itemListElement es el fin del
listado.

URL del aviso (igual que las filas existentes): https://ecuador.patiotuerca.com/vehicle/{slug}/{id}
"""
import argparse
import json
import os
import re
import time
from typing import Optional

import cloudscraper
from parsel import Selector

BASE = "https://ecuador.patiotuerca.com"
LIST_URL = f"{BASE}/usados/-/autos"


def to_int(value) -> Optional[int]:
    if value in (None, ""):
        return None
    d = "".join(ch for ch in str(value) if ch.isdigit())
    return int(d) if d else None


def fetch_items(scraper, page: int):
    r = scraper.get(LIST_URL, params={"fts": "usados", "page": page}, timeout=30)
    r.raise_for_status()
    sel = Selector(r.text)
    for raw in sel.css("script[type='application/ld+json']::text").getall():
        try:
            obj = json.loads(raw)
        except Exception:
            continue
        if obj.get("@type") == "ItemList":
            return obj.get("itemListElement") or []
    return []


def build_row(entry: dict):
    item = (entry or {}).get("item") or {}
    url = item.get("url")
    if not url:
        return None

    brand = ((item.get("brand") or {}).get("name") or "").strip()
    model = (item.get("model") or "").strip()
    title = (item.get("name") or "").strip() or " ".join(x for x in (brand, model) if x) or "Sin título"

    image = item.get("image")
    if isinstance(image, list):
        image = image[0] if image else None

    m = re.search(r"/(\d+)$", url)
    external_id = m.group(1) if m else url.rstrip("/").split("/")[-1]

    return {
        "url": url[:250],
        "slug": url.rstrip("/").split("/")[-1][:255],
        "title": title[:250],
        "image": (image or "")[:255] or None,
        "brand": brand,
        "model": model,
        "price": to_int((item.get("offers") or {}).get("price")),
        "year": to_int(item.get("vehicleModelDate")),
        "km": to_int((item.get("mileageFromOdometer") or {}).get("value")),
        "external_id": external_id[:50],
    }


SQL = """
INSERT INTO cars (
  id_country, url, slug, title, image, image_big,
  id_car_make, id_car_model, id_state, id_city, details,
  price, year, km, id_fuel, id_body, id_transmission,
  status, created, updated, nexo_id, nexo_flag
) VALUES (
  'EC', %s, %s, %s, %s, %s,
  NULL, NULL, NULL, NULL, NULL,
  %s, %s, %s, NULL, NULL, NULL,
  1, NOW(), NOW(), 'Patiotuerca', 0
)
ON DUPLICATE KEY UPDATE
  slug=VALUES(slug), title=VALUES(title), image=VALUES(image), image_big=VALUES(image_big),
  price=VALUES(price), year=VALUES(year), km=VALUES(km), updated=VALUES(updated),
  nexo_id=VALUES(nexo_id), status=1
"""


def main(max_pages: int, dry_run: bool, delay: float, max_empty_pages: int):
    db = cur = None
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
        cur = db.cursor()

    scraper = cloudscraper.create_scraper(browser={"browser": "chrome", "platform": "linux", "mobile": False})

    stats = {"inserted": 0, "updated": 0, "failed": 0}
    seen = set()
    empty_streak = 0

    for page in range(1, max_pages + 1):
        try:
            entries = fetch_items(scraper, page)
        except Exception as e:
            print(f"page_error page={page} err={e}")
            entries = []

        print(f"page={page} items={len(entries)}")
        if not entries:
            empty_streak += 1
            if empty_streak >= max_empty_pages:
                print(f"fin del listado en page={page}")
                break
            if delay:
                time.sleep(delay)
            continue
        empty_streak = 0

        for entry in entries:
            try:
                row = build_row(entry)
                if not row or row["url"] in seen:
                    continue
                seen.add(row["url"])

                if dry_run:
                    print("item", {k: row[k] for k in ("url", "title", "price", "year", "km", "image")})
                    continue

                cur.execute("SELECT id FROM cars WHERE id_country='EC' AND url=%s LIMIT 1", (row["url"],))
                exists = cur.fetchone() is not None
                cur.execute(
                    SQL,
                    (row["url"], row["slug"], row["title"], row["image"], row["image"],
                     row["price"], row["year"], row["km"]),
                )
                stats["updated" if exists else "inserted"] += 1
            except Exception as e:
                stats["failed"] += 1
                print(f"row_error page={page} err={e}")

        if db is not None:
            db.commit()
        if delay:
            time.sleep(delay)

    if cur is not None:
        cur.close()
    if db is not None:
        db.close()
    print({**stats, "unique_seen": len(seen), "dry_run": dry_run})


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--max-pages", type=int, default=400)
    ap.add_argument("--max-empty-pages", type=int, default=2)
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--delay", type=float, default=1.0)
    args = ap.parse_args()
    main(max_pages=args.max_pages, dry_run=args.dry_run, delay=args.delay, max_empty_pages=args.max_empty_pages)
