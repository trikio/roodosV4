#!/usr/bin/env python3
"""
Seminuevos (México): https://www.seminuevos.com/usados/-/autos

Cloudflare Managed Challenge (Turnstile) desde oct-2026: no hay forma de pasarlo
con un cliente HTTP plano (ni cloudscraper, que solo resuelve el desafío clásico
de JS). Se necesita un navegador real: `scrapling.fetchers.StealthySession` con
`solve_cloudflare=True` resuelve el Turnstile una vez y reutiliza la cookie
`cf_clearance` para el resto de la sesión (requiere `pip install
'scrapling[fetchers]'` + `patchright install chromium` en la máquina).

Mismo markup que Patiotuerca Ecuador (mismo proveedor de plataforma, platform
"ptx_migration_mexico" en las imágenes): un solo bloque `<script
type="ld+json">` de `@type: ItemList`, con `itemListElement[].item` de tipo
`["Product","Vehicle"]` (url, name, image, brand.name, model, modelDate,
mileageFromOdometer.value, vehicleTransmission, offers.price). Paginación
`?page=N`; una página sin itemListElement es el fin del listado.

URL del aviso (igual que las filas existentes): https://www.seminuevos.com/vehicle/{slug}/{id}

Cada aviso pasa por el mismo pipeline que los spiders (roodos_scraper.pipelines): resuelve marca y
modelo, transmisión, versión y miniatura. Antes se insertaba directo y quedaban sin marca ni modelo
(~7% resueltos) y con la transmisión al revés (1 es Manual, 2 Automático).
"""
import argparse
import json
import logging
import re
import sys
import time
from pathlib import Path
from types import SimpleNamespace
from typing import Optional

from parsel import Selector
from scrapling.fetchers import StealthySession

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from roodos_scraper.pipelines import MySQLRoodosPipeline  # noqa: E402

BASE = "https://www.seminuevos.com"
LIST_URL = f"{BASE}/usados/-/autos"


def to_int(value) -> Optional[int]:
    if value in (None, ""):
        return None
    d = "".join(ch for ch in str(value) if ch.isdigit())
    return int(d) if d else None



def item_type_is_vehicle(obj: dict) -> bool:
    t = obj.get("@type")
    return "Vehicle" in t if isinstance(t, list) else t == "Vehicle"


def fetch_items(session, page: int):
    url = f"{LIST_URL}?page={page}" if page > 1 else LIST_URL
    r = session.fetch(url, timeout=60000)
    if r.status != 200:
        raise RuntimeError(f"http {r.status}")
    sel = Selector(r.html_content)
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
    if not item_type_is_vehicle(item):
        return None
    url = item.get("url")
    if not url:
        return None

    brand = ((item.get("brand") or {}).get("name") or "").strip()
    model = (item.get("model") or "").strip()
    title = (item.get("name") or "").strip() or " ".join(x for x in (brand, model) if x) or "Sin título"

    image = item.get("image")
    if isinstance(image, list):
        image = image[0] if image else None

    transmission = item.get("vehicleTransmission") or ""
    details = f"trans:{transmission}" if transmission else None

    m = re.search(r"/(\d+)$", url)
    external_id = m.group(1) if m else url.rstrip("/").split("/")[-1]

    return {
        "url": url[:250],
        "slug": url.rstrip("/").split("/")[-1][:255],
        "title": title[:250],
        "image": (image or "")[:255] or None,
        "brand": brand,
        "model": model,
        "details": details,
        "price": to_int((item.get("offers") or {}).get("price")),
        "year": to_int(item.get("modelDate")),
        "km": to_int((item.get("mileageFromOdometer") or {}).get("value")),
        "external_id": external_id[:50],
    }


def to_item(row):
    """Fila -> item con las claves de roodos_scraper.items.ListingItem."""
    return {
        "vertical": "car", "source_name": "seminuevos_mexico", "source_label": "Seminuevos", "country": "MX",
        "url": row["url"], "slug": row["slug"], "title": row["title"], "image": row["image"],
        "image_big": row["image"], "details": row["details"], "price": row["price"], "year": row["year"],
        "km": row["km"], "external_id": row["external_id"], "brand_name": row["brand"] or None,
        "model_name": row["model"] or None,
    }


def main(max_pages: int, dry_run: bool, delay: float, max_empty_pages: int):
    spider = SimpleNamespace(name="seminuevos_mexico", logger=logging.getLogger("seminuevos_mexico"))
    pipeline = None
    if not dry_run:
        pipeline = MySQLRoodosPipeline()
        pipeline.open_spider(spider)

    stats = {"failed": 0}
    seen = set()
    empty_streak = 0

    with StealthySession(headless=True, solve_cloudflare=True, network_idle=True) as session:
        for page in range(1, max_pages + 1):
            try:
                entries = fetch_items(session, page)
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
                        print("item", {k: row[k] for k in ("url", "title", "brand", "model", "details", "price")})
                        continue
                    pipeline.process_item(to_item(row), spider)
                except Exception as e:
                    stats["failed"] += 1
                    print(f"row_error page={page} err={e}")

            if delay:
                time.sleep(delay)

    if pipeline is not None:
        stats.update(inserted=pipeline.inserted, updated=pipeline.updated)
        pipeline.close_spider(spider)
    print({**stats, "unique_seen": len(seen), "dry_run": dry_run})


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--max-pages", type=int, default=250)
    ap.add_argument("--max-empty-pages", type=int, default=2)
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--delay", type=float, default=1.0)
    args = ap.parse_args()
    main(max_pages=args.max_pages, dry_run=args.dry_run, delay=args.delay, max_empty_pages=args.max_empty_pages)
