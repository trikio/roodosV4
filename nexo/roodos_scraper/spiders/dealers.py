"""Spider genérico para automotoras con sitio propio (tabla nexo.dealer_sites).

Un solo spider sirve para muchos sitios chicos: encuentra las fichas de los autos por los sitemaps
(los de robots.txt y las rutas habituales) y por los enlaces de la portada que parecen de autos, y
solo arma un aviso cuando la ficha publica datos estructurados de schema.org (Car, Vehicle o un
Product con datos de auto). Sin esos datos no se adivina nada.

  scrapy crawl dealers -a country=cl          # los sitios activos de Chile, desde nexo.dealer_sites
  scrapy crawl dealers -a country=cl -a hosts=difor.cl,ncautos.cl   # sitios puntuales (pruebas)
"""

import hashlib
import json
import os
import re
from datetime import date
from pathlib import Path
from urllib.parse import urlparse

import mysql.connector
import scrapy
from dotenv import load_dotenv
from scrapy.utils.gz import gunzip
from scrapy.utils.sitemap import Sitemap, sitemap_urls_from_robots

from roodos_scraper.items import ListingItem

load_dotenv(Path(__file__).resolve().parents[2] / ".env")

DEFAULT_SITEMAPS = ("/sitemap.xml", "/sitemap_index.xml", "/wp-sitemap.xml")
# Rutas que suelen tener autos: /autos/, /vehiculos/, /usados/, /listing/, /inventario/…
VEHICLE_PATH = re.compile(
    r"/(autos?|vehiculos?|veh%C3%ADculos?|usados?|seminuevos?|listings?|inventario|inventory|stock|"
    r"cars?|carros?|ficha|detalle|catalogo|unidades|camionetas?)(/|$)",
    re.I,
)
VEHICLE_SITEMAP = re.compile(r"(listing|vehic|auto|car|stock|inventor|product|seminuevo|usado|unidad)", re.I)
SKIP_SITEMAP = re.compile(r"(post|page|categor|tag|author|attachment|blog|news|noticia|elementor)", re.I)
VEHICLE_TYPES = {"Car", "Vehicle", "MotorVehicle", "BusOrCoach", "Motorcycle"}
# Monedas posibles por país: un aviso en otra moneda es de otro país (hay marketplaces internacionales).
CURRENCIES = {"cl": {"CLP", "CLF"}, "mx": {"MXN"}, "pe": {"PEN", "USD"}, "ec": {"USD"}}
CAR_WORDS = re.compile(r"\b(km|kilometraje|transmisi[oó]n|motor|año|modelo|sed[aá]n|suv|pick ?up|hatchback)\b", re.I)


