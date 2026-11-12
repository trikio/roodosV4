#!/usr/bin/env python3
import argparse
import hashlib
import os
import random
import re
import unicodedata
import urllib.parse
from typing import Dict, List, Optional, Tuple

import mysql.connector
import requests
from dotenv import load_dotenv
from parsel import Selector


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
    return int(digits) if digits else None


def webshare_list(token: str, page_size: int) -> List[Dict]:
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
        out.append(
            {
                "proxy": f"http://{p.get('username')}:{p.get('password')}@{host}:{port}",
                "host": host,
                "port": port,
            }
        )
    random.shuffle(out)
    return out


class Resolver:
    def __init__(self, cur):
        self.cur = cur
        self.make_by_norm = {}
        self.make_names = []
        self.models_by_make = {}
        self.city_by_name = {}
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

        self.cur.execute("SELECT id, SHORTFORM, FULLNAME FROM cities WHERE CC1='PE'")
        for r in self.cur.fetchall():
            for v in [r.get("SHORTFORM"), r.get("FULLNAME")]:
                n = norm(v or "")
                if n and n not in self.city_by_name:
                    self.city_by_name[n] = r["id"]

    def resolve_make_model(self, title: str) -> Tuple[Optional[int], Optional[int]]:
        t = norm(title)
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

    def resolve_city(self, city_hint: str) -> Optional[int]:
        return self.city_by_name.get(norm(city_hint or ""))


def solve_ml_challenge(session: requests.Session, url: str, headers: dict) -> None:
    r = session.get(url, headers=headers, timeout=30)
    if "_bmstate" not in session.cookies:
        return
    bm = urllib.parse.unquote(session.cookies.get("_bmstate"))
    parts = bm.split(";")
    token = parts[0] if parts else ""
    zeros = int(parts[1]) if len(parts) > 1 and parts[1].isdigit() else 0
    target = "0" * zeros
    answer = 0
    if zeros > 0:
        for i in range(2_000_000):
            if hashlib.sha256((token + str(i)).encode()).hexdigest().startswith(target):
                answer = i
                break
    bmc = urllib.parse.quote(f"{token};{answer}")
    session.cookies.set("_bmc", bmc, domain=".mercadolibre.com.pe", path="/")


def parse_cards(html: str) -> List[dict]:
    sel = Selector(text=html)
    cards = []
    for card in sel.css("li.ui-search-layout__item"):
        a = card.css("a.poly-component__title::attr(href)").get()
        title = (card.css("a.poly-component__title::text").get() or "").strip()
        if not a or not title:
            continue
        url = a.split("#")[0]
        if not url.startswith("https://auto.mercadolibre.com.pe/"):
            continue
        image = card.css("img.poly-component__picture::attr(src)").get() or ""
        attrs = [x.strip() for x in card.css(".poly-attributes_list__item::text").getall() if x.strip()]
        city = (card.css(".poly-component__location::text, .poly-component__location span::text").get() or "").strip()
        price_txt = "".join(card.css(".poly-price__current *::text").getall())
        ext = re.search(r"/(MPE-\d+)", url)
        cards.append(
            {
                "url": url[:250],
                "slug": url.rstrip("/").split("/")[-1][:255],
                "title": title[:250],
                "image": image[:255],
                "image_big": image[:255],
                "details": (" | ".join(attrs)[:1900] if attrs else None),
                "price": to_int(price_txt),
                "year": to_int(attrs[0]) if attrs else None,
                "km": next((to_int(a) for a in attrs if "km" in a.lower()), None),
                "city_hint": city,
                "external_id": ext.group(1) if ext else url.rstrip("/").split("/")[-1][:50],
            }
        )
    return cards


def _fetch_listing_html(url: str, ua: str, proxy_url: Optional[str] = None) -> str:
    s = requests.Session()
    if proxy_url:
        s.proxies.update({"http": proxy_url, "https": proxy_url})
    headers = {"User-Agent": ua, "Accept-Language": "es-PE,es;q=0.9,en;q=0.8"}
    solve_ml_challenge(s, url, headers)
    r = s.get(url, headers=headers, timeout=40)
    return r.text


def main(max_pages: int = 3):
    load_dotenv()
    ua_pool = [
        "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36",
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36",
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36",
    ]

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

    token = os.getenv("WEBSHARE_API_TOKEN", "")
    proxies = webshare_list(token, int(os.getenv("WEBSHARE_PAGE_SIZE", "100")))
    if not proxies:
        raise SystemExit("No proxies")

    sql = """
    INSERT INTO cars (
      id_country, url, slug, title, image, image_big,
      id_car_make, id_car_model, id_state, id_city, details,
      price, year, km, id_fuel, id_body, id_transmission,
      status, created, updated, nexo_id, nexo_flag
    ) VALUES (
      'PE', %s, %s, %s, %s, %s,
      %s, %s, NULL, %s, %s,
      %s, %s, %s, NULL, NULL, NULL,
      1, NOW(), NOW(), 'Mercadolibre', 0
    )
    ON DUPLICATE KEY UPDATE
      slug=VALUES(slug), title=VALUES(title), image=VALUES(image), image_big=VALUES(image_big),
      id_car_make=VALUES(id_car_make), id_car_model=VALUES(id_car_model), id_city=VALUES(id_city),
      details=VALUES(details), price=VALUES(price), year=VALUES(year), km=VALUES(km), updated=VALUES(updated),
      nexo_id=VALUES(nexo_id), status=1
    """

    inserted = updated = failed = 0
    base = "https://listado.mercadolibre.com.pe/vehiculos/"

    for page_idx in range(1, max_pages + 1):
        start = 49 + (page_idx - 2) * 48
        url = base if page_idx == 1 else f"{base}_Desde_{start}"
        ua = random.choice(ua_pool)

        used = "init"
        try:
            html = ""
            used = "direct"

            # Try rotating proxies first
            for k in range(min(8, len(proxies))):
                px = proxies[(page_idx - 1 + k) % len(proxies)]
                html_try = _fetch_listing_html(url, ua, proxy_url=px["proxy"])
                cards_try = parse_cards(html_try)
                if cards_try:
                    html = html_try
                    used = f"proxy={px['host']}"
                    break

            if not html:
                print(f"page={page_idx} no_proxy_succeeded")
                continue

            cards = parse_cards(html)
            print(f"page={page_idx} {used} cards={len(cards)}")

            for row in cards:
                try:
                    cur.execute("SELECT id FROM cars WHERE id_country='PE' AND url=%s LIMIT 1", (row["url"],))
                    exists = cur.fetchone() is not None
                    mk, md = resolver.resolve_make_model(row["title"])
                    cid = resolver.resolve_city(row.get("city_hint") or "")
                    cur.execute(
                        sql,
                        (
                            row["url"], row["slug"], row["title"], row["image"], row["image_big"],
                            mk, md, cid, row["details"], row["price"], row["year"], row["km"],
                        ),
                    )
                    if exists:
                        updated += 1
                    else:
                        inserted += 1
                except Exception as e:
                    failed += 1
                    print("row_error", row.get("url"), e)
            db.commit()
        except Exception as e:
            failed += 1
            db.rollback()
            print(f"page_error page={page_idx} via={used} err={e}")

    print({"inserted": inserted, "updated": updated, "failed": failed})
    cur.close()
    db.close()


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--max-pages", type=int, default=3)
    args = ap.parse_args()
    main(max_pages=args.max_pages)
