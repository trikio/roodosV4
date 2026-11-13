#!/usr/bin/env python3
"""
Bruno Fritsch (Chile): https://www.brunofritsch.cl/autos-usados

Akamai retiene (y, tras unos pocos requests, resetea) las conexiones de
clientes HTTP planos (curl, requests, Scrapy); un navegador real con
fingerprint de Chromium sí pasa. Se usa `scrapling.fetchers.StealthySession`
(sin resolver Cloudflare: no es ese el bloqueo) para traer cada página del
listado, igual que hacía el spider de Scrapy que reemplaza este script
(requiere `pip install 'scrapling[fetchers]'` + `patchright install chromium`
en la máquina).

Oracle Commerce Cloud. Cada página del listado (?page=N, 14 autos) trae el
resultado de búsqueda completo en `window.state = JSON.parse(decodeURI("..."))`
(searchRepository.pages[...].results: totalNumRecs, recsPerPage, records[]
con attributes product.* / sku.*). Se mapea desde ese JSON, sin pedir las
fichas: ya trae marca, modelo, versión, año, km, precio e imagen.
La página posterior a la última viene sin records.

URL del aviso: https://www.brunofritsch.cl/autos-usados/{patente}
"""
import argparse
import json
import os
import re
import time
from typing import Optional
from urllib.parse import unquote

from scrapling.fetchers import StealthySession

BASE = "https://www.brunofritsch.cl"
LIST_URL = f"{BASE}/autos-usados"


def to_int(value) -> Optional[int]:
    if value in (None, ""):
        return None
    digits = "".join(ch for ch in str(value) if ch.isdigit())
    return int(digits) if digits else None


def to_price(value) -> Optional[int]:
    """'9990000.000000' -> 9990000; '2026.0' -> 2026."""
    if value in (None, ""):
        return None
    try:
        n = int(float(str(value).replace(",", "")))
    except ValueError:
        return to_int(value)
    return n if n > 0 else None


def extract_results(html: str):
    m = re.search(r'window\.state\s*=\s*JSON\.parse\(decodeURI\("(.*?)"\)\)', html, re.S)
    if not m:
        return None
    try:
        state = json.loads(unquote(m.group(1)))
    except Exception:
        return None
    pages = (state.get("searchRepository") or {}).get("pages") or {}
    for page in pages.values():
        results = (page or {}).get("results")
        if isinstance(results, dict) and "records" in results:
            return results
    return None


def attr(attrs: dict, key: str):
    v = attrs.get(key)
    if isinstance(v, list):
        v = v[0] if v else None
    return v.strip() if isinstance(v, str) else v


def build_row(rec: dict):
    attrs = rec.get("attributes") or {}

    plate = attr(attrs, "sku.listingId") or attr(attrs, "product.repositoryId")
    route = attr(attrs, "product.route") or (f"/autos-usados/{plate}" if plate else None)
    if not route:
        return None
    url = BASE + route if route.startswith("/") else route

    brand = attr(attrs, "product.x_marca") or attr(attrs, "product.brand")
    model = attr(attrs, "product.x_modelo")
    display = attr(attrs, "product.displayName") or ""
    version = attr(attrs, "product.x_version")
    title = " ".join(p for p in (brand, display) if p) or plate or "Sin título"

    price = to_price(attr(attrs, "sku.activePrice") or attr(attrs, "sku.minActivePrice"))
    if price is None:
        for child in rec.get("records") or []:
            cattrs = child.get("attributes") or {}
            cp = cattrs.get("sku.activePrice") or cattrs.get("sku.listPrice")
            price = to_price(cp[0] if isinstance(cp, list) and cp else cp)
            if price:
                break

    year = to_price(attr(attrs, "product.x_agno"))
    if year and not 1950 <= year <= 2100:
        year = None
    km = to_price(attr(attrs, "product.x_kilometraje"))

    image = attr(attrs, "product.primarySmallImageURL") or attr(attrs, "product.primaryFullImageURL")
    if image:
        image = re.sub(r"&(height|width)=\d+", "", image)
        image = BASE + image if image.startswith("/") else image

    details = []
    if version:
        details.append(f"version:{version}")
    if attr(attrs, "product.x_combustible"):
        details.append(f"fuel:{attr(attrs, 'product.x_combustible')}")
    if attr(attrs, "product.x_transmision"):
        details.append(f"trans:{attr(attrs, 'product.x_transmision')}")
    if attr(attrs, "product.x_tipo"):
        details.append(f"body:{attr(attrs, 'product.x_tipo')}")

    return {
        "url": url[:250],
        "slug": url.rstrip("/").split("/")[-1][:255],
        "title": (title or "Sin título")[:250],
        "image": image,
        "brand": brand,
        "model": model or display,
        "details": " | ".join(details)[:1000] if details else None,
        "price": price,
        "year": year,
        "km": km,
        "external_id": str(plate)[:50] if plate else None,
    }