class DealersSpider(scrapy.Spider):
    name = "dealers"
    custom_settings = {
        "ROBOTSTXT_OBEY": True,
        "DOWNLOAD_DELAY": 1.0,
        "CONCURRENT_REQUESTS": 8,
        "CONCURRENT_REQUESTS_PER_DOMAIN": 1,
        # Reparte el turno entre sitios: con la cola por defecto un sitio grande acapara el crawl.
        "SCHEDULER_PRIORITY_QUEUE": "scrapy.pqueues.DownloaderAwarePriorityQueue",
        "DOWNLOAD_TIMEOUT": 30,
        "RETRY_TIMES": 1,
        # Sitios chicos: van con la IP propia, sin rotar proxies.
        "DOWNLOADER_MIDDLEWARES": {
            "roodos_scraper.middlewares.WebshareProxyRotationMiddleware": None,
            "scrapy.downloadermiddlewares.httpproxy.HttpProxyMiddleware": 750,
        },
    }

    max_pages_per_site = 2000
    max_sitemaps_per_site = 40
    max_depth = 3

    def __init__(self, country=None, hosts=None, *args, **kwargs):
        super().__init__(*args, **kwargs)
        if not country:
            raise ValueError("falta -a country=cl|mx|pe|ec")
        self.country = country.lower()
        if hosts:
            self.sites = {h.strip().lower().removeprefix("www."): None for h in hosts.split(",") if h.strip()}
        else:
            self.sites = self._active_sites(self.country)
        self.pages = {host: 0 for host in self.sites}
        self.sitemaps = {host: 0 for host in self.sites}
        self.allowed_domains = sorted({d for host in self.sites for d in (host, f"www.{host}")})

    def _active_sites(self, country):
        conn = mysql.connector.connect(
            host=os.getenv("MYSQL_HOST", "localhost"), port=int(os.getenv("MYSQL_PORT", "3306")),
            user=os.getenv("MYSQL_USER", "trikio"), password=os.getenv("MYSQL_PASSWORD", ""),
        )
        cur = conn.cursor()
        cur.execute("SELECT host, name FROM nexo.dealer_sites WHERE country=%s AND status='active'", (country,))
        sites = {host: name for host, name in cur.fetchall()}
        conn.close()
        return sites

    def start_requests(self):
        for host in self.sites:
            meta = {"site": host, "depth": 0}
            yield scrapy.Request(f"https://{host}/robots.txt", callback=self.parse_robots, errback=self._robots_failed,
                                 meta=meta, dont_filter=True)
            yield scrapy.Request(f"https://{host}/", callback=self.parse_page, meta=meta)

    # ---------- sitemaps ----------
    def parse_robots(self, response):
        host = response.meta["site"]
        urls = list(sitemap_urls_from_robots(response.text, base_url=response.url))
        for url in urls or [f"https://{host}{path}" for path in DEFAULT_SITEMAPS]:
            yield from self._sitemap_request(host, url)

    def _robots_failed(self, failure):
        host = failure.request.meta["site"]
        for path in DEFAULT_SITEMAPS:
            yield from self._sitemap_request(host, f"https://{host}{path}")

    def _sitemap_request(self, host, url):
        if self.sitemaps[host] >= self.max_sitemaps_per_site:
            return
        self.sitemaps[host] += 1
        yield scrapy.Request(url, callback=self.parse_sitemap, meta={"site": host, "depth": self.max_depth})

    def parse_sitemap(self, response):
        host = response.meta["site"]
        body = response.body
        if body[:2] == b"\x1f\x8b":
            try:
                body = gunzip(body)
            except Exception:
                return
        if b"<urlset" not in body[:3000] and b"<sitemapindex" not in body[:3000]:
            return
        try:
            sitemap = Sitemap(body)
        except Exception:
            return
        if sitemap.type == "sitemapindex":
            for entry in sitemap:
                loc = entry.get("loc", "")
                if loc and not SKIP_SITEMAP.search(urlparse(loc).path):
                    yield from self._sitemap_request(host, loc)
            return
        vehicle_sitemap = bool(VEHICLE_SITEMAP.search(urlparse(response.url).path))
        for entry in sitemap:
            loc = entry.get("loc", "")
            if self._same_site(loc, host) and self._looks_like_vehicle(loc, vehicle_sitemap):
                request = self._page_request(host, loc, self.max_depth)
                if request:
                    yield request

    # ---------- páginas ----------
    def parse_page(self, response):
        host = response.meta["site"]
        item = self._vehicle_item(response, host)
        if item:
            yield item
            return
        depth = response.meta.get("depth", 0)
        if depth >= self.max_depth or "html" not in response.headers.get("Content-Type", b"").decode().lower():
            return
        for href in response.css("a::attr(href)").getall():
            url = response.urljoin(href).split("#")[0]
            if not self._same_site(url, host) or not self._looks_like_vehicle(url, False):
                continue
            # La paginación de un listado no cuenta como un nivel más.
            next_depth = depth if re.search(r"[?&/](page|pagina|pag)[=/]?\d", url, re.I) else depth + 1
            request = self._page_request(host, url, next_depth)
            if request:
                yield request

    def _page_request(self, host, url, depth):
        if self.pages[host] >= self.max_pages_per_site:
            return None
        self.pages[host] += 1
        return scrapy.Request(url, callback=self.parse_page, meta={"site": host, "depth": depth})

    def _same_site(self, url, host):
        return (urlparse(url).hostname or "").lower().removeprefix("www.") == host

    def _looks_like_vehicle(self, url, vehicle_sitemap):
        path = urlparse(url).path
        if re.search(r"\.(jpe?g|png|webp|gif|pdf|xml|css|js)$", path, re.I):
            return False
        if VEHICLE_PATH.search(path):
            return True
        return vehicle_sitemap and len([s for s in path.split("/") if s]) >= 1

    # ---------- extracción ----------
    def _vehicle_item(self, response, host):
        if "html" not in response.headers.get("Content-Type", b"").decode().lower():
            return None
        # Una ficha tiene un id o un año en la URL; sin eso suele ser un listado, y su primer auto no es "el" aviso.
        if not re.search(r"\d{3,}", urlparse(response.url).path):
            return None
        data = self._vehicle_ld(response) or self._vehicle_microdata(response)
        if not data:
            return None

        offers = data.get("offers") or {}
        if isinstance(offers, list):
            offers = offers[0] if offers else {}
        price = self._price(offers.get("price") if isinstance(offers, dict) else None)
        if price is None and isinstance(offers, dict):
            price = self._price(offers.get("lowPrice"))
        currency = (offers.get("priceCurrency") if isinstance(offers, dict) else "") or ""

        title = self._text(data.get("name")) or response.css("meta[property='og:title']::attr(content)").get() or ""
        title = re.sub(r"\s+", " ", title).strip()
        year = self._year(data.get("vehicleModelDate") or data.get("modelDate") or data.get("productionDate")
                          or data.get("releaseDate")) or self._year(title)
        km = self._mileage(data.get("mileageFromOdometer"))

        # Algunas plataformas (p. ej. RT Automotriz) dejan precio y km solo en la descripción para
        # redes sociales: "... DIESEL 78.100 Kms. $16.990.000".
        summary = response.css("meta[property='og:description']::attr(content)").get() or ""
        if price is None:
            match = re.search(r"\$\s?(\d{1,3}(?:[.,]\d{3})+)", summary)
            price = self._to_int(match.group(1)) if match else None
        if km is None:
            match = re.search(r"(\d{1,3}(?:[.,]\d{3})+|\d+)\s*kms?\b", summary, re.I)
            km = self._to_int(match.group(1)) if match else None

        if not title or not price or not year:
            return None
        if currency and currency.upper() not in CURRENCIES.get(self.country, set()):
            return None

        image = self._image(data.get("image")) or response.css("meta[property='og:image']::attr(content)").get()
        url = response.url.split("#")[0]
        site_name = self.sites.get(host) or (response.css("meta[property='og:site_name']::attr(content)").get() or "").strip()
        if not site_name or "://" in site_name or "." in site_name.split()[0]:
            site_name = host

        details = [self._text(data.get(key)) for key in ("bodyType", "fuelType", "vehicleTransmission")]
        if currency:
            details.append(currency.upper())

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = f"dealer:{host}"
        item["source_label"] = site_name[:50]
        item["country"] = self.country.upper()
        item["url"] = url[:250]
        item["slug"] = re.sub(r"[^a-z0-9]+", "-", title.lower()).strip("-")[:200]
        item["title"] = title[:250]
        item["image"] = response.urljoin(image) if image else None
        item["image_big"] = item["image"]
        item["details"] = " | ".join(d for d in details if d) or None
        item["price"] = price
        item["year"] = year
        item["km"] = km
        item["external_id"] = hashlib.sha1(url.encode()).hexdigest()[:16]
        item["brand_name"] = self._text(data.get("brand")) or self._text(data.get("manufacturer"))
        item["model_name"] = self._text(data.get("model"))
        for key in ("id_state", "id_city", "id_car_make", "id_car_model", "id_fuel", "id_body", "id_transmission"):
            item[key] = None
        return item

    def _vehicle_ld(self, response):
        """El primer objeto JSON-LD que describe un auto (Car/Vehicle, o Product con datos de auto)."""
        for raw in response.css("script[type='application/ld+json']::text").getall():
            try:
                parsed = json.loads(raw.strip())
            except Exception:
                continue
            stack = parsed if isinstance(parsed, list) else [parsed]
            while stack:
                obj = stack.pop(0)
                if not isinstance(obj, dict):
                    continue
                stack.extend(obj.get("@graph") or [])
                types = obj.get("@type")
                types = set(types) if isinstance(types, list) else {types}
                if types & VEHICLE_TYPES:
                    return obj
                if "Product" in types and (
                    obj.get("mileageFromOdometer") or obj.get("vehicleModelDate")
                    or CAR_WORDS.search(f"{self._text(obj.get('name'))} {self._text(obj.get('description'))}")
                ):
                    return obj
        return None

    def _vehicle_microdata(self, response):
        """El primer bloque de microdatos (itemscope) de tipo Product, Car o Vehicle, con sus propiedades
        directas: los bloques siguientes suelen ser autos relacionados."""
        scope = response.xpath(
            "//*[@itemscope][contains(@itemtype, 'schema.org/Product') or contains(@itemtype, 'schema.org/Car')"
            " or contains(@itemtype, 'schema.org/Vehicle')]"
        )
        if not scope:
            return None
        scope = scope[0]
        data = {}
        for el in scope.xpath(".//*[@itemprop]"):
            owner = el.xpath("ancestor::*[@itemscope][1]")
            if not owner or owner[0].root is not scope.root:
                continue
            key = el.attrib.get("itemprop")
            value = el.attrib.get("content") or el.attrib.get("src") or " ".join(el.xpath(".//text()").getall()).strip()
            if key and value and key not in data:
                data[key] = value
        if not data.get("name"):
            return None
        if data.get("price"):
            data["offers"] = {"price": data["price"], "priceCurrency": data.get("priceCurrency", "")}
        return data

    def _price(self, value):
        """En datos estructurados el precio es un número con punto decimal ("10450.000" son 10.450);
        si trae separadores de miles ("12.990.000") se leen los dígitos. Menos de 500 no es un auto."""
        text = str(value).strip() if value not in (None, "") else ""
        if isinstance(value, (int, float)) or re.fullmatch(r"\d+(\.\d+)?", text):
            price = int(float(value if isinstance(value, (int, float)) else text))
        else:
            price = self._to_int(text)
        return price if price and price >= 500 else None

    def _text(self, value):
        if isinstance(value, dict):
            value = value.get("name") or value.get("@value")
        if isinstance(value, list):
            value = value[0] if value else None
        return str(value).strip() if value not in (None, "") else None

    def _image(self, value):
        if isinstance(value, list):
            value = value[0] if value else None
        if isinstance(value, dict):
            value = value.get("url") or value.get("contentUrl")
        return value or None

    def _year(self, value):
        match = re.search(r"\b(19[89]\d|20\d{2})\b", str(value or ""))
        year = int(match.group(1)) if match else None
        return year if year and year <= date.today().year + 1 else None

    def _mileage(self, value):
        unit = ""
        if isinstance(value, dict):
            unit = str(value.get("unitCode") or value.get("unitText") or "").upper()
            value = value.get("value")
        km = self._to_int(value)
        if km is not None and unit in ("SMI", "MI", "MILES"):
            km = round(km * 1.609344)
        return km

    def _to_int(self, value):
        if value in (None, ""):
            return None
        if isinstance(value, (int, float)):
            return int(value)
        text = str(value).strip()
        # "12.990.000", "12,990,000.00", "12990000.0"
        text = re.sub(r"[.,]\d{1,2}$", "", text)
        digits = "".join(ch for ch in text if ch.isdigit())
        return int(digits) if digits and len(digits) < 12 else None
