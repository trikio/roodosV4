#!/usr/bin/env python3
import os
import random
import re
import unicodedata
from typing import Optional, Tuple, List, Dict

import mysql.connector
import requests
from bs4 import BeautifulSoup
from dotenv import load_dotenv

FEED_URL = "https://www.doomos.cl/feeds/trovit.xml"


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
    digits = "".join(ch for ch in str(value) if ch.isdigit())
    if not digits:
        return None
    v = int(digits)
    return v if v <= 2147483647 else None


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


def manticore_match_id(cur, index_name: str, text: str) -> Optional[int]:
    qn = norm(text)
    if not qn:
        return None
    # user-requested strategy: query only over @names with quorum /1
    phrase = " ".join([w for w in qn.split() if len(w) >= 3][:16])
    if not phrase:
        return None
    mq = f'@names "{phrase}"/1'
    try:
        cur.execute(f"SELECT id FROM {index_name} WHERE MATCH(%s) ORDER BY WEIGHT() DESC LIMIT 1", (mq,))
        row = cur.fetchone()
        return int(row["id"]) if row and row.get("id") is not None else None
    except Exception:
        return None


def manticore_location(cur, city: str, region: str) -> Tuple[Optional[int], Optional[int]]:
    queries = [norm(f"{city} {region}"), norm(city)]
    for q in queries:
        if not q:
            continue
        try:
            cur.execute(
                """
                SELECT id, id_state
                FROM location
                WHERE country='cl' AND MATCH(%s)
                ORDER BY puntaje DESC, WEIGHT() DESC
                LIMIT 1
                """,
                (q,),
            )
            row = cur.fetchone()
            if row:
                return (
                    int(row["id"]) if row.get("id") is not None else None,
                    int(row["id_state"]) if row.get("id_state") is not None else None,
                )
        except Exception:
            pass
    return None, None


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


def operation_phrase(raw: str) -> str:
    n = norm(raw)
    if "for sale" in n or "sale" in n or "venta" in n:
        return "venta"
    if "for rent" in n or "rent" in n or "arriendo" in n or "alquiler" in n:
        return "arriendo"
    return n


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


def main(max_items: Optional[int] = None):
    load_dotenv()

    xml = download_feed(FEED_URL, os.getenv("WEBSHARE_API_TOKEN", ""))
    soup = BeautifulSoup(xml, "xml")
    ads = soup.find_all("ad")

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
            op_txt = tag_text(ad, "type")
            typ_txt = tag_text(ad, "property_type")
            city = tag_text(ad, "city")
            region = tag_text(ad, "region")
            image = first_picture(ad)

            id_op = manticore_match_id(mcur, "house_operation", operation_phrase(op_txt))
            id_type = manticore_match_id(mcur, "house_type", typ_txt)
            id_city, id_state = manticore_location(mcur, city, region)

            price = to_int(tag_text(ad, "price")) or to_int(title)
            rooms = to_int(tag_text(ad, "rooms"))
            bath = to_int(tag_text(ad, "bathrooms"))
            size = to_int(tag_text(ad, "floor_area"))

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
    print({"total_ads": len(ads), "inserted": inserted, "updated": updated, "failed": failed})

    mcur.close(); mc.close(); cur.close(); db.close()


if __name__ == "__main__":
    import argparse
    p = argparse.ArgumentParser()
    p.add_argument("--max-items", type=int, default=None)
    args = p.parse_args()
    main(max_items=args.max_items)
