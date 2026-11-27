#!/usr/bin/env python3
"""Casas de Chile desde el feed Trovit de Doomos (https://www.doomos.cl/feeds/trovit.xml, ~34.000 avisos).

Cada aviso trae operación (`type`: "For Sale", "Land For Rent"...), tipo (`property_type`), región y
comuna, y se guardan con esos ids. La ubicación la resuelve el mismo normalizador que los scrapers.

Precio: `price` viene en pesos. Cuando falta (la mitad del feed), Doomos pone el precio en UF al final
del título ("Venta Departamento Santiago ... UF 2850"); se convierte a pesos con la UF del día que
publica el SII, porque la web muestra todos los precios de Chile en pesos. Si no hay valor de UF, el
aviso queda sin precio, nunca con los UF como si fueran pesos.
"""
import json
import os
import random
import re
import sys
import unicodedata
from datetime import date
from pathlib import Path
from typing import Optional, List, Dict

import mysql.connector
import requests
from bs4 import BeautifulSoup
from dotenv import load_dotenv

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from roodos_scraper.normalize import Normalizer  # noqa: E402

FEED_URL = "https://www.doomos.cl/feeds/trovit.xml"
UF_URL = "https://www.sii.cl/valores_y_fechas/uf/uf{year}.htm"
UF_CACHE = Path(__file__).resolve().parent.parent / "logs" / "uf_value.json"
MONTHS = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre",
          "octubre", "noviembre", "diciembre"]

# house_type de roodos por `property_type` del feed.
TYPES = {"departamento": 2, "casa": 1, "parcela": 5, "terreno": 3, "oficina": 6, "local comercial": 4,
         "bodega": 9, "casa comercial": 4, "galpon": 7, "negocio": 4, "loft": 15, "quinta": 19, "almacen": 9}
# Si `property_type` falta o es otro, el tipo que dice `type` ("Office For Rent").
TYPE_HINTS = [("land", 3), ("office", 6), ("local", 4), ("warehouse", 9)]


def norm(text) -> str:
    t = unicodedata.normalize("NFKD", str(text or "").strip().lower())
    t = "".join(ch for ch in t if not unicodedata.combining(ch))
    return " ".join(re.sub(r"[^a-z0-9]+", " ", t).split())


def operation_id(raw: str) -> Optional[int]:
    n = norm(raw)
    if "sale" in n or "venta" in n:
        return 1
    if "rent" in n or "arriendo" in n or "alquiler" in n:
        return 2
    return None


def type_id(property_type: str, raw_type: str) -> Optional[int]:
    found = TYPES.get(norm(property_type))
    if found:
        return found
    n = norm(raw_type)
    return next((tid for word, tid in TYPE_HINTS if word in n), None)


def number(text) -> Optional[float]:
    """"10370.00" -> 10370.0; "1.650" (miles) -> 1650; "2,5" -> 2.5."""
    m = re.search(r"\d[\d.,]*", str(text or ""))
    if not m:
        return None
    s = m.group(0).rstrip(".,")
    if re.fullmatch(r"\d{1,3}(\.\d{3})+(,\d+)?", s):  # 1.650 / 1.650,5
        s = s.replace(".", "").replace(",", ".")
    elif re.fullmatch(r"\d{1,3}(,\d{3})+(\.\d+)?", s):  # 1,650 / 1,650.5
        s = s.replace(",", "")
    else:
        s = s.replace(",", ".")
    try:
        return float(s)
    except ValueError:
        return None


def small_int(text, top=50) -> Optional[int]:
    v = number(text)
    return int(v) if v is not None and 0 < v < top else None


