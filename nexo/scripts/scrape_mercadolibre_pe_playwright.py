#!/usr/bin/env python3
import argparse
import json
import os
import random
import re
import unicodedata
from dataclasses import dataclass
from typing import Dict, List, Optional, Tuple

import mysql.connector
import requests
from dotenv import load_dotenv
from parsel import Selector
from playwright.sync_api import sync_playwright


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


@dataclass
class ProxyCfg:
    server: str
    username: Optional[str] = None
    password: Optional[str] = None


def get_webshare_proxies(token: str, page_size: int = 100) -> List[ProxyCfg]:
    r = requests.get(
        "https://proxy.webshare.io/api/proxy/list/",
        headers={"Authorization": f"Token {token}", "Accept": "application/json"},
        params={"page": 1, "page_size": page_size},
        timeout=20,
    )
    r.raise_for_status()
    out: List[ProxyCfg] = []
    for p in (r.json().get("results") or []):
        host = p.get("proxy_address") or p.get("address") or p.get("ip")
        port = (p.get("ports") or {}).get("http") or p.get("port")
        if not host or not port:
            continue
        out.append(
            ProxyCfg(
                server=f"http://{host}:{port}",
                username=p.get("username"),
                password=p.get("password"),
            )
        )
    random.shuffle(out)
    return out


class Resolver:
    def __init__(self, cur):
        self.cur = cur
        self.make_by_norm: Dict[str, int] = {}
        self.make_names_norm: List[str] = []
        self.model_by_make_and_norm: Dict[Tuple[int, str], int] = {}
        self.model_names_by_make: Dict[int, List[Tuple[str, int]]] = {}

        self.state_by_name: Dict[str, Tuple[int, Optional[str]]] = {}
        self.city_by_adm_name: Dict[Tuple[str, str], int] = {}
        self.city_name_global: Dict[str, set] = {}
        self._load_catalogs()

    def _load_catalogs(self):
        self.cur.execute("SELECT id_car_make, name FROM car_make")
        for row in self.cur.fetchall():
            n = norm(row["name"] or "")
            if n and n not in self.make_by_norm:
                self.make_by_norm[n] = row["id_car_make"]
        self.make_names_norm = sorted(self.make_by_norm.keys(), key=len, reverse=True)

        self.cur.execute("SELECT id_car_model, id_car_make, name FROM car_model")
        for row in self.cur.fetchall():
            n = norm(row["name"] or "")
            if not n:
                continue
            mk = row["id_car_make"]
            self.model_by_make_and_norm[(mk, n)] = row["id_car_model"]
            self.model_names_by_make.setdefault(mk, []).append((n, row["id_car_model"]))
        for mk in list(self.model_names_by_make.keys()):
            self.model_names_by_make[mk].sort(key=lambda x: len(x[0]), reverse=True)

        self.cur.execute("SELECT id, ADM_CODE, SHORTFORM, FULLNAME FROM states WHERE CC1='PE'")
        for s in self.cur.fetchall():
            for v in [s.get("SHORTFORM"), s.get("FULLNAME")]:
                n = norm(v or "")
                if n:
                    self.state_by_name[n] = (s["id"], s.get("ADM_CODE"))

        self.cur.execute("SELECT id, ADM1, SHORTFORM, FULLNAME FROM cities WHERE CC1='PE'")
        for c in self.cur.fetchall():
            cid = c["id"]
            adm = c.get("ADM1")
            for v in [c.get("SHORTFORM"), c.get("FULLNAME")]:
                n = norm(v or "")
                if not n:
                    continue
                if adm:
                    self.city_by_adm_name[(adm, n)] = cid
                self.city_name_global.setdefault(n, set()).add(cid)

    def resolve_make_model(self, title: str) -> Tuple[Optional[int], Optional[int]]:
        ntitle = norm(title)
        make_id = None
        for mname in self.make_names_norm:
            if re.search(rf"(^|\s){re.escape(mname)}(\s|$)", ntitle):
                make_id = self.make_by_norm.get(mname)
                break
        model_id = None
        if make_id:
            for mn, mid in self.model_names_by_make.get(make_id, []):
                if re.search(rf"(^|\s){re.escape(mn)}(\s|$)", ntitle):
                    model_id = mid
                    break
        return make_id, model_id

    def resolve_city_state(self, city_hint: str) -> Tuple[Optional[int], Optional[int]]:
        n = norm(city_hint)
        if not n:
            return None, None
        # direct state match fallback
        if n in self.state_by_name:
            sid, _ = self.state_by_name[n]
            return None, sid
        ids = self.city_name_global.get(n)
        if ids and len(ids) == 1:
            cid = next(iter(ids))
            return cid, None
        return None, None