SQL = """
INSERT INTO cars (
  id_country, url, slug, title, image, image_big,
  id_car_make, id_car_model, id_state, id_city, details,
  price, year, km, id_fuel, id_body, id_transmission,
  status, created, updated, nexo_id, nexo_flag
) VALUES (
  'CL', %s, %s, %s, %s, %s,
  NULL, NULL, NULL, NULL, %s,
  %s, %s, %s, NULL, NULL, NULL,
  1, NOW(), NOW(), 'BrunoFritsch', 0
)
ON DUPLICATE KEY UPDATE
  slug=VALUES(slug), title=VALUES(title), image=VALUES(image), image_big=VALUES(image_big),
  details=VALUES(details), price=VALUES(price), year=VALUES(year), km=VALUES(km),
  updated=VALUES(updated), nexo_id=VALUES(nexo_id), status=1
"""


def main(max_pages: int, dry_run: bool, delay: float):
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

    stats = {"inserted": 0, "updated": 0, "failed": 0}
    seen = set()
    last_page = None

    with StealthySession(headless=True, network_idle=True) as session:
        for page in range(1, max_pages + 1):
            url = LIST_URL if page == 1 else f"{LIST_URL}?page={page}"
            try:
                r = session.fetch(url, timeout=60000)
                results = extract_results(r.html_content) if r.status == 200 else None
            except Exception as e:
                print(f"page_error page={page} err={e}")
                results = None

            if results is None:
                print(f"page={page} sin window.state; se detiene")
                break

            records = results.get("records") or []
            total = to_int(results.get("totalNumRecs"))
            per_page = to_int(results.get("recsPerPage")) or len(records) or 14
            if total:
                last_page = -(-total // per_page)
            print(f"page={page}/{last_page or '?'} records={len(records)} total={total}")

            if not records:
                break

            for rec in records:
                try:
                    row = build_row(rec)
                    if not row or row["url"] in seen:
                        continue
                    seen.add(row["url"])

                    if dry_run:
                        print("item", {k: row[k] for k in ("url", "title", "price", "year", "km", "image")})
                        continue

                    cur.execute("SELECT id FROM cars WHERE id_country='CL' AND url=%s LIMIT 1", (row["url"],))
                    exists = cur.fetchone() is not None
                    cur.execute(
                        SQL,
                        (row["url"], row["slug"], row["title"], row["image"], row["image"], row["details"],
                         row["price"], row["year"], row["km"]),
                    )
                    stats["updated" if exists else "inserted"] += 1
                except Exception as e:
                    stats["failed"] += 1
                    print(f"row_error page={page} err={e}")

            if db is not None:
                db.commit()

            if last_page is not None and page >= last_page:
                break
            if delay:
                time.sleep(delay)

    if cur is not None:
        cur.close()
    if db is not None:
        db.close()
    print({**stats, "unique_seen": len(seen), "dry_run": dry_run})


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--max-pages", type=int, default=80)
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--delay", type=float, default=1.0)
    args = ap.parse_args()
    main(max_pages=args.max_pages, dry_run=args.dry_run, delay=args.delay)
