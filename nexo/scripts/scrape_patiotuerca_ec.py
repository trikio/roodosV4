#!/usr/bin/env python3
"""
Patiotuerca Ecuador: https://ecuador.patiotuerca.com/usados/-/autos?fts=usados

Desde nov-2026 Cloudflare responde 403 a `cloudscraper` (las corridas del 18 y 23 de nov vinieron
vacías). Como Seminuevos México (misma plataforma), se lee con un navegador real:
`scrapling.fetchers.StealthySession` con `solve_cloudflare=True`.

El listado tiene un único bloque `<script type="ld+json">` de `@type: ItemList`, con
`itemListElement[].item` de tipo `Vehicle` (url, name, image, brand.name, model, vehicleModelDate,
mileageFromOdometer.value, offers.price). La ciudad no viene ahí: está en la tarjeta, junto al
ícono `lucide-map-pin`, y se une al aviso por el id al final de su URL.

Paginación: `?page=N`; una página sin itemListElement es el fin del listado.

Cada aviso pasa por el mismo pipeline que los spiders (roodos_scraper.pipelines): marca y modelo,
ubicación, versión y miniatura.

URL del aviso (igual que las filas existentes): https://ecuador.patiotuerca.com/vehicle/{slug}/{id}
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

BASE = "https://ecuador.patiotuerca.com"
LIST_URL = f"{BASE}/usados/-/autos?fts=usados"
ID_RE = re.compile(r"/(\d+)/?$")


def to_int(value) -> Optional[int]:
    if value in (None, ""):
        return None
    d = "".join(ch for ch in str(value) if ch.isdigit())
    return int(d) if d else None


def fetch_page(session, page: int):
    """(entradas del ItemList, {id del aviso: ciudad})."""
    r = session.fetch(f"{LIST_URL}&page={page}", timeout=60000)
    if r.status != 200:
        raise RuntimeError(f"http {r.status}")
    sel = Selector(r.html_content)
    entries = []
    for raw in sel.css("script[type='application/ld+json']::text").getall():
        try:
            obj = json.loads(raw)
        except Exception:
            continue
        if obj.get("@type") == "ItemList":
            entries = obj.get("itemListElement") or []
            break
    cities = {}
    for pin in sel.xpath("//svg[contains(@class,'lucide-map-pin')]/following-sibling::span[1]"):
        city = (pin.xpath("normalize-space(.)").get() or "").strip()
        href = pin.xpath("ancestor::*[.//a[contains(@href,'/vehicle/')]][1]//a[contains(@href,'/vehicle/')]/@href").get()
        m = ID_RE.search(href or "")
        if city and m:
            cities.setdefault(m.group(1), city)
    return entries, cities


def build_item(entry: dict, cities: dict):
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

    m = ID_RE.search(url)
    external_id = m.group(1) if m else url.rstrip("/").split("/")[-1]

    return {
        "vertical": "car", "source_name": "patiotuerca_ecuador", "source_label": "Patiotuerca", "country": "EC",
        "url": url[:250],
        "slug": url.rstrip("/").split("/")[-1][:255],
        "title": title[:250],
        "image": (image or "")[:255] or None,
        "image_big": (image or "")[:255] or None,
        "details": None,
        "price": to_int((item.get("offers") or {}).get("price")),
        "year": to_int(item.get("vehicleModelDate")),
        "km": to_int((item.get("mileageFromOdometer") or {}).get("value")),
        "external_id": external_id[:50],
        "brand_name": brand or None,
        "model_name": model or None,
        "location": cities.get(external_id),
    }


def main(max_pages: int, dry_run: bool, delay: float, max_empty_pages: int):
    spider = SimpleNamespace(name="patiotuerca_ecuador", logger=logging.getLogger("patiotuerca_ecuador"))
    pipeline = None
    if not dry_run:
        pipeline = MySQLRoodosPipeline()
        pipeline.open_spider(spider)

    stats = {"failed": 0, "with_city": 0}
    seen = set()
    empty_streak = 0

    with StealthySession(headless=True, solve_cloudflare=True, network_idle=True) as session:
        for page in range(1, max_pages + 1):
            try:
                entries, cities = fetch_page(session, page)
            except Exception as e:
                print(f"page_error page={page} err={e}")
                entries, cities = [], {}

            print(f"page={page} items={len(entries)} cities={len(cities)}")
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
                    item = build_item(entry, cities)
                    if not item or item["url"] in seen:
                        continue
                    seen.add(item["url"])
                    stats["with_city"] += bool(item["location"])
                    if dry_run:
                        print("item", {k: item[k] for k in ("url", "title", "price", "year", "km", "location")})
                        continue
                    pipeline.process_item(item, spider)
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
    ap.add_argument("--max-pages", type=int, default=400)
    ap.add_argument("--max-empty-pages", type=int, default=2)
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--delay", type=float, default=1.0)
    args = ap.parse_args()
    main(max_pages=args.max_pages, dry_run=args.dry_run, delay=args.delay, max_empty_pages=args.max_empty_pages)