def parse_cards(html: str) -> List[dict]:
    sel = Selector(text=html)
    rows = []
    for card in sel.css("li.ui-search-layout__item"):
        a = card.css("a.poly-component__title::attr(href)").get()
        title = (card.css("a.poly-component__title::text").get() or "").strip()
        if not a or not title:
            continue
        url = a.split("#")[0]
        if not url.startswith("https://auto.mercadolibre.com.pe/"):
            continue
        image = card.css("img.poly-component__picture::attr(src)").get()
        price_text = "".join(card.css(".poly-price__current *::text").getall())
        attrs = [x.strip() for x in card.css(".poly-attributes_list__item::text").getall() if x.strip()]
        details = " | ".join(attrs) if attrs else None

        km = None
        year = to_int(attrs[0]) if attrs else None
        for atext in attrs:
            if "km" in atext.lower():
                km = to_int(atext)
                break

        city = card.css(".poly-component__location::text, .poly-component__location span::text").get()
        m = re.search(r"/(MPE-\d+)", url)
        ext = m.group(1) if m else url.rstrip("/").split("/")[-1][:50]

        rows.append(
            {
                "url": url[:250],
                "slug": url.rstrip("/").split("/")[-1][:255],
                "title": title[:250],
                "image": (image or "")[:255],
                "image_big": (image or "")[:255],
                "details": (details or "")[:2000] or None,
                "price": to_int(price_text),
                "year": year,
                "km": km,
                "city_hint": (city or "").strip(),
                "external_id": ext,
            }
        )
    return rows


def build_page_urls(base_url: str, pages: int) -> List[str]:
    urls = [base_url]
    offset = 49
    for _ in range(2, pages + 1):
        urls.append(f"{base_url}_Desde_{offset}")
        offset += 48
    return urls


def main(max_pages: int = 5):
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

    token = os.getenv("WEBSHARE_API_TOKEN", "")
    if not token:
        raise SystemExit("WEBSHARE_API_TOKEN missing")
    proxies = get_webshare_proxies(token, page_size=int(os.getenv("WEBSHARE_PAGE_SIZE", "100")))
    if not proxies:
        raise SystemExit("No proxies available")

    user_agents = [
        "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36",
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36",
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36",
    ]

    sql = """
    INSERT INTO cars (
      id_country, url, slug, title, image, image_big,
      id_car_make, id_car_model, id_state, id_city, details,
      price, year, km, id_fuel, id_body, id_transmission,
      status, created, updated, nexo_id, nexo_flag
    ) VALUES (
      'PE', %s, %s, %s, %s, %s,
      %s, %s, %s, %s, %s,
      %s, %s, %s, NULL, NULL, NULL,
      1, NOW(), NOW(), 'Mercadolibre', 0
    )
    ON DUPLICATE KEY UPDATE
      slug=VALUES(slug), title=VALUES(title), image=VALUES(image), image_big=VALUES(image_big),
      id_car_make=VALUES(id_car_make), id_car_model=VALUES(id_car_model),
      id_state=VALUES(id_state), id_city=VALUES(id_city), details=VALUES(details),
      price=VALUES(price), year=VALUES(year), km=VALUES(km), updated=VALUES(updated),
      nexo_id=VALUES(nexo_id), status=1
    """

    inserted = 0
    updated = 0
    errors = 0

    page_urls = build_page_urls("https://listado.mercadolibre.com.pe/vehiculos/", max_pages)

    with sync_playwright() as p:
        for i, page_url in enumerate(page_urls, 1):
            proxy = proxies[(i - 1) % len(proxies)]
            ua = random.choice(user_agents)
            browser = None
            try:
                browser = p.chromium.launch(headless=True, proxy={
                    "server": proxy.server,
                    "username": proxy.username or "",
                    "password": proxy.password or "",
                })
                ctx = browser.new_context(user_agent=ua, locale="es-PE")
                page = ctx.new_page()
                page.goto(page_url, wait_until="domcontentloaded", timeout=60000)
                page.wait_for_timeout(2500)
                html = page.content()
                cards = parse_cards(html)
                print(f"page={i} proxy={proxy.server} cards={len(cards)}")

                for row in cards:
                    try:
                        cur.execute("SELECT id FROM cars WHERE id_country='PE' AND url=%s LIMIT 1", (row["url"],))
                        exists = cur.fetchone() is not None

                        mk, md = resolver.resolve_make_model(row["title"])
                        cid, sid = resolver.resolve_city_state(row.get("city_hint") or "")

                        cur.execute(
                            sql,
                            (
                                row["url"], row["slug"], row["title"], row["image"], row["image_big"],
                                mk, md, sid, cid, row["details"],
                                row["price"], row["year"], row["km"],
                            ),
                        )
                        if exists:
                            updated += 1
                        else:
                            inserted += 1
                    except Exception as e:
                        errors += 1
                        print("row_error", row.get("url"), e)
                db.commit()
                ctx.close()
            except Exception as e:
                errors += 1
                print(f"page_error page={i} proxy={proxy.server} err={e}")
                db.rollback()
            finally:
                if browser:
                    browser.close()

    print(json.dumps({"inserted": inserted, "updated": updated, "errors": errors}, ensure_ascii=False))
    cur.close()
    db.close()


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--max-pages", type=int, default=5)
    args = ap.parse_args()
    main(max_pages=args.max_pages)