def uf_value() -> Optional[float]:
    """UF de hoy desde la tabla anual del SII; si el SII no responde, la última guardada (máx. 7 días)."""
    today = date.today()
    try:
        r = requests.get(UF_URL.format(year=today.year), timeout=30,
                         headers={"User-Agent": "Mozilla/5.0 (X11; Linux x86_64) Chrome/131 Safari/537.36"})
        r.raise_for_status()
        html = r.content.decode("latin-1")
        block = html.split(f"id='mes_{MONTHS[today.month - 1]}'", 1)[1].split("class='meses'", 1)[0]
        days = dict(re.findall(r"<strong>(\d+)</strong></th>\s*<td[^>]*>([\d.,]+)</td>", block))
        value = number(days.get(str(today.day)))
        if value and 20000 < value < 100000:
            UF_CACHE.parent.mkdir(exist_ok=True)
            UF_CACHE.write_text(json.dumps({"date": today.isoformat(), "uf": value}))
            return value
        print(f"uf: el SII no trae el valor del {today.isoformat()}")
    except Exception as exc:
        print(f"uf: SII falló ({exc.__class__.__name__}: {exc})")
    try:
        cached = json.loads(UF_CACHE.read_text())
        if (today - date.fromisoformat(cached["date"])).days <= 7:
            print(f"uf: uso el valor guardado del {cached['date']}")
            return float(cached["uf"])
    except Exception:
        pass
    return None


def price_clp(feed_price: str, title: str, uf: Optional[float]) -> Optional[int]:
    value = number(feed_price)
    if value and value >= 1000:
        return int(value)
    m = re.search(r"\bUF\s*([\d.,]+)\s*$", title or "", re.I)
    if m and uf:
        amount = number(m.group(1))
        if amount and amount > 0:
            clp = int(round(amount * uf))
            return clp if clp <= 2147483647 else None
    return None


def webshare_proxies(token: str, page_size: int = 100) -> List[Dict]:
    r = requests.get(
        "https://proxy.webshare.io/api/proxy/list/",
        headers={"Authorization": f"Token {token}", "Accept": "application/json"},
        params={"page": 1, "page_size": page_size},
        timeout=20,
    )
    r.raise_for_status()
    out = []
    for p in (r.json().get("results") or []):
        host = p.get("proxy_address") or p.get("address") or p.get("ip")
        port = (p.get("ports") or {}).get("http") or p.get("port")
        if not host or not port:
            continue
        out.append({
            "host": host,
            "proxy": f"http://{p.get('username')}:{p.get('password')}@{host}:{port}",
        })
    random.shuffle(out)
    return out


def download_feed(url: str, token: str) -> str:
    headers = {
        "User-Agent": random.choice([
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36",
            "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36",
        ]),
        "Accept": "application/xml,text/xml;q=0.9,*/*;q=0.8",
    }

    # Try direct first
    try:
        r = requests.get(url, headers=headers, timeout=40)
        if r.status_code == 200 and ("<?xml" in r.text[:200] or "<trovit" in r.text[:500].lower()):
            return r.text
    except Exception:
        pass

    proxies = webshare_proxies(token, int(os.getenv("WEBSHARE_PAGE_SIZE", "100"))) if token else []
    for px in proxies:
        try:
            r = requests.get(url, headers=headers, timeout=40, proxies={"http": px["proxy"], "https": px["proxy"]})
            if r.status_code == 200 and ("<?xml" in r.text[:200] or "<trovit" in r.text[:500].lower()):
                return r.text
        except Exception:
            continue

    raise RuntimeError("Could not download XML feed (blocked or unavailable)")


def fix_text(s: str) -> str:
    if not s:
        return ""
    s = str(s).strip()
    # Recover common mojibake like "ViÃ±a" -> "Viña"
    try:
        repaired = s.encode("latin1").decode("utf-8")
        if repaired.count("�") <= s.count("�"):
            s = repaired
    except Exception:
        pass
    return s


def tag_text(ad, tag_name: str) -> str:
    t = ad.find(tag_name)
    return fix_text(t.text) if t and t.text else ""


def first_picture(ad) -> Optional[str]:
    pics = ad.find("pictures")
    if not pics:
        return None
    txt = (pics.text or "").strip()
    if not txt:
        return None
    parts = re.split(r"[\s,;]+", txt)
    for p in parts:
        if p.startswith("http"):
            return p
    return txt if txt.startswith("http") else None


def main(max_items: Optional[int] = None, dry_run: bool = False):
    load_dotenv()

    xml = download_feed(FEED_URL, os.getenv("WEBSHARE_API_TOKEN", ""))
    soup = BeautifulSoup(xml, "xml")
    ads = soup.find_all("ad")
    uf = uf_value()
    print(f"feed: {len(ads)} avisos; UF {uf}")

    db = mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "localhost"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        user=os.getenv("MYSQL_USER", "trikio"),
        password=os.getenv("MYSQL_PASSWORD", ""),
        database=os.getenv("MYSQL_DATABASE", "roodos"),
        autocommit=False,
    )
    cur = db.cursor(dictionary=True)
    normalizer = Normalizer(cur)

    sql = """
    INSERT INTO houses (
      id_country, url, slug, title, image, image_big,
      id_house_operation, id_house_type, id_state, id_city,
      details, price, rooms, bath, size,
      status, created, updated, nexo_id, nexo_flag
    ) VALUES (
      'CL', %s, %s, %s, %s, %s,
      %s, %s, %s, %s,
      %s, %s, %s, %s, %s,
      1, NOW(), NOW(), 'Doomos', 1
    )
    ON DUPLICATE KEY UPDATE
      slug=VALUES(slug), title=VALUES(title), image=VALUES(image), image_big=VALUES(image_big),
      id_house_operation=VALUES(id_house_operation), id_house_type=VALUES(id_house_type),
      id_state=VALUES(id_state), id_city=VALUES(id_city), details=VALUES(details),
      price=VALUES(price), rooms=VALUES(rooms), bath=VALUES(bath), size=VALUES(size),
      updated=VALUES(updated), nexo_id=VALUES(nexo_id), status=1
    """

    inserted = updated = failed = 0
    stats = {"operation": 0, "type": 0, "state": 0, "city": 0, "price": 0, "price_uf": 0, "size": 0}

    for i, ad in enumerate(ads, 1):
        if max_items and i > max_items:
            break
        try:
            url = tag_text(ad, "url")
            # Skip malformed/fragment-only URLs like '#modelos-...'
            if not url or not url.startswith("http"):
                continue
            slug = url.rstrip("/").split("/")[-1][:255]
            title = (tag_text(ad, "title") or slug or "Sin título").strip()[:250]
            details = (tag_text(ad, "content") or "").strip()[:60000] or None
            raw_type = tag_text(ad, "type")
            city = tag_text(ad, "city")
            region = tag_text(ad, "region")
            image = first_picture(ad)

            id_op = operation_id(raw_type)
            id_type = type_id(tag_text(ad, "property_type"), raw_type)
            id_state, id_city = normalizer.location("cl", [city] + (["region:" + region] if region else []))
            feed_price = tag_text(ad, "price")
            price = price_clp(feed_price, title, uf)
            rooms = small_int(tag_text(ad, "rooms"))
            bath = small_int(tag_text(ad, "bathrooms"))
            area = number(tag_text(ad, "floor_area"))
            size = int(area) if area and 1 <= area <= 2147483647 else None

            for key, value in (("operation", id_op), ("type", id_type), ("state", id_state), ("city", id_city),
                               ("price", price), ("size", size)):
                stats[key] += value is not None
            stats["price_uf"] += bool(price and not number(feed_price))
            if dry_run:
                continue

            cur.execute("SELECT id FROM houses WHERE id_country='CL' AND url=%s LIMIT 1", (url[:250],))
            exists = cur.fetchone() is not None

            cur.execute(
                sql,
                (
                    url[:250], slug, title, image or "", image,
                    id_op, id_type, id_state, id_city,
                    details, price, rooms, bath, size,
                ),
            )
            if exists:
                updated += 1
            else:
                inserted += 1

            if i % 1000 == 0:
                db.commit()
                print(f"progress {i}/{len(ads)} inserted={inserted} updated={updated} failed={failed}")
        except Exception as e:
            failed += 1
            if failed < 20:
                print("row_error", i, e)

    db.commit()
    print({"total_ads": len(ads), "inserted": inserted, "updated": updated, "failed": failed, **stats})

    cur.close(); db.close()


if __name__ == "__main__":
    import argparse
    p = argparse.ArgumentParser()
    p.add_argument("--max-items", type=int, default=None)
    p.add_argument("--dry-run", action="store_true", help="lee el feed y cuenta, sin escribir en la base")
    args = p.parse_args()
    main(max_items=args.max_items, dry_run=args.dry_run)
