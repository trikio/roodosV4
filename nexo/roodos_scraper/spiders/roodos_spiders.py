import base64
import json
import re
from urllib.parse import parse_qs, urlencode, urlparse, urlunparse

import scrapy

from roodos_scraper.items import ListingItem
from roodos_scraper.spiders.base_roodos_spider import BaseRoodosSpider


class AutosRoodosClSpider(BaseRoodosSpider):
    name = "autos_roodos_cl"
    vertical = "car"
    country = "CL"
    base_domain = "https://autos.roodos.cl"


class PatiotuercaEcuadorSpider(scrapy.Spider):
    """
    autos.roodos.ec source spider using Patiotuerca Ecuador used cars pages.
    Source: https://ecuador.patiotuerca.com/usados/-/autos?fts=usados

    Parsing strategy:
    - Crawl listing pagination
    - Extract each car from JSON-LD blocks (@type=Car)
    - Normalize to ListingItem for DB upsert
    """

    name = "patiotuerca_ecuador"
    allowed_domains = ["ecuador.patiotuerca.com"]
    start_urls = ["https://ecuador.patiotuerca.com/usados/-/autos?fts=usados"]

    custom_settings = {
        "ROBOTSTXT_OBEY": True,
        "DOWNLOAD_DELAY": 1.0,
    }
    max_pages = 400
    max_empty_pages = 2
    handle_httpstatus_list = [429, 500, 502, 503, 504]

    def start_requests(self):
        # Start from page 1 explicitly and continue sequentially until no results.
        first = self._with_page(self.start_urls[0], 1)
        yield scrapy.Request(
            first,
            callback=self.parse,
            errback=self.on_page_error,
            meta={"page_num": 1, "empty_streak": 0},
        )

    def parse(self, response):
        requested_page = response.meta.get("page_num", 1)

        if response.status in self.handle_httpstatus_list:
            self.logger.warning("Page %s returned HTTP %s. Skipping to next page.", requested_page, response.status)
            next_page = requested_page + 1
            if next_page <= self.max_pages:
                next_url = self._with_page(self.start_urls[0], next_page)
                yield scrapy.Request(
                    next_url,
                    callback=self.parse,
                    errback=self.on_page_error,
                    meta={"page_num": next_page, "empty_streak": response.meta.get("empty_streak", 0) + 1},
                    dont_filter=True,
                )
            return

        current_page = self._current_page_from_url(response.url)

        # If site normalizes/redirects to a different page, stop this chain.
        if current_page and current_page != requested_page:
            self.logger.info("Stopping chain: requested page %s resolved to %s", requested_page, current_page)
            return

        found = 0

        # 1) Extract listings from JSON-LD car objects (list page only)
        scripts = response.css("script[type='application/ld+json']::text").getall()
        for raw in scripts:
            data = self._safe_json(raw)
            if not data:
                continue

            objs = data if isinstance(data, list) else [data]
            for obj in objs:
                if not isinstance(obj, dict):
                    continue
                if obj.get("@type") != "Car":
                    continue

                item = self._map_car_object(obj)
                if item:
                    found += 1
                    yield item

        # 2) Continue sequential pagination with stop conditions
        empty_streak = response.meta.get("empty_streak", 0)
        empty_streak = 0 if found > 0 else empty_streak + 1

        next_page = requested_page + 1
        if next_page > self.max_pages:
            self.logger.info("Stopping chain: reached max_pages=%s", self.max_pages)
            return
        if empty_streak >= self.max_empty_pages:
            self.logger.info("Stopping chain: empty_streak=%s", empty_streak)
            return

        next_url = self._with_page(self.start_urls[0], next_page)
        yield scrapy.Request(
            next_url,
            callback=self.parse,
            errback=self.on_page_error,
            meta={"page_num": next_page, "empty_streak": empty_streak},
        )

    def on_page_error(self, failure):
        request = failure.request
        page_num = request.meta.get("page_num", 1)
        empty_streak = request.meta.get("empty_streak", 0)
        self.logger.warning("Page %s failed (%s). Skipping to next page.", page_num, failure.value)

        next_page = page_num + 1
        if next_page > self.max_pages:
            self.logger.info("Stopping chain after errors: reached max_pages=%s", self.max_pages)
            return

        next_url = self._with_page(self.start_urls[0], next_page)
        yield scrapy.Request(
            next_url,
            callback=self.parse,
            errback=self.on_page_error,
            meta={"page_num": next_page, "empty_streak": empty_streak + 1},
            dont_filter=True,
        )

    def _map_car_object(self, obj):
        url = obj.get("url")
        if not url:
            return None

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "EC"

        item["url"] = url
        item["slug"] = url.rstrip("/").split("/")[-1]

        name = (obj.get("name") or "").strip()
        brand = ((obj.get("brand") or {}).get("name") or "").strip()
        model = (obj.get("model") or "").strip()
        title = name or " ".join(x for x in [brand, model] if x).strip()
        item["brand_name"] = brand or None
        item["model_name"] = model or None
        item["title"] = title[:250] if title else "Sin título"

        image = obj.get("image")
        if isinstance(image, list):
            image = image[0] if image else None
        item["image"] = image
        item["image_big"] = image

        body_type = (obj.get("bodyType") or "").strip()
        fuel = (obj.get("fuelType") or "").strip()
        transmission = (obj.get("vehicleTransmission") or "").strip()
        details = " - ".join(x for x in [body_type, fuel, transmission] if x)
        item["details"] = details or None

        item["price"] = self._to_int((obj.get("offers") or {}).get("price"))
        item["year"] = self._to_int(obj.get("vehicleModelDate"))
        item["km"] = self._to_int((obj.get("mileageFromOdometer") or {}).get("value"))

        # Pending catalog mappings
        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_body"] = None
        item["id_fuel"] = self._map_fuel(fuel)
        item["id_transmission"] = self._map_transmission(transmission)

        # Extract numeric listing id from URL tail
        m = re.search(r"/(\d+)$", item["url"])
        item["external_id"] = m.group(1) if m else None

        return item

    def _safe_json(self, raw):
        try:
            return json.loads(raw)
        except Exception:
            return None

    def _page_token(self, n):
        return base64.b64encode(str(n).encode("utf-8")).decode("utf-8")

    def _with_page(self, base_url, n):
        parsed = urlparse(base_url)
        q = parse_qs(parsed.query)
        q["page"] = [self._page_token(n)]
        query = urlencode(q, doseq=True)
        return urlunparse((parsed.scheme, parsed.netloc, parsed.path, parsed.params, query, parsed.fragment))

    def _current_page_from_url(self, url):
        try:
            parsed = urlparse(url)
            page_token = parse_qs(parsed.query).get("page", [None])[0]
            if not page_token:
                return 1
            decoded = base64.b64decode(page_token).decode("utf-8").strip()
            return int(decoded)
        except Exception:
            return None

    def _to_int(self, value):
        if value in (None, ""):
            return None
        text = str(value)
        digits = "".join(ch for ch in text if ch.isdigit())
        return int(digits) if digits else None

    def _map_fuel(self, value):
        t = (value or "").lower()
        if "gasolina" in t or "bencina" in t:
            return 1
        if "diesel" in t or "diésel" in t:
            return 2
        if "elect" in t:
            return 3
        if "hibr" in t:
            return 4
        return None

    def _map_transmission(self, value):
        t = (value or "").lower()
        if "auto" in t:
            return 1
        if "manual" in t or "mec" in t:
            return 2
        return None


class AutosTopmotorsEcSpider(scrapy.Spider):
    """
    Secondary Ecuador autos source:
    https://www.topmotorsusados.com/vehiculos

    Uses SSR JSON in __NEXT_DATA__ (dataVehicles.vehicles) and paginates with ?page=N.
    """

    name = "autos_topmotors_ec"
    allowed_domains = ["www.topmotorsusados.com", "topmotorsusados.com"]
    base_url = "https://www.topmotorsusados.com/vehiculos"

    custom_settings = {
        "ROBOTSTXT_OBEY": True,
        "DOWNLOAD_DELAY": 1.0,
    }

    def start_requests(self):
        yield scrapy.Request(f"{self.base_url}?page=1", callback=self.parse, meta={"page_num": 1})

    def parse(self, response):
        page_num = response.meta.get("page_num", 1)

        next_data_raw = response.css("#__NEXT_DATA__::text").get()
        if not next_data_raw:
            self.logger.warning("No __NEXT_DATA__ found on page %s", page_num)
            return

        payload = self._safe_json(next_data_raw) or {}
        page_props = ((payload.get("props") or {}).get("pageProps") or {})
        data_vehicles = page_props.get("dataVehicles") or {}
        pagination = data_vehicles.get("pagination") or {}
        vehicles = data_vehicles.get("vehicles") or []

        for v in vehicles:
            item = self._map_vehicle(v)
            if item:
                yield item

        current = int(pagination.get("currentPage") or page_num)
        total_pages = int(pagination.get("totalPages") or current)
        if current < total_pages:
            nxt = current + 1
            yield scrapy.Request(f"{self.base_url}?page={nxt}", callback=self.parse, meta={"page_num": nxt})

    def _map_vehicle(self, v):
        vid = v.get("id") or v.get("idWeb")
        if not vid:
            return None

        brand = ((v.get("brand") or {}).get("value") or "").strip()
        model = ((v.get("model") or {}).get("value") or "").strip()
        version = ((v.get("version") or {}).get("value") or "").strip()
        year = self._to_int(v.get("year"))

        title_parts = [str(year) if year else "", brand, model, version]
        title = " ".join([p for p in title_parts if p]).strip() or f"Vehículo {vid}"

        dealer_city = ((v.get("dealerInfo") or {}).get("city") or "").strip()
        mileage = v.get("mileage")
        price = self._to_int(v.get("price") or v.get("priceDisplay"))

        image = v.get("firstImageThumbnail")
        if not image:
            imgs = v.get("images") or []
            if imgs and isinstance(imgs[0], str):
                image = imgs[0]

        details_parts = []
        if dealer_city:
            details_parts.append(f"Ciudad: {dealer_city}")
        if mileage:
            details_parts.append(f"Kilometraje: {mileage}")
        details = " | ".join(details_parts) if details_parts else None

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "EC"

        item["url"] = f"https://www.topmotorsusados.com/vehiculo/{vid}"
        item["slug"] = str(vid)
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = details
        item["price"] = price
        item["year"] = year
        item["km"] = self._to_int(mileage)
        item["external_id"] = str(vid)

        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["brand_name"] = brand or None
        item["model_name"] = model or None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        return item

    def _safe_json(self, raw):
        try:
            return json.loads(raw)
        except Exception:
            return None

    def _to_int(self, value):
        if value in (None, ""):
            return None
        digits = "".join(ch for ch in str(value) if ch.isdigit())
        return int(digits) if digits else None


class AutosMercadolibreEcuadorSpider(scrapy.Spider):
    """
    Ecuador used cars from MercadoLibre listing pages (no detail crawl).
    """

    name = "mercadolibre_ecuador"
    allowed_domains = ["autos.mercadolibre.com.ec", "mercadolibre.com.ec", "auto.mercadolibre.com.ec"]
    base_url = "https://autos.mercadolibre.com.ec/autos-camionetas/usados/vehiculos-usados"

    custom_settings = {
        "ROBOTSTXT_OBEY": False,
        "DOWNLOAD_DELAY": 1.0,
    }

    def start_requests(self):
        yield scrapy.Request(self.base_url, callback=self.parse, meta={"page_seeded": False})

    def parse(self, response):
        cards = response.css("li.ui-search-layout__item")

        for card in cards:
            item = self._parse_card(response, card)
            if item:
                yield item

        # Seed remaining pages once from page-1 using total count.
        if response.meta.get("page_seeded"):
            return

        total = self._extract_total_results(response.text)
        per_page = len(cards) if len(cards) > 0 else 48
        if not total or total <= per_page:
            return

        # MercadoLibre pagination path: ..._Desde_49, ..._Desde_97, ...
        start = per_page + 1
        while start <= total:
            next_url = f"{self.base_url}_Desde_{start}"
            yield scrapy.Request(next_url, callback=self.parse, meta={"page_seeded": True})
            start += per_page

    def _parse_card(self, response, card):
        a = card.css("a.poly-component__title::attr(href)").get()
        title = (card.css("a.poly-component__title::text").get() or "").strip()
        if not a or not title:
            return None

        url = response.urljoin(a.split("#")[0])
        image = card.css("img.poly-component__picture::attr(src)").get()

        price_text = "".join(card.css(".poly-price__current *::text").getall())
        price = self._to_int(price_text)

        attrs = [x.strip() for x in card.css(".poly-attributes_list__item::text").getall() if x.strip()]
        year = self._to_int(attrs[0]) if len(attrs) > 0 else None
        km = None
        for atext in attrs:
            if "km" in atext.lower() or "milla" in atext.lower():
                km = self._to_int(atext)
                break

        details = " | ".join(attrs) if attrs else None

        ext = None
        m = re.search(r"/(MEC-\d+)", url)
        if m:
            ext = m.group(1)

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "EC"
        item["url"] = url
        item["slug"] = (url.rstrip("/").split("/")[-1])[:255]
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = details
        item["price"] = price
        item["year"] = year
        item["km"] = km
        item["external_id"] = ext

        # heuristic brand/model split from listing title
        t_parts = [p for p in re.split(r"\s+", title) if p]
        item["brand_name"] = t_parts[0] if t_parts else None
        item["model_name"] = " ".join(t_parts[1:3]) if len(t_parts) > 1 else None

        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        return item

    def _extract_total_results(self, html):
        m = re.search(r'"total":\s*(\d+)', html)
        if not m:
            return None
        try:
            return int(m.group(1))
        except Exception:
            return None

    def _to_int(self, value):
        if value in (None, ""):
            return None
        digits = "".join(ch for ch in str(value) if ch.isdigit())
        return int(digits) if digits else None


class AutosMercadolibreChileSpider(scrapy.Spider):
    """
    Chile used vehicles from MercadoLibre listing pages (no detail crawl).
    """

    name = "mercadolibre_chile"
    allowed_domains = ["autos.mercadolibre.cl", "mercadolibre.cl"]
    base_url = "https://autos.mercadolibre.cl/usado/"

    custom_settings = {
        "ROBOTSTXT_OBEY": False,
        "DOWNLOAD_DELAY": 1.0,
    }

    def start_requests(self):
        yield scrapy.Request(self.base_url, callback=self.parse, meta={"page_seeded": False})

    def parse(self, response):
        cards = response.css("li.ui-search-layout__item")

        for card in cards:
            item = self._parse_card(response, card)
            if item:
                yield item

        if response.meta.get("page_seeded"):
            return

        total = self._extract_total_results(response.text)
        per_page = len(cards) if len(cards) > 0 else 48
        if not total or total <= per_page:
            return

        start = per_page + 1
        while start <= total:
            next_url = f"{self.base_url}_Desde_{start}"
            yield scrapy.Request(next_url, callback=self.parse, meta={"page_seeded": True})
            start += per_page

    def _parse_card(self, response, card):
        a = card.css("a.poly-component__title::attr(href)").get()
        title = (card.css("a.poly-component__title::text").get() or "").strip()
        if not a or not title:
            return None

        url = response.urljoin(a.split("#")[0])
        image = card.css("img.poly-component__picture::attr(src)").get()

        price_text = "".join(card.css(".poly-price__current *::text").getall())
        price = self._to_int(price_text)

        attrs = [x.strip() for x in card.css(".poly-attributes_list__item::text").getall() if x.strip()]
        year = self._to_int(attrs[0]) if len(attrs) > 0 else None
        km = None
        for atext in attrs:
            if "km" in atext.lower() or "milla" in atext.lower():
                km = self._to_int(atext)
                break

        details = " | ".join(attrs) if attrs else None

        ext = None
        m = re.search(r"/(MLC-\d+)", url)
        if m:
            ext = m.group(1)

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "CL"
        item["url"] = url
        item["slug"] = (url.rstrip("/").split("/")[-1])[:255]
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = details
        item["price"] = price
        item["year"] = year
        item["km"] = km
        item["external_id"] = ext

        t_parts = [p for p in re.split(r"\s+", title) if p]
        item["brand_name"] = t_parts[0] if t_parts else None
        item["model_name"] = " ".join(t_parts[1:3]) if len(t_parts) > 1 else None

        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        return item

    def _extract_total_results(self, html):
        m = re.search(r'"total":\s*(\d+)', html)
        if not m:
            return None
        try:
            return int(m.group(1))
        except Exception:
            return None

    def _to_int(self, value):
        if value in (None, ""):
            return None
        digits = "".join(ch for ch in str(value) if ch.isdigit())
        return int(digits) if digits else None


class AutosMercadolibrePeruSpider(scrapy.Spider):
    """
    Peru used vehicles from MercadoLibre listing pages (no detail crawl).
    """

    name = "mercadolibre_peru"
    allowed_domains = ["autos.mercadolibre.com.pe", "mercadolibre.com.pe", "listado.mercadolibre.com.pe"]
    base_url = "https://listado.mercadolibre.com.pe/vehiculos/"

    custom_settings = {
        "ROBOTSTXT_OBEY": False,
        "DOWNLOAD_DELAY": 1.0,
    }

    def start_requests(self):
        yield scrapy.Request(self.base_url, callback=self.parse, meta={"page_seeded": False})

    def parse(self, response):
        cards = response.css("li.ui-search-layout__item")

        for card in cards:
            item = self._parse_card(response, card)
            if item:
                yield item

        if response.meta.get("page_seeded"):
            return

        total = self._extract_total_results(response.text)
        per_page = len(cards) if len(cards) > 0 else 48
        if not total or total <= per_page:
            return

        start = per_page + 1
        while start <= total:
            next_url = f"{self.base_url}_Desde_{start}"
            yield scrapy.Request(next_url, callback=self.parse, meta={"page_seeded": True})
            start += per_page

    def _parse_card(self, response, card):
        a = card.css("a.poly-component__title::attr(href)").get()
        title = (card.css("a.poly-component__title::text").get() or "").strip()
        if not a or not title:
            return None

        url = response.urljoin(a.split("#")[0])
        image = card.css("img.poly-component__picture::attr(src)").get()

        price_text = "".join(card.css(".poly-price__current *::text").getall())
        price = self._to_int(price_text)

        attrs = [x.strip() for x in card.css(".poly-attributes_list__item::text").getall() if x.strip()]
        year = self._to_int(attrs[0]) if len(attrs) > 0 else None
        km = None
        for atext in attrs:
            if "km" in atext.lower() or "milla" in atext.lower():
                km = self._to_int(atext)
                break

        details = " | ".join(attrs) if attrs else None

        ext = None
        m = re.search(r"/(MPE-\d+)", url)
        if m:
            ext = m.group(1)

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "PE"
        item["url"] = url
        item["slug"] = (url.rstrip("/").split("/")[-1])[:255]
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = details
        item["price"] = price
        item["year"] = year
        item["km"] = km
        item["external_id"] = ext

        t_parts = [p for p in re.split(r"\s+", title) if p]
        item["brand_name"] = t_parts[0] if t_parts else None
        item["model_name"] = " ".join(t_parts[1:3]) if len(t_parts) > 1 else None

        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        return item

    def _extract_total_results(self, html):
        m = re.search(r'"total":\s*(\d+)', html)
        if not m:
            return None
        try:
            return int(m.group(1))
        except Exception:
            return None

    def _to_int(self, value):
        if value in (None, ""):
            return None
        digits = "".join(ch for ch in str(value) if ch.isdigit())
        return int(digits) if digits else None


class AutosAutocosmosEcuadorSpider(scrapy.Spider):
    """
    Ecuador used cars from Autocosmos listing cards.
    """

    name = "autocosmos_ecuador"
    allowed_domains = ["www.autocosmos.com.ec", "autocosmos.com.ec"]
    start_urls = ["https://www.autocosmos.com.ec/auto/usado"]

    custom_settings = {
        "ROBOTSTXT_OBEY": True,
        "DOWNLOAD_DELAY": 1.0,
        # this source worked better in direct mode without datacenter proxies.
        "DOWNLOADER_MIDDLEWARES": {
            "roodos_scraper.middlewares.WebshareProxyRotationMiddleware": None,
            "scrapy.downloadermiddlewares.httpproxy.HttpProxyMiddleware": 750,
        },
    }

    def parse(self, response):
        cards = response.css("article.card.listing-card")
        for card in cards:
            item = self._parse_card(response, card)
            if item:
                yield item

        # Follow explicit pagination links if present
        for href in response.css("a[href*='/auto/usado?page=']::attr(href), a[href*='/auto/usado?pagina=']::attr(href)").getall():
            yield response.follow(href, callback=self.parse)

    def _parse_card(self, response, card):
        href = card.css("a[itemprop='url']::attr(href)").get() or card.css("a::attr(href)").get()
        title = (card.css("h2::text, h3::text, [itemprop='name']::attr(content)").get() or "").strip()
        if not href or not title:
            return None

        url = response.urljoin(href)
        image = card.css("img::attr(src)").get() or card.css("img::attr(data-src)").get()

        # price: prefer schema value, fallback to first monetary match in card text
        price_text = card.css("[itemprop='price']::attr(content)").get()
        if not price_text:
            raw = " ".join(card.css(".price *::text, .listing-card__price *::text, [itemprop='description']::attr(content)").getall())
            m_price = re.search(r"u\$s\s*([\d\.,]+)", raw, re.I)
            price_text = m_price.group(1) if m_price else raw
        price = self._to_int(price_text)

        # year / km from card metadata line
        meta = " ".join([x.strip() for x in card.css(".card__content *::text, .listing-card__content *::text, .card__specs *::text").getall() if x.strip()])
        year = None
        ym = re.search(r"\b(19\d{2}|20\d{2})\b", meta)
        if ym:
            year = int(ym.group(1))

        km = None
        kmm = re.search(r"([\d\.,]+)\s*(km|kms|kil[oó]metros)", meta, re.I)
        if kmm:
            km = self._to_int(kmm.group(1))

        details = meta[:2000] if meta else None

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "EC"
        item["url"] = url[:250]
        item["slug"] = url.rstrip("/").split("/")[-1][:255]
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = details
        item["price"] = price
        item["year"] = year
        item["km"] = km

        # infer brand/model from URL pattern /auto/usado/{brand}/{model}/...
        parts = [p for p in urlparse(item["url"]).path.split("/") if p]
        item["brand_name"] = parts[2].replace("-", " ") if len(parts) > 2 else None
        item["model_name"] = parts[3].replace("-", " ") if len(parts) > 3 else None

        # stable-ish external id from slug tail token
        m = re.search(r"/([a-f0-9]{16,})$", item["url"], re.I)
        item["external_id"] = m.group(1) if m else item["slug"][:50]

        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        return item

    def _to_int(self, value):
        if value in (None, ""):
            return None
        digits = "".join(ch for ch in str(value) if ch.isdigit())
        return int(digits) if digits else None


class AutosKoopaPeSpider(scrapy.Spider):
    """
    Peru autos source from Koopa storefront grid.
    https://koopa.pe/compra/
    """

    name = "autos_koopa_pe"
    allowed_domains = ["koopa.pe", "www.koopa.pe"]
    start_urls = ["https://koopa.pe/compra/"]

    custom_settings = {
        "ROBOTSTXT_OBEY": True,
        "DOWNLOAD_DELAY": 1.0,
    }

    def parse(self, response):
        cards = response.css(".jet-listing-grid__item")
        for card in cards:
            href = card.css("a[href*='/producto/']::attr(href)").get()
            if not href:
                continue
            url = response.urljoin(href.split("#")[0])

            brand = (card.css(".container-marca h2::text").get() or "").strip()
            model = (card.css(".container-modelo h2::text").get() or "").strip()
            trans = (card.css("h3.elementor-heading-title::text").get() or "").strip()
            price_txt = " ".join(card.css(".woocommerce-Price-amount *::text").getall())
            image = card.css("img::attr(src)").get()

            text_blob = " ".join([t.strip() for t in card.css("*::text").getall() if t and t.strip()])
            year = self._extract_year(text_blob)
            km = self._extract_km(text_blob)

            title = " ".join([x for x in [str(year) if year else "", brand, model] if x]).strip()
            if not title:
                title = (model or brand or url.rstrip("/").split("/")[-1])[:250]

            item = ListingItem()
            item["vertical"] = "car"
            item["source_name"] = self.name
            item["country"] = "PE"
            item["url"] = url[:250]
            item["slug"] = url.rstrip("/").split("/")[-1][:255]
            item["title"] = title[:250]
            item["image"] = image
            item["image_big"] = image
            item["details"] = " | ".join([x for x in [trans, text_blob[:1200]] if x])[:2000]
            item["price"] = self._extract_price(price_txt)
            item["year"] = year
            item["km"] = km
            item["external_id"] = item["slug"][:50]

            item["brand_name"] = brand or None
            item["model_name"] = model or None
            item["id_state"] = None
            item["id_city"] = None
            item["id_car_make"] = None
            item["id_car_model"] = None
            item["id_fuel"] = None
            item["id_body"] = None
            item["id_transmission"] = self._map_trans(trans)
            yield item

    def _to_int(self, value):
        if value in (None, ""):
            return None
        digits = "".join(ch for ch in str(value) if ch.isdigit())
        return int(digits) if digits else None

    def _extract_price(self, text):
        if not text:
            return None
        vals = []
        for m in re.findall(r"(?:\$|S\/\.?\s*)\s*([\d\.,]+)", text):
            v = self._to_int(m)
            if v:
                vals.append(v)
        if vals:
            # on sale cards include old and new; prefer lower (current) value
            return min(vals)
        return self._to_int(text)

    def _extract_year(self, text):
        m = re.search(r"\b(19\d{2}|20\d{2})\b", text or "")
        return int(m.group(1)) if m else None

    def _extract_km(self, text):
        m = re.search(r"([\d\.,]+)\s*(km|kil[oó]metros)", text or "", re.I)
        return self._to_int(m.group(1)) if m else None

    def _map_trans(self, value):
        t = (value or "").lower()
        if "auto" in t:
            return 1
        if "mec" in t or "manual" in t:
            return 2
        return None


class AutosBrunoFritschChileSpider(scrapy.Spider):
    """
    Chile used cars source from Bruno Fritsch.
    https://www.brunofritsch.cl/autos-usados
    """

    name = "brunofritsch_chile"
    allowed_domains = ["www.brunofritsch.cl", "brunofritsch.cl"]
    start_urls = ["https://www.brunofritsch.cl/autos-usados"]

    custom_settings = {
        "ROBOTSTXT_OBEY": False,
        "DOWNLOAD_DELAY": 1.0,
        # Works better direct for this source.
        "DOWNLOADER_MIDDLEWARES": {
            "roodos_scraper.middlewares.WebshareProxyRotationMiddleware": None,
            "scrapy.downloadermiddlewares.httpproxy.HttpProxyMiddleware": 750,
        },
    }

    max_pages = 80

    def start_requests(self):
        yield scrapy.Request("https://www.brunofritsch.cl/autos-usados", callback=self.parse, meta={"page_num": 1})

    def parse(self, response):
        page_num = int(response.meta.get("page_num", 1))
        detail_count = 0

        for href in response.css("a[href^='/autos-usados/']::attr(href)").getall():
            path = href.split("#")[0]
            if "?" in path:
                path = path.split("?", 1)[0]
            tail = path.replace("/autos-usados/", "").strip("/")
            if not tail or tail.startswith("page"):
                continue
            detail_count += 1
            yield response.follow(path, callback=self.parse_detail)

        # pagination by page number (site exposes rel=next and ?page=N even if no anchor)
        if detail_count > 0 and page_num < self.max_pages:
            next_page = page_num + 1
            next_url = f"https://www.brunofritsch.cl/autos-usados?page={next_page}"
            yield scrapy.Request(next_url, callback=self.parse, meta={"page_num": next_page})

    def parse_detail(self, response):
        scripts = response.css("script[type='application/ld+json']::text").getall()
        product = None
        for raw in scripts:
            data = self._safe_json(raw)
            if not data:
                continue
            objs = data if isinstance(data, list) else [data]
            for obj in objs:
                if isinstance(obj, dict) and obj.get("@type") == "Product":
                    product = obj
                    break
            if product:
                break

        if not product:
            return

        url = (response.url or (product.get("offers") or {}).get("url") or "")
        if not url:
            return

        title = (response.css("meta[property='og:title']::attr(content)").get() or product.get("name") or "").strip()
        image = response.css("meta[property='og:image']::attr(content)").get() or product.get("image")
        if isinstance(image, list):
            image = image[0] if image else None

        brand_name = None
        brand = product.get("brand")
        if isinstance(brand, dict):
            brand_name = brand.get("name")
        elif isinstance(brand, str):
            brand_name = brand

        model_name = None
        desc = product.get("description") or ""
        if brand_name and desc:
            model_name = re.sub(rf"^{re.escape(brand_name)}\s*-?\s*", "", desc, flags=re.I).strip()
        if not model_name:
            model_name = desc[:120] if desc else None

        price = self._to_int((product.get("offers") or {}).get("price"))
        year = self._extract_year(title + " " + desc)
        ext = product.get("sku") or url.rstrip("/").split("/")[-1]

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "CL"
        item["url"] = url[:250]
        item["slug"] = url.rstrip("/").split("/")[-1][:255]
        item["title"] = (title or item["slug"] or "Sin título")[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = (desc[:1000] if desc else None)
        item["price"] = price
        item["year"] = year
        item["km"] = None
        item["external_id"] = str(ext)[:50] if ext else None

        item["brand_name"] = brand_name[:80] if brand_name else None
        item["model_name"] = model_name[:120] if model_name else None
        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        yield item

    def _safe_json(self, raw):
        import json
        try:
            return json.loads(raw)
        except Exception:
            return None

    def _to_int(self, value):
        if value in (None, ""):
            return None
        digits = "".join(ch for ch in str(value) if ch.isdigit())
        return int(digits) if digits else None

    def _extract_year(self, text):
        m = re.search(r"\b(19\d{2}|20\d{2})\b", text or "")
        return int(m.group(1)) if m else None


class AutosAutocosmosChileSpider(scrapy.Spider):
    """
    Chile used cars from Autocosmos listing cards.
    """

    name = "autocosmos_chile"
    allowed_domains = ["www.autocosmos.cl", "autocosmos.cl"]
    start_urls = ["https://www.autocosmos.cl/auto/usado"]

    custom_settings = {
        "ROBOTSTXT_OBEY": True,
        "DOWNLOAD_DELAY": 1.0,
        "DOWNLOADER_MIDDLEWARES": {
            "roodos_scraper.middlewares.WebshareProxyRotationMiddleware": None,
            "scrapy.downloadermiddlewares.httpproxy.HttpProxyMiddleware": 750,
        },
    }

    def parse(self, response):
        cards = response.css("article.card.listing-card")
        for card in cards:
            item = self._parse_card(response, card)
            if item:
                yield item

        for href in response.css("a[href*='/auto/usado?page=']::attr(href), a[href*='/auto/usado?pagina=']::attr(href)").getall():
            yield response.follow(href, callback=self.parse)

    def _parse_card(self, response, card):
        href = card.css("a[itemprop='url']::attr(href)").get() or card.css("a::attr(href)").get()
        title = (card.css("h2::text, h3::text, [itemprop='name']::attr(content)").get() or "").strip()
        if not href or not title:
            return None

        url = response.urljoin(href)
        image = card.css("img::attr(src)").get() or card.css("img::attr(data-src)").get()

        price_text = card.css("[itemprop='price']::attr(content)").get()
        if not price_text:
            raw = " ".join(card.css(".price *::text, .listing-card__price *::text, [itemprop='description']::attr(content)").getall())
            m_price = re.search(r"(?:\$|u\$s)\s*([\d\.,]+)", raw, re.I)
            price_text = m_price.group(1) if m_price else raw
        price = self._to_int(price_text)

        meta = " ".join([x.strip() for x in card.css(".card__content *::text, .listing-card__content *::text, .card__specs *::text").getall() if x.strip()])
        year = None
        ym = re.search(r"\b(19\d{2}|20\d{2})\b", meta)
        if ym:
            year = int(ym.group(1))

        km = None
        kmm = re.search(r"([\d\.,]+)\s*(km|kms|kil[oó]metros)", meta, re.I)
        if kmm:
            km = self._to_int(kmm.group(1))

        details = meta[:2000] if meta else None

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "CL"
        item["url"] = url[:250]
        item["slug"] = url.rstrip("/").split("/")[-1][:255]
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = details
        item["price"] = price
        item["year"] = year
        item["km"] = km

        parts = [p for p in urlparse(item["url"]).path.split("/") if p]
        item["brand_name"] = parts[2].replace("-", " ") if len(parts) > 2 else None
        item["model_name"] = parts[3].replace("-", " ") if len(parts) > 3 else None

        m = re.search(r"/([a-f0-9]{16,})$", item["url"], re.I)
        item["external_id"] = m.group(1) if m else item["slug"][:50]

        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        return item

    def _to_int(self, value):
        if value in (None, ""):
            return None
        digits = "".join(ch for ch in str(value) if ch.isdigit())
        return int(digits) if digits else None


class AutosKovacsChileSpider(scrapy.Spider):
    name = "kovacs_chile"
    allowed_domains = ["www.kovacsusados.cl", "kovacsusados.cl"]
    start_urls = ["https://www.kovacsusados.cl/web/autos-usados"]
    custom_settings = {"ROBOTSTXT_OBEY": True, "DOWNLOAD_DELAY": 1.0}

    def parse(self, response):
        for href in response.css("a[href*='/web/vehiculos/view?id=']::attr(href)").getall():
            yield response.follow(href, callback=self.parse_detail)
        for href in response.css("a[href*='?page=']::attr(href)").getall():
            yield response.follow(href, callback=self.parse)

    def parse_detail(self, response):
        title = (response.css("meta[property='og:title']::attr(content)").get() or response.css("title::text").get() or "").strip()
        image = response.css("meta[property='og:image']::attr(content)").get()

        # prefer product price in page scripts/meta
        price = None
        m_price = re.search(r'"price"\s*:\s*([0-9]{5,})', response.text)
        if m_price:
            price = int(m_price.group(1))
        if price is None:
            ptxt = response.css("*::text").re_first(r"\$\s*([\d\.]{5,})")
            price = self._to_int(ptxt)

        m_year = re.search(r"\b(19\d{2}|20\d{2})\b", title)
        year = int(m_year.group(1)) if m_year else None

        km = None
        km_m = re.search(r"([\d\.]{2,})\s*KM", response.text, re.I)
        if km_m:
            km = self._to_int(km_m.group(1))

        # title often comes as: 'Kovacs UsadosToyota Rav4 ...'
        clean_title = re.sub(r"^Kovacs\s*Usados", "", title, flags=re.I).strip()
        tokens = [t for t in re.split(r"\s+", clean_title) if t]
        brand = tokens[0] if tokens else None
        model = " ".join(tokens[1:4]) if len(tokens) > 1 else None

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "CL"
        item["url"] = response.url[:250]
        item["slug"] = response.url.split("id=")[-1][:255]
        item["title"] = clean_title[:250] if clean_title else (title[:250] if title else item["slug"])
        item["image"] = image
        item["image_big"] = image
        item["details"] = None
        item["price"] = price
        item["year"] = year
        item["km"] = km
        item["external_id"] = item["slug"][:50]
        item["brand_name"] = brand
        item["model_name"] = model
        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        yield item

    def _to_int(self, value):
        if value in (None, ""):
            return None
        digits = "".join(ch for ch in str(value) if ch.isdigit())
        return int(digits) if digits else None


class AutosPortilloChileSpider(scrapy.Spider):
    name = "portillo_chile"
    allowed_domains = ["www.portillo.cl", "portillo.cl", "www.clicar.cl", "clicar.cl"]
    start_urls = ["https://www.portillo.cl/vehiculos/usado"]
    custom_settings = {
        "ROBOTSTXT_OBEY": False,
        "DOWNLOAD_DELAY": 1.0,
        "DOWNLOADER_MIDDLEWARES": {
            "roodos_scraper.middlewares.WebshareProxyRotationMiddleware": None,
            "scrapy.downloadermiddlewares.httpproxy.HttpProxyMiddleware": 750,
        },
    }

    def parse(self, response):
        for href in response.css("a[href*='/marcas/'][href*='/usado/']::attr(href)").getall():
            if "formulario-cotizacion" in href:
                continue
            yield response.follow(href, callback=self.parse_detail)
        for href in response.css("a[href*='/vehiculos/usado?page=']::attr(href)").getall():
            yield response.follow(href, callback=self.parse)

    def parse_detail(self, response):
        title = (response.css("meta[property='og:title']::attr(content)").get() or response.css("title::text").get() or "").strip()
        image = response.css("meta[property='og:image']::attr(content)").get()
        prices = response.css("*::text").re(r"\$\s*[\d\.]{5,}")
        price = self._to_int(prices[0]) if prices else None
        year = None
        m = re.search(r"\b(19\d{2}|20\d{2})\b", title)
        if m:
            year = int(m.group(1))
        km = None
        km_candidates = response.css("*::text").re(r"([\d\.]{2,})\s*km")
        km_vals = [self._to_int(v) for v in km_candidates if self._to_int(v)]
        km_vals = [v for v in km_vals if 10 <= v <= 1000000]
        if km_vals:
            km = km_vals[0]

        parts = [p for p in urlparse(response.url).path.split("/") if p]
        brand = parts[1] if len(parts) > 1 else None
        model = None
        if len(parts) > 3:
            model = re.sub(r"-\d+$", "", parts[3]).replace("-", " ")

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "CL"
        item["url"] = response.url[:250]
        item["slug"] = response.url.rstrip('/').split('/')[-1][:255]
        item["title"] = title[:250] if title else item["slug"]
        item["image"] = image
        item["image_big"] = image
        item["details"] = None
        item["price"] = price
        item["year"] = year
        item["km"] = km
        item["external_id"] = item["slug"][:50]
        item["brand_name"] = brand.replace('-', ' ') if brand else None
        item["model_name"] = model if model else None
        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        yield item

    def _to_int(self, value):
        if value in (None, ""):
            return None
        digits = "".join(ch for ch in str(value) if ch.isdigit())
        return int(digits) if digits else None


class AutosSalfaChileSpider(scrapy.Spider):
    """
    Salfa Usados (Chile): https://salfausados.cl/autos/catalogo

    El sitio es Angular SSR. Cada página del catálogo (?page=N, 18 autos) trae
    el resultado de la API embebido en <script id="ng-state"> (vehicles.items,
    con total/pages). Se mapea desde ese JSON, sin pedir las fichas: la ficha
    no tiene og:title/og:image y el JSON ya trae todo.
    URL del aviso: /autos/vehiculo/{id}; external_id = id numérico limpio.
    """

    name = "salfa_chile"
    allowed_domains = ["salfausados.cl", "www.salfausados.cl"]
    base_url = "https://salfausados.cl/autos/catalogo"
    custom_settings = {
        "ROBOTSTXT_OBEY": False,
        "DOWNLOAD_DELAY": 1.0,
        "DOWNLOADER_MIDDLEWARES": {
            "roodos_scraper.middlewares.WebshareProxyRotationMiddleware": None,
            "scrapy.downloadermiddlewares.httpproxy.HttpProxyMiddleware": 750,
        },
    }
    max_pages = 200

    def start_requests(self):
        yield scrapy.Request(f"{self.base_url}?page=1", callback=self.parse, meta={"page_num": 1})

    def parse(self, response):
        page_num = response.meta.get("page_num", 1)
        vehicles = self._vehicles_block(response)
        if vehicles is None:
            self.logger.warning("No ng-state vehicles block on page %s (%s)", page_num, response.url)
            return

        items = vehicles.get("items") or []
        for v in items:
            item = self._map_vehicle(v)
            if item:
                yield item

        total_pages = self._to_int(vehicles.get("pages")) or page_num
        if page_num == 1:
            self.logger.info("Salfa catalog: total=%s pages=%s", vehicles.get("total"), total_pages)
        if items and page_num < total_pages and page_num < self.max_pages:
            nxt = page_num + 1
            yield scrapy.Request(f"{self.base_url}?page={nxt}", callback=self.parse, meta={"page_num": nxt})

    def _vehicles_block(self, response):
        raw = response.css("script#ng-state::text").get()
        if not raw:
            return None
        try:
            state = json.loads(raw)
        except Exception:
            return None
        for entry in state.values():
            if not isinstance(entry, dict):
                continue
            body = entry.get("b")
            if isinstance(body, dict) and isinstance(body.get("vehicles"), dict):
                return body["vehicles"]
        return None

    def _map_vehicle(self, v):
        vid = v.get("id")
        if not vid:
            return None
        status = (v.get("status") or "").strip().lower()
        if status and status != "activo":
            return None

        # attributes viene como lista de dicts de una o dos llaves: [{"brand_id":..,"brand":..}, {"year":..}, ...]
        attrs = {}
        for a in v.get("attributes") or []:
            if isinstance(a, dict):
                attrs.update(a)

        brand = (attrs.get("brand") or "").strip()
        model = (attrs.get("model") or "").strip()
        version = (attrs.get("version") or "").strip()
        year = self._to_int(attrs.get("year"))
        km = self._to_int(attrs.get("mileage"))
        # legal_price = "Precio contado"; cash_price = "Precio financiamiento" (contado menos bono).
        price = self._to_int(attrs.get("legal_price")) or self._to_int(attrs.get("cash_price"))

        images = sorted(
            [i for i in (v.get("images") or []) if isinstance(i, dict) and i.get("source")],
            key=lambda i: i.get("order") or 0,
        )
        image = images[0]["source"] if images else None

        title = " ".join(p for p in [brand, model, str(year) if year else "", version] if p).strip()
        fuel = (attrs.get("fuel_type") or "").strip()
        transmission = (attrs.get("transmission") or "").strip()
        body = (attrs.get("vehicle_category") or attrs.get("vehicle_type") or "").strip()
        details = " - ".join(x for x in [body, fuel, transmission] if x) or None

        url = f"https://salfausados.cl/autos/vehiculo/{vid}"
        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "CL"
        item["url"] = url
        item["slug"] = str(vid)
        item["title"] = title[:250] if title else f"Salfa {vid}"
        item["image"] = image
        item["image_big"] = image
        item["details"] = details
        item["price"] = price
        item["year"] = year
        item["km"] = km
        item["external_id"] = str(vid)
        item["brand_name"] = brand or None
        item["model_name"] = model or None
        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = self._map_fuel(fuel)
        item["id_body"] = None
        item["id_transmission"] = self._map_transmission(transmission)
        return item

    def _map_fuel(self, value):
        t = (value or "").lower()
        if "gasolina" in t or "bencina" in t:
            return 1
        if "diesel" in t or "diésel" in t:
            return 2
        if "híbr" in t or "hibr" in t:
            return 4
        if "eléct" in t or "elect" in t:
            return 3
        return None

    def _map_transmission(self, value):
        t = (value or "").lower()
        if "auto" in t:
            return 1
        if "manual" in t or "mec" in t:
            return 2
        return None

    def _to_int(self, value):
        if value in (None, ""):
            return None
        digits = "".join(ch for ch in str(value) if ch.isdigit())
        return int(digits) if digits else None


class AutosMercadolibreMexicoSpider(scrapy.Spider):
    """
    Mexico used cars from MercadoLibre listing pages (no detail crawl).

    - Goes out WITHOUT the Webshare proxies: the Webshare pool is US datacenter
      IPs and MercadoLibre answers them with a 302 to /gz/account-verification
      (login wall, "negative_traffic"). From the server's own IP the listing
      loads normally.
    - A MercadoLibre search only serves ~2,048 results (offset <= 2001), so the
      catalogue (~66k) is split into price bands (_PriceRange_) small enough to
      be paginated completely. Bands with more than SEGMENT_CAP results are
      split again.
    - max_pages is a page budget per run (-a max_pages=N; ~1,700 covers the
      whole site). Requests are prioritised breadth-first (page 1 of every band
      before page 2, ...) so a partial budget still spreads across all prices.
    - A 302 to the login wall closes the spider with finish_reason
      blocked_login_wall (0 items -> the monitor marks the run empty).
    """

    name = "mercadolibre_mexico"
    allowed_domains = ["autos.mercadolibre.com.mx", "mercadolibre.com.mx", "auto.mercadolibre.com.mx"]
    base_url = "https://autos.mercadolibre.com.mx/usados/"

    custom_settings = {
        "ROBOTSTXT_OBEY": False,
        "CONCURRENT_REQUESTS": 1,
        "DOWNLOAD_DELAY": 2.0,
        "RANDOMIZE_DOWNLOAD_DELAY": True,
        "AUTOTHROTTLE_ENABLED": True,
        "AUTOTHROTTLE_START_DELAY": 2.0,
        "AUTOTHROTTLE_TARGET_CONCURRENCY": 1.0,
        "DOWNLOADER_MIDDLEWARES": {
            # Webshare (US datacenter) IPs get the login wall; keep the server IP
            # and a single, stable browser UA for the whole session.
            "roodos_scraper.middlewares.WebshareProxyRotationMiddleware": None,
            "roodos_scraper.middlewares.RandomUserAgentRotationMiddleware": None,
        },
    }

    per_page = 48
    max_offset = 2001          # last _Desde_ that still returns cards
    SEGMENT_CAP = 2000         # split a price band above this many results
    target_per_segment = 1800
    # Initial price bands (MXN). 0 as upper bound = open ended.
    seed_bounds = [0, 100000, 150000, 200000, 250000, 300000, 350000, 400000,
                   500000, 650000, 850000, 1200000, 0]

    def __init__(self, max_pages=400, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.max_pages = int(max_pages)
        self.pages_done = 0
        self.seen_urls = set()

    # ---------- requests ----------
    def _segment_url(self, lo, hi, start=1):
        if lo is None:  # root: whole catalogue, no price filter
            return self.base_url
        desde = f"_Desde_{start}" if start > 1 else ""
        return f"{self.base_url}{desde}_PriceRange_{lo}MXN-{hi}MXN_NoIndex_True"

    def _request(self, lo, hi, start=1, probe=False, priority=0):
        return scrapy.Request(
            self._segment_url(lo, hi, start),
            callback=self.parse,
            priority=priority,
            dont_filter=True,
            meta={
                "lo": lo, "hi": hi, "start": start, "probe": probe,
                "dont_redirect": True, "handle_httpstatus_list": [301, 302, 303, 307],
            },
        )

    def start_requests(self):
        # One root request; the price bands are scheduled from its callback so
        # they all sit in the (priority) scheduler at once.
        yield self._request(None, None, probe=True)

    # ---------- parsing ----------
    def parse(self, response):
        from scrapy.exceptions import CloseSpider

        if response.status in (301, 302, 303, 307):
            loc = response.headers.get("Location", b"").decode("utf-8", "ignore")
            if "account-verification" in loc or "/login" in loc:
                self.crawler.stats.set_value("mercadolibre/blocked_login_wall", 1)
                self.logger.error("MercadoLibre MX login wall (302 -> %s)", loc[:120])
                raise CloseSpider("blocked_login_wall")
            self.logger.warning("Unexpected redirect %s -> %s", response.url, loc[:120])
            return

        self.pages_done += 1
        lo, hi = response.meta["lo"], response.meta["hi"]

        for card in response.css("li.ui-search-layout__item"):
            item = self._parse_card(response, card)
            if item and item["url"] not in self.seen_urls:
                self.seen_urls.add(item["url"])
                yield item

        if self.pages_done >= self.max_pages:
            raise CloseSpider("max_pages_reached")

        if not response.meta.get("probe"):
            return

        total = self._extract_total_results(response.text) or 0
        if lo is None:
            self.crawler.stats.set_value("mercadolibre/site_total", total)
            self.logger.info("site total=%s", total)
            b = self.seed_bounds
            for sub_lo, sub_hi in zip(b[:-1], b[1:]):
                yield self._request(sub_lo, sub_hi, probe=True, priority=0)
            return

        self.logger.info("segment %s-%s total=%s", lo, hi or "inf", total)
        subs = self._split(lo, hi, total) if total > self.SEGMENT_CAP else None
        if subs:
            for sub_lo, sub_hi in subs:
                yield self._request(sub_lo, sub_hi, probe=True, priority=0)
            return

        pages = min((total + self.per_page - 1) // self.per_page,
                    (self.max_offset - 1) // self.per_page + 1)
        for page in range(2, pages + 1):
            # breadth-first: page 1 of every band, then page 2, ...
            yield self._request(lo, hi, start=(page - 1) * self.per_page + 1, priority=-page)

    def _split(self, lo, hi, total):
        if hi == 0:  # open ended band
            return [(lo, lo * 2), (lo * 2, 0)]
        width = hi - lo
        parts = max(2, -(-total // self.target_per_segment))
        step = width // parts
        if step < 1000:
            return None  # can't narrow further: crawl the reachable 42 pages
        cuts = [lo + i * step for i in range(parts)] + [hi]
        # adjacent bands share their boundary price (no gaps; duplicates are skipped)
        return list(zip(cuts[:-1], cuts[1:]))

    def _parse_card(self, response, card):
        a = card.css("a.poly-component__title::attr(href)").get()
        title = (card.css("a.poly-component__title::text").get() or "").strip()
        if not a or not title:
            return None
        url = response.urljoin(a.split("#")[0])
        if not url.startswith("https://auto.mercadolibre.com.mx/MLM-"):
            return None  # tracking / ad links
        image = card.css("img.poly-component__picture::attr(src)").get()
        price_text = "".join(card.css(".poly-price__current .andes-money-amount__fraction::text").getall()) \
            or "".join(card.css(".poly-price__current *::text").getall())
        attrs = [x.strip() for x in card.css(".poly-attributes_list__item::text").getall() if x.strip()]
        year = self._to_int(attrs[0]) if attrs else None
        km = next((self._to_int(x) for x in attrs if "km" in x.lower()), None)

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "MX"
        item["url"] = url[:250]
        item["slug"] = url.rstrip("/").split("/")[-1][:255]
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = (" | ".join(attrs)[:2000] if attrs else None)
        item["price"] = self._to_int(price_text)
        item["year"] = year
        item["km"] = km
        m = re.search(r"/(MLM-\d+)", url)
        item["external_id"] = m.group(1) if m else item["slug"][:50]
        t = [p for p in re.split(r"\s+", title) if p]
        item["brand_name"] = t[0] if t else None
        item["model_name"] = " ".join(t[1:3]) if len(t) > 1 else None
        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        return item

    def _extract_total_results(self, html):
        m = re.search(r'"offset":\d+,"total":(\d+)', html) or re.search(r'"total":\s*(\d+)', html)
        return int(m.group(1)) if m else None

    def _to_int(self, value):
        if value in (None, ""):
            return None
        d = "".join(ch for ch in str(value) if ch.isdigit())
        return int(d) if d else None


class AutosAutocosmosMexicoSpider(scrapy.Spider):
    """
    Mexico used cars from Autocosmos.

    Since ~Sept 2026 autocosmos.com.mx serves a new frontend: cards are
    `div.card.vehicle-card` (no more `article.listing-card`) and links point to
    /Publications/Clasified?id=N. The canonical ad URL is
    /auto/usado/<brand>/<model>/<version>/<id> (the site resolves it by id),
    so we build it from the card data. Pagination: ?page=N&itemsPerPage=36&sortBy=newest,
    stops on the first page without cards (~4.6k ads, ~128 pages).
    """

    name = "autocosmos_mexico"
    allowed_domains = ["www.autocosmos.com.mx", "autocosmos.com.mx"]
    start_urls = ["https://www.autocosmos.com.mx/auto/usado"]
    # robots.txt pide Crawl-delay: 20 (Scrapy no lo aplica solo); el recorrido completo toma ~45 min.
    custom_settings = {"ROBOTSTXT_OBEY": True, "DOWNLOAD_DELAY": 20}

    items_per_page = 36
    max_pages = 1000  # safety net only; the crawl stops when a page has no cards

    def start_requests(self):
        yield scrapy.Request(self._page_url(1), callback=self.parse, meta={"page_num": 1})

    def _page_url(self, page_num):
        # sortBy=newest: the default order is shuffled on every request, so plain
        # pagination repeats/skips ~40% of the ads.
        return (
            f"https://www.autocosmos.com.mx/auto/usado?page={page_num}"
            f"&itemsPerPage={self.items_per_page}&sortBy=newest"
        )

    def parse(self, response):
        page_num = int(response.meta.get("page_num", 1))
        cards = response.css("div.vehicle-card")
        if page_num == 1:
            m_total = re.search(r"(\d[\d,\.]*)\s+publicaciones", response.text)
            if m_total:
                self.logger.info("autocosmos_mexico: site reports %s listings", m_total.group(1))

        for card in cards:
            item = self._parse_card(response, card)
            if item:
                yield item

        if cards and page_num < self.max_pages:
            next_num = page_num + 1
            yield scrapy.Request(self._page_url(next_num), callback=self.parse, meta={"page_num": next_num})
        elif not cards:
            self.logger.info("autocosmos_mexico: no cards on page %s, pagination finished", page_num)

    def _parse_card(self, response, card):
        href = card.css("a.u-link-v2::attr(href)").get() or card.css("a[href*='Clasified']::attr(href)").get()
        pub_id = card.css("[data-publication-id]::attr(data-publication-id)").get()
        if not pub_id and href:
            m = re.search(r"id=(\d+)", href)
            pub_id = m.group(1) if m else None
        if not pub_id:
            return None

        brand = (card.css(".favorite-btn::attr(data-brand)").get() or "").strip() or None
        model = (card.css(".favorite-btn::attr(data-serie)").get() or "").strip() or None
        title_parts = [t.strip() for t in card.css("h6.card-publication-title ::text").getall() if t.strip()]
        version = title_parts[1] if len(title_parts) > 1 else None
        title = " ".join(title_parts)
        if not title:
            return None

        details_lines = [
            " ".join(t.strip() for t in p.css("::text").getall() if t.strip())
            for p in card.css("p.card-publication-detail-text")
        ]
        details_lines = [x for x in details_lines if x]
        specs = details_lines[0] if details_lines else ""  # e.g. "2024 | 21,057 km"
        location = details_lines[1] if len(details_lines) > 1 else None

        year = None
        ym = re.search(r"\b(19\d{2}|20\d{2})\b", specs)
        if ym:
            year = int(ym.group(1))
        km = None
        kmm = re.search(r"([\d\.,]+)\s*(km|kms|kil[oó]metros)\b", specs, re.I)
        if kmm:
            km = self._to_int(kmm.group(1))

        price = None
        price_raw = " ".join(card.css(".grid-card-pricing__price ::text").getall()).strip()
        pm = re.search(r"\$\s*([\d,]+)", price_raw)
        if pm:
            price = self._to_int(pm.group(1))  # drop the ".00" cents

        image = card.css("img.card-img-top::attr(src)").get() or card.css("img::attr(src)").get()
        if image:
            image = response.urljoin(image)

        if brand and model:
            path_parts = [self._slug(brand), self._slug(model)]
            if version:
                path_parts.append(self._slug(version))
            path_parts = [p for p in path_parts if p]
            url = "https://www.autocosmos.com.mx/auto/usado/" + "/".join(path_parts + [pub_id])
        else:
            url = f"https://www.autocosmos.com.mx/Publications/Clasified?id={pub_id}"

        details = " | ".join(x for x in [specs, location] if x) or None

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "MX"
        item["url"] = url[:250]
        item["slug"] = "-".join(p for p in [self._slug(title), pub_id] if p)[:255]
        item["title"] = (f"{title} {year}" if year else title)[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = details[:2000] if details else None
        item["price"] = price
        item["year"] = year
        item["km"] = km
        item["external_id"] = pub_id[:50]
        item["brand_name"] = brand
        item["model_name"] = model
        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        return item

    def _slug(self, value):
        import unicodedata

        s = unicodedata.normalize("NFKD", str(value or "")).encode("ascii", "ignore").decode("ascii").lower()
        return re.sub(r"[^a-z0-9]+", "-", s).strip("-")

    def _to_int(self, value):
        if value in (None, ""):
            return None
        d = "".join(ch for ch in str(value) if ch.isdigit())
        return int(d) if d else None


class AutosSeminuevosMexicoSpider(scrapy.Spider):
    name = "seminuevos_mexico"
    allowed_domains = ["www.seminuevos.com", "seminuevos.com"]
    start_urls = ["https://www.seminuevos.com/usados/-/autos"]
    custom_settings = {"ROBOTSTXT_OBEY": False, "DOWNLOAD_DELAY": 1.0}

    max_pages = 250

    def start_requests(self):
        yield scrapy.Request(self.start_urls[0], callback=self.parse, meta={"page_num": 1})

    def parse(self, response):
        import json
        for raw in response.css("script[type='application/ld+json']::text").getall():
            try:
                obj = json.loads(raw)
            except Exception:
                continue
            if not isinstance(obj, dict) or obj.get("@type") != "Car":
                continue
            url = obj.get("url") or ""
            if "seminuevos.com/vehicle/" not in url:
                continue
            title = (obj.get("name") or "").strip()
            if not title:
                continue
            offers = obj.get("offers") or {}
            brand = obj.get("brand") or {}
            mileage = obj.get("mileageFromOdometer") or {}

            item = ListingItem()
            item["vertical"] = "car"
            item["source_name"] = self.name
            item["country"] = "MX"
            item["url"] = url[:250]
            item["slug"] = url.rstrip("/").split("/")[-1][:255]
            item["title"] = title[:250]
            img = obj.get("image")
            if isinstance(img, list):
                img = img[0] if img else None
            item["image"] = img
            item["image_big"] = img
            item["details"] = None
            item["price"] = self._to_int(offers.get("price"))
            item["year"] = self._to_int(obj.get("vehicleModelDate"))
            item["km"] = self._to_int(mileage.get("value"))
            item["external_id"] = item["slug"][:50]
            bname = brand.get("name") if isinstance(brand, dict) else brand
            item["brand_name"] = bname
            item["model_name"] = obj.get("model")
            item["id_state"] = None
            item["id_city"] = None
            item["id_car_make"] = None
            item["id_car_model"] = None
            item["id_fuel"] = None
            item["id_body"] = None
            item["id_transmission"] = None
            yield item

        page_num = int(response.meta.get("page_num", 1))
        if page_num >= self.max_pages:
            return
        # Follow real encoded pagination links discovered in page markup
        for href in response.css("a[href*='/usados/-/autos?page=']::attr(href)").getall():
            yield response.follow(href, callback=self.parse, meta={"page_num": page_num + 1})

    def _to_int(self, value):
        if value in (None, ""):
            return None
        d = "".join(ch for ch in str(value) if ch.isdigit())
        return int(d) if d else None


class AutosKavakMexicoSpider(scrapy.Spider):
    name = "autos_kavak_mexico"
    allowed_domains = ["www.kavak.com", "kavak.com"]
    start_urls = ["https://www.kavak.com/mx/seminuevos"]
    custom_settings = {"ROBOTSTXT_OBEY": False, "DOWNLOAD_DELAY": 1.0}

    max_pages = 120

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        if kwargs.get("max_pages"):
            try:
                self.max_pages = int(kwargs["max_pages"])
            except Exception:
                pass

    def start_requests(self):
        yield scrapy.Request(self.start_urls[0], callback=self.parse, meta={"page_num": 1})

    def parse(self, response):
        import json

        seen = set()
        for raw in response.css("script[type='application/ld+json']::text").getall():
            try:
                obj = json.loads(raw)
            except Exception:
                continue

            objs = obj if isinstance(obj, list) else [obj]
            for it in objs:
                if not isinstance(it, dict):
                    continue
                if it.get("@type") != "Car":
                    continue
                url = (it.get("url") or "").strip()
                if not url or "kavak.com" not in url:
                    continue
                if url in seen:
                    continue
                seen.add(url)

                title = (it.get("name") or "").strip()
                if not title:
                    continue

                offers = it.get("offers") or {}
                mileage = it.get("mileageFromOdometer") or {}
                brand = it.get("brand") or {}
                img = it.get("image")
                if isinstance(img, list):
                    img = img[0] if img else None

                item = ListingItem()
                item["vertical"] = "car"
                item["source_name"] = self.name
                item["country"] = "MX"
                item["url"] = url[:250]
                item["slug"] = url.rstrip("/").split("/")[-1][:255]
                item["title"] = title[:250]
                item["image"] = img
                item["image_big"] = img
                item["details"] = None
                item["price"] = self._to_int(offers.get("price"))
                item["year"] = self._to_int(it.get("vehicleModelDate"))
                item["km"] = self._to_int(mileage.get("value"))
                item["external_id"] = item["slug"][:50]
                bname = brand.get("name") if isinstance(brand, dict) else brand
                item["brand_name"] = bname
                item["model_name"] = it.get("model")
                item["id_state"] = None
                item["id_city"] = None
                item["id_car_make"] = None
                item["id_car_model"] = None
                item["id_fuel"] = None
                item["id_body"] = None
                item["id_transmission"] = None
                yield item

        page_num = int(response.meta.get("page_num", 1))
        if page_num >= self.max_pages:
            return

        next_links = response.css("a[href*='?page=']::attr(href)").getall() + response.css("a[rel='next']::attr(href)").getall()
        for href in next_links:
            yield response.follow(href, callback=self.parse, meta={"page_num": page_num + 1})

    def _to_int(self, value):
        if value in (None, ""):
            return None
        d = "".join(ch for ch in str(value) if ch.isdigit())
        return int(d) if d else None


class AutosCarrosMexicoSpider(scrapy.Spider):
    name = "autos_carros_mexico"
    allowed_domains = ["www.carros.com", "carros.com"]
    start_urls = ["https://www.carros.com/cars-for-sale/mexico-co231/"]
    custom_settings = {"ROBOTSTXT_OBEY": False, "DOWNLOAD_DELAY": 1.0}

    max_pages = 120

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        if kwargs.get("max_pages"):
            try:
                self.max_pages = int(kwargs["max_pages"])
            except Exception:
                pass

    def start_requests(self):
        yield scrapy.Request(self.start_urls[0], callback=self.parse, meta={"page_num": 1})

    def parse(self, response):
        import json

        seen = set()
        for raw in response.css("script[type='application/ld+json']::text").getall():
            try:
                obj = json.loads(raw)
            except Exception:
                continue
            objs = obj if isinstance(obj, list) else [obj]
            for it in objs:
                if not isinstance(it, dict):
                    continue
                if it.get("@type") != "Car":
                    continue
                url = (it.get("url") or "").strip()
                if not url or "carros.com" not in url:
                    continue
                if url in seen:
                    continue
                seen.add(url)

                title = (it.get("name") or "").strip()
                if not title:
                    continue

                offers = it.get("offers") or {}
                mileage = it.get("mileageFromOdometer") or {}
                brand = it.get("brand") or {}
                img = it.get("image")
                if isinstance(img, list):
                    img = img[0] if img else None

                item = ListingItem()
                item["vertical"] = "car"
                item["source_name"] = self.name
                item["country"] = "MX"
                item["url"] = url[:250]
                item["slug"] = url.rstrip("/").split("/")[-1][:255]
                item["title"] = title[:250]
                item["image"] = img
                item["image_big"] = img
                item["details"] = None
                item["price"] = self._to_int(offers.get("price"))
                item["year"] = self._to_int(it.get("vehicleModelDate"))
                item["km"] = self._to_int(mileage.get("value"))
                item["external_id"] = item["slug"][:50]
                bname = brand.get("name") if isinstance(brand, dict) else brand
                item["brand_name"] = bname
                item["model_name"] = it.get("model")
                item["id_state"] = None
                item["id_city"] = None
                item["id_car_make"] = None
                item["id_car_model"] = None
                item["id_fuel"] = None
                item["id_body"] = None
                item["id_transmission"] = None
                yield item

        page_num = int(response.meta.get("page_num", 1))
        if page_num >= self.max_pages:
            return

        next_links = response.css("a[rel='next']::attr(href)").getall() + response.css("a[href*='page=']::attr(href)").getall()
        for href in next_links:
            yield response.follow(href, callback=self.parse, meta={"page_num": page_num + 1})

    def _to_int(self, value):
        if value in (None, ""):
            return None
        d = "".join(ch for ch in str(value) if ch.isdigit())
        return int(d) if d else None


class AutosCarplanetMexicoSpider(scrapy.Spider):
    name = "autos_carplanet_mexico"
    allowed_domains = ["carplanet.mx", "www.carplanet.mx"]
    start_urls = ["https://carplanet.mx/autos/seminuevos"]
    custom_settings = {"ROBOTSTXT_OBEY": True, "DOWNLOAD_DELAY": 1.0}

    max_pages = 1

    FILTER_PATHS = [
        "/autos/seminuevos",
        "/autos/seminuevos?body_style=Suv",
        "/autos/seminuevos?body_style=Sedan",
        "/autos/seminuevos?body_style=PickUp",
        "/autos/seminuevos?body_style=Hatchback",
    ]

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        if kwargs.get("max_pages"):
            try:
                self.max_pages = int(kwargs["max_pages"])
            except Exception:
                pass

    def start_requests(self):
        for p in self.FILTER_PATHS:
            yield scrapy.Request(response_urljoin(self.start_urls[0], p), callback=self.parse_listing)

    def parse_listing(self, response):
        # collect detail URLs from seminuevos cards
        seen = set()
        for href in response.css("a[href*='/auto-seminuevo/']::attr(href)").getall():
            if not href:
                continue
            url = response.urljoin(href).split("#")[0]
            if "/auto-seminuevo/" not in url:
                continue
            if url in seen:
                continue
            seen.add(url)
            yield scrapy.Request(url, callback=self.parse_detail)

    def parse_detail(self, response):
        title = (
            response.css("h1::text").get()
            or response.css("meta[property='og:title']::attr(content)").get()
            or ""
        )
        title = re.sub(r"\s+", " ", title).strip()

        def table_val(label):
            x = response.xpath(
                f"//tr[td[1][contains(normalize-space(), '{label}')]]/td[2]//text()"
            ).getall()
            return re.sub(r"\s+", " ", " ".join(x)).strip() if x else ""

        brand = table_val("Marca")
        model = table_val("Modelo")
        year = self._to_int(table_val("Año"))
        km = self._to_int(table_val("Kilometraje"))
        fuel = table_val("Combustible")
        transmission = table_val("Transmis")

        if not brand or not model:
            # fallback from title: "Marca Modelo Año $..."
            m = re.match(r"^([A-Za-z0-9]+)\s+([A-Za-z0-9\-]+)", title)
            if m:
                brand = brand or m.group(1)
                model = model or m.group(2)

        # prefer visible vehicle price from title/content
        price = None
        h1_price = response.xpath("string(//h1)").get() or ""
        m_price = re.search(r"\$\s*([0-9][0-9\.,]*)", h1_price)
        if not m_price:
            m_price = re.search(r"\$\s*([0-9][0-9\.,]*)", response.text)
        if m_price:
            price = self._to_int(m_price.group(1))

        # avoid favicon/brand assets; keep only car image patterns
        image = (
            response.css("img[src*='/view/img/usedcars/']::attr(src)").get()
            or response.css("img[src*='/view/img/jpgs/']::attr(src)").get()
            or response.css("img[src*='/usedcars/']::attr(src)").get()
        )

        if not title and not image:
            return

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "MX"
        item["url"] = response.url[:250]
        item["slug"] = response.url.rstrip("/").split("/")[-1][:255]
        item["title"] = title[:250] if title else f"{brand} {model}".strip()[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = None
        item["price"] = price
        item["year"] = year
        item["km"] = km
        item["external_id"] = item["slug"][:50]
        item["brand_name"] = brand or None
        item["model_name"] = model or None
        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        if fuel:
            item["details"] = f"fuel:{fuel}"
        if transmission:
            item["details"] = (item["details"] + " | " if item["details"] else "") + f"trans:{transmission}"
        yield item

    def _to_int(self, value):
        if value in (None, ""):
            return None
        d = "".join(ch for ch in str(value) if ch.isdigit())
        if not d:
            return None
        n = int(d)
        if n > 2147483647:
            return None
        return n


class AutosSeminuevosSonoraMexicoSpider(scrapy.Spider):
    name = "autos_seminuevossonora_mexico"
    allowed_domains = ["seminuevossonora.com", "www.seminuevossonora.com"]
    start_urls = ["https://seminuevossonora.com/autos"]
    custom_settings = {"ROBOTSTXT_OBEY": True, "DOWNLOAD_DELAY": 1.0}

    max_pages = 120

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        if kwargs.get("max_pages"):
            try:
                self.max_pages = int(kwargs["max_pages"])
            except Exception:
                pass

    def start_requests(self):
        yield scrapy.Request(self.start_urls[0], callback=self.parse_listing, meta={"page_num": 1})

    def parse_listing(self, response):
        page_num = int(response.meta.get("page_num", 1))

        seen = set()
        for href in response.css("a[href*='/auto/']::attr(href)").getall():
            url = response.urljoin(href).split("#")[0]
            if "/auto/" not in url:
                continue
            if url in seen:
                continue
            seen.add(url)
            yield scrapy.Request(url, callback=self.parse_detail)

        if page_num >= self.max_pages:
            return
        next_href = response.css("a[rel='next']::attr(href)").get()
        if next_href:
            yield response.follow(next_href, callback=self.parse_listing, meta={"page_num": page_num + 1})

    def parse_detail(self, response):
        m = re.search(r'<script id="__NEXT_DATA__" type="application/json">(.*?)</script>', response.text, re.S)
        if not m:
            return
        try:
            data = json.loads(m.group(1))
        except Exception:
            return

        car = (((data.get("props") or {}).get("pageProps") or {}).get("car") or {})
        if not car:
            return

        title = (car.get("title") or "").strip()
        brand = (car.get("brand") or "").strip() or None
        model = None
        if title and brand and title.lower().startswith(brand.lower() + " "):
            model = title[len(brand):].strip() or None

        image = car.get("cover") or car.get("thumbnail")
        imgs = car.get("images") or []
        if not image and imgs:
            image = imgs[0].get("urlImage")

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "MX"
        item["url"] = response.url[:250]
        item["slug"] = response.url.rstrip("/").split("/")[-1][:255]
        item["title"] = title[:250] if title else None
        item["image"] = image
        item["image_big"] = image
        item["details"] = None
        item["price"] = self._to_int(car.get("price"))
        item["year"] = self._to_int(car.get("year"))
        item["km"] = self._to_int(car.get("km"))
        item["external_id"] = str(car.get("id") or item["slug"])[:50]
        item["brand_name"] = brand
        item["model_name"] = model
        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        yield item

    def _to_int(self, value):
        if value in (None, ""):
            return None
        d = "".join(ch for ch in str(value) if ch.isdigit())
        if not d:
            return None
        n = int(d)
        if n > 2147483647:
            return None
        return n


def response_urljoin(base, path):
    from urllib.parse import urljoin
    return urljoin(base, path)


class AutosRoodosPeSpider(BaseRoodosSpider):
    name = "autos_roodos_pe"
    vertical = "car"
    country = "PE"
    base_domain = "https://autos.roodos.pe"


class AutosRoodosMxSpider(BaseRoodosSpider):
    name = "autos_roodos_mx"
    vertical = "car"
    country = "MX"
    base_domain = "https://autos.roodos.mx"


class CasasRoodosClSpider(BaseRoodosSpider):
    name = "casas_roodos_cl"
    vertical = "house"
    country = "CL"
    base_domain = "https://casas.roodos.cl"


class CasasRoodosEcSpider(BaseRoodosSpider):
    name = "casas_roodos_ec"
    vertical = "house"
    country = "EC"
    base_domain = "https://casas.roodos.ec"


class CasasRoodosPeSpider(BaseRoodosSpider):
    name = "casas_roodos_pe"
    vertical = "house"
    country = "PE"
    base_domain = "https://casas.roodos.pe"


class CasasRoodosMxSpider(BaseRoodosSpider):
    name = "casas_roodos_mx"
    vertical = "house"
    country = "MX"
    base_domain = "https://casas.roodos.mx"


class AutosAutolandPeSpider(scrapy.Spider):
    name = "autos_autoland_pe"
    allowed_domains = ["www.autoland.com.pe", "autoland.com.pe"]
    start_urls = ["https://www.autoland.com.pe/autos-usados-peru"]
    custom_settings = {"ROBOTSTXT_OBEY": False, "DOWNLOAD_DELAY": 1.0}

    def parse(self, response):
        seen = set()
        for href in response.css("a[href*='/autos-usados-peru/']::attr(href)").getall():
            url = response.urljoin(href).split("#")[0]
            if url in seen:
                continue
            seen.add(url)
            yield scrapy.Request(url, callback=self.parse_detail)

    def parse_detail(self, response):
        product = {}
        for raw in response.css("script[type='application/ld+json']::text").getall():
            try:
                obj = json.loads(raw)
            except Exception:
                continue
            if isinstance(obj, dict) and obj.get("@type") == "Product":
                product = obj
                break

        title = (
            response.css("h1::text").get()
            or response.css("meta[property='og:title']::attr(content)").get()
            or product.get("name")
            or ""
        )
        title = re.sub(r"\s+", " ", title).strip()
        if not title:
            return

        image = response.css("meta[property='og:image']::attr(content)").get() or product.get("image")
        if isinstance(image, list):
            image = image[0] if image else None
        if image:
            image = response.urljoin(image)

        text = " ".join(response.css("body *::text").getall())
        text = re.sub(r"\s+", " ", text).strip()
        offers = product.get("offers") or {}

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "PE"
        item["url"] = response.url[:250]
        item["slug"] = response.url.rstrip("/").split("/")[-1][:255]
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = text[:1200] if text else None
        item["price"] = self._to_int(offers.get("price")) or self._price_from_text(text)
        item["year"] = self._extract_year(title or text)
        item["km"] = self._km_from_text(text)
        item["external_id"] = item["slug"][:50]
        item["brand_name"], item["model_name"] = self._brand_model(title)
        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        yield item

    def _brand_model(self, title):
        parts = (title or "").split()
        return (parts[0] if parts else None, " ".join(parts[1:3]) if len(parts) > 1 else None)

    def _extract_year(self, text):
        m = re.search(r"\b(19[8-9]\d|20[0-3]\d)\b", text or "")
        return int(m.group(1)) if m else None

    def _km_from_text(self, text):
        m = re.search(r"([\d.,]+)\s*km\b", text or "", re.I)
        return self._to_int(m.group(1)) if m else None

    def _price_from_text(self, text):
        m = re.search(r"(?:US\$|USD|\$|S/\.?)\s*([\d.,]+)", text or "", re.I)
        return self._to_int(m.group(1)) if m else None

    def _to_int(self, value):
        if value in (None, ""):
            return None
        d = "".join(ch for ch in str(value) if ch.isdigit())
        return int(d) if d else None


class AutosNeoautoPeSpider(scrapy.Spider):
    name = "autos_neoauto_pe"
    allowed_domains = ["neoauto.com", "www.neoauto.com"]
    start_urls = [
        "https://neoauto.com/venta-de-autos",
        "https://neoauto.com/venta-de-autos--camionetas-suv",
        "https://neoauto.com/venta-de-autos--sedan",
        "https://neoauto.com/venta-de-autos--hatchback",
        "https://neoauto.com/venta-de-autos--pick-up",
    ]
    custom_settings = {"ROBOTSTXT_OBEY": True, "DOWNLOAD_DELAY": 1.0}

    def parse(self, response):
        seen = set()
        for href in response.css("a[href*='auto/seminuevo/']::attr(href), a[href*='/auto/usado/']::attr(href)").getall():
            url = response.urljoin(href).split("#")[0]
            if url in seen:
                continue
            seen.add(url)
            yield scrapy.Request(url, callback=self.parse_detail)

    def parse_detail(self, response):
        title = (
            response.css("h1::text").get()
            or response.css("meta[property='og:title']::attr(content)").get()
            or ""
        )
        title = re.sub(r"\s+", " ", title).strip()
        if not title:
            return

        image = response.css("meta[property='og:image']::attr(content)").get()
        text = " ".join(response.css("body *::text").getall())
        text = re.sub(r"\s+", " ", text).strip()

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "PE"
        item["url"] = response.url[:250]
        item["slug"] = response.url.rstrip("/").split("/")[-1][:255]
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = text[:1200] if text else None
        item["price"] = self._price_from_text(text)
        item["year"] = self._extract_year(title or text)
        item["km"] = self._km_from_text(text)
        item["external_id"] = item["slug"][:50]
        item["brand_name"], item["model_name"] = self._brand_model(title)
        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        yield item

    def _brand_model(self, title):
        parts = (title or "").split()
        return (parts[0] if parts else None, " ".join(parts[1:3]) if len(parts) > 1 else None)

    def _extract_year(self, text):
        m = re.search(r"\b(19[8-9]\d|20[0-3]\d)\b", text or "")
        return int(m.group(1)) if m else None

    def _km_from_text(self, text):
        m = re.search(r"([\d.,]+)\s*km\b", text or "", re.I)
        return self._to_int(m.group(1)) if m else None

    def _price_from_text(self, text):
        m = re.search(r"(?:Precio:)?\s*(?:US\$|USD|\$|S/\.?)\s*([\d.,]+)", text or "", re.I)
        return self._to_int(m.group(1)) if m else None

    def _to_int(self, value):
        if value in (None, ""):
            return None
        d = "".join(ch for ch in str(value) if ch.isdigit())
        return int(d) if d else None


class SimplePeruCarDetailSpider(scrapy.Spider):
    custom_settings = {
        "ROBOTSTXT_OBEY": False,
        "DOWNLOAD_DELAY": 1.0,
        "DOWNLOADER_MIDDLEWARES": {
            "roodos_scraper.middlewares.WebshareProxyRotationMiddleware": None,
        },
    }
    country = "PE"
    link_regexes = []

    def parse(self, response):
        seen = set()
        for href in response.css("a::attr(href)").getall():
            if not href:
                continue
            url = response.urljoin(href).split("#")[0]
            if "?" in url:
                url = url.split("?", 1)[0]
            if url in seen:
                continue
            if not self._is_detail_url(url):
                continue
            seen.add(url)
            yield scrapy.Request(url, callback=self.parse_detail)

    def parse_detail(self, response):
        product = self._product_json(response)
        title = (
            response.css("h1::text").get()
            or response.css("meta[property='og:title']::attr(content)").get()
            or product.get("name")
            or response.css("title::text").get()
            or ""
        )
        title = re.sub(r"\s+", " ", title).strip()
        if not title:
            return

        image = response.css("meta[property='og:image']::attr(content)").get() or product.get("image")
        if isinstance(image, list):
            image = image[0] if image else None
        if image:
            image = response.urljoin(str(image))

        text = " ".join(response.css("body *::text").getall())
        text = re.sub(r"\s+", " ", text).strip()
        offers = product.get("offers") or {}
        mileage = product.get("mileageFromOdometer") or {}
        brand = product.get("brand") or {}

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "PE"
        item["url"] = response.url[:250]
        item["slug"] = response.url.rstrip("/").split("/")[-1][:255]
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = text[:1200] if text else None
        item["price"] = self._to_int(offers.get("price")) or self._price_from_text(text)
        item["year"] = self._to_int(product.get("vehicleModelDate")) or self._extract_year(title or text)
        item["km"] = self._to_int(mileage.get("value") if isinstance(mileage, dict) else None) or self._km_from_text(text)
        item["external_id"] = item["slug"][:50]
        bname = brand.get("name") if isinstance(brand, dict) else brand
        item["brand_name"], item["model_name"] = self._brand_model(title, bname)
        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        yield item

    def _is_detail_url(self, url):
        return any(re.search(rx, url, re.I) for rx in self.link_regexes)

    def _product_json(self, response):
        for raw in response.css("script[type='application/ld+json']::text").getall():
            try:
                obj = json.loads(raw)
            except Exception:
                continue
            stack = obj if isinstance(obj, list) else [obj]
            if isinstance(obj, dict) and isinstance(obj.get("@graph"), list):
                stack += obj["@graph"]
            for it in stack:
                if not isinstance(it, dict):
                    continue
                typ = it.get("@type")
                if typ in ("Product", "Car", "Vehicle") or (isinstance(typ, list) and any(t in typ for t in ("Product", "Car", "Vehicle"))):
                    return it
        return {}

    def _brand_model(self, title, brand_name=None):
        parts = (title or "").split()
        brand = str(brand_name).strip() if brand_name else (parts[0] if parts else None)
        model_parts = parts[1:3] if brand and parts and parts[0].lower() == str(brand).lower() else parts[:2]
        return brand, " ".join(model_parts) if model_parts else None

    def _extract_year(self, text):
        years = [int(y) for y in re.findall(r"\b(19[8-9]\d|20[0-3]\d)\b", text or "")]
        return years[0] if years else None

    def _km_from_text(self, text):
        m = re.search(r"([\d.,]+)\s*k(?:m|ilometros)\b", text or "", re.I)
        return self._to_int(m.group(1)) if m else None

    def _price_from_text(self, text):
        m = re.search(r"(?:US\$|USD|\$|S/\.?)\s*([\d.,]+)", text or "", re.I)
        return self._to_int(m.group(1)) if m else None

    def _to_int(self, value):
        if value in (None, ""):
            return None
        d = "".join(ch for ch in str(value) if ch.isdigit())
        return int(d) if d else None


class AutosTuautoPeSpider(SimplePeruCarDetailSpider):
    name = "autos_tuauto_pe"
    allowed_domains = ["tuauto.com.pe"]
    start_urls = [
        "https://tuauto.com.pe/venta-de-autos",
        "https://tuauto.com.pe/venta-de-autos/seminuevos",
        "https://tuauto.com.pe/venta-de-autos/usados",
    ]
    link_regexes = [r"/auto/[^/]+/\d+$"]


class AutosAutosellPeSpider(SimplePeruCarDetailSpider):
    name = "autos_autosell_pe"
    allowed_domains = ["www.autosell.pe", "autosell.pe"]
    start_urls = ["https://www.autosell.pe/compra-tu-auto"]
    link_regexes = [r"/info-auto/\d+$"]


class AutosWigoPeSpider(SimplePeruCarDetailSpider):
    name = "autos_wigo_pe"
    allowed_domains = ["www.wigomotors.com", "wigomotors.com"]
    start_urls = ["https://www.wigomotors.com/vehiculos/usado"]
    link_regexes = [r"/marcas/[^/]+/usado/[^/]+-\d+$"]


class AutosLeonfordPeSpider(SimplePeruCarDetailSpider):
    name = "autos_leonford_pe"
    allowed_domains = ["www.leonford.pe", "leonford.pe"]
    start_urls = ["https://www.leonford.pe/vehiculos/buscar"]
    link_regexes = [r"/vehiculos/stock/\d+/\d+/[^/]+$"]


class AutosAutosummitPeSpider(SimplePeruCarDetailSpider):
    name = "autos_autosummit_pe"
    allowed_domains = ["www.autosummitford.pe", "autosummitford.pe"]
    start_urls = ["https://www.autosummitford.pe/vehiculos/buscar"]
    link_regexes = [r"/vehiculos/stock/\d+/\d+/[^/]+$"]


class AutosAutocosmosPeruSpider(scrapy.Spider):
    name = "autocosmos_peru"
    allowed_domains = ["www.autocosmos.com.pe", "autocosmos.com.pe"]
    sitemap_url = "https://www.autocosmos.com.pe/sitemap.xml"
    custom_settings = {
        "ROBOTSTXT_OBEY": True,
        "DOWNLOAD_DELAY": 0.5,
        "DOWNLOADER_MIDDLEWARES": {
            "roodos_scraper.middlewares.WebshareProxyRotationMiddleware": None,
        },
    }

    detail_re = re.compile(r"/catalogo/(\d{4})/([^/]+)/([^/]+)/(.+)/(\d+)$")

    def start_requests(self):
        yield scrapy.Request(self.sitemap_url, callback=self.parse_sitemap)

    def parse_sitemap(self, response):
        from xml.etree import ElementTree as ET

        root = ET.fromstring(response.body)
        ns = {"sm": "http://www.sitemaps.org/schemas/sitemap/0.9"}
        for loc in root.findall(".//sm:loc", ns):
            url = (loc.text or "").strip()
            if not url or "/catalogo/" not in url:
                continue
            if not self.detail_re.search(urlparse(url).path):
                continue
            yield scrapy.Request(url, callback=self.parse_detail)

    def parse_detail(self, response):
        match = self.detail_re.search(urlparse(response.url).path)
        if not match:
            return
        year_txt, brand_slug, model_slug, version_slug, external_id = match.groups()

        title = (
            response.css("h1::text").get()
            or response.css("meta[name='description']::attr(content)").get()
            or response.css("title::text").get()
            or ""
        )
        title = re.sub(r"\s+", " ", title).strip()
        if not title:
            return

        # Catalog image placeholders are not stable raster assets; skip thumbnailing.
        image = None

        text = " ".join(response.css("body *::text").getall())
        text = re.sub(r"\s+", " ", text).strip()

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "PE"
        item["url"] = response.url[:250]
        item["slug"] = response.url.rstrip("/").split("/")[-1][:255]
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = text[:1200] if text else None
        item["price"] = self._price_from_text(text)
        item["year"] = int(year_txt)
        item["km"] = None
        item["external_id"] = external_id[:50]
        item["brand_name"] = brand_slug.replace("-", " ")
        item["model_name"] = model_slug.replace("-", " ")
        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = None
        item["id_body"] = None
        item["id_transmission"] = None
        yield item

    def _price_from_text(self, text):
        m = re.search(r"(?:US\$|USD|\$|S/\.?)\s*([\d.,]+)", text or "", re.I)
        if not m:
            return None
        digits = "".join(ch for ch in m.group(1) if ch.isdigit())
        return int(digits) if digits else None


class AutosPeruautosPeSpider(scrapy.Spider):
    """
    Peru autos source:
    https://www.peruautos.pe/auto/

    WordPress/GeoDirectory listing pages with detail URLs under /auto/<slug>/
    """

    name = "autos_peruautos_pe"
    allowed_domains = ["www.peruautos.pe", "peruautos.pe"]
    base_url = "https://www.peruautos.pe/auto/"

    custom_settings = {
        "ROBOTSTXT_OBEY": True,
        "DOWNLOAD_DELAY": 1.0,
    }

    max_pages = 300

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        if kwargs.get("max_pages"):
            try:
                self.max_pages = int(kwargs["max_pages"])
            except Exception:
                pass

    def start_requests(self):
        yield scrapy.Request(self.base_url, callback=self.parse_listing, meta={"page_num": 1})

    def parse_listing(self, response):
        page_num = int(response.meta.get("page_num", 1))

        detail_urls = set()
        for href in response.css("a::attr(href)").getall():
            if not href:
                continue
            url = response.urljoin(href).split("#")[0]
            if not url.startswith("https://www.peruautos.pe/auto/"):
                continue
            tail = url.replace("https://www.peruautos.pe/auto/", "", 1).strip("/")
            if not tail or tail.startswith("page/") or tail.startswith("category/"):
                continue
            detail_urls.add(url if url.endswith("/") else url + "/")

        for url in sorted(detail_urls):
            yield scrapy.Request(url, callback=self.parse_detail)

        # follow pagination /auto/page/N/
        next_page = page_num + 1
        if next_page <= self.max_pages and detail_urls:
            next_url = f"{self.base_url}page/{next_page}/"
            yield scrapy.Request(next_url, callback=self.parse_listing, meta={"page_num": next_page})

    def parse_detail(self, response):
        # basic title
        title = (
            response.css("h1.geodir-entry-title::text").get()
            or response.css("h1::text").get()
            or response.css("meta[property='og:title']::attr(content)").get()
            or ""
        )
        title = re.sub(r"\s+", " ", title).strip()

        # price and specs from geodir fields
        price_txt = response.css(".geodir-field-price::text").get() or ""
        km_txt = response.css(".geodir-field-kilometraje::text").get() or ""
        city_txt = response.css(".geodir-field-city::text").get() or ""
        fuel_txt = response.css(".geodir-field-combustible::text").get() or ""
        seller_txt = response.css(".geodir-field-tipo_de_vendedor::text").get() or ""

        # image
        image = (
            response.css("meta[property='og:image']::attr(content)").get()
            or response.css(".geodir-post-images img::attr(src)").get()
            or response.css("img::attr(src)").get()
        )

        # description
        desc_parts = [
            re.sub(r"\s+", " ", t).strip()
            for t in response.css(".geodir-field-post_content *::text").getall()
            if t and t.strip()
        ]
        desc_text = " ".join(desc_parts[:40])

        # derive brand/model/year from title
        year = self._extract_year(title)
        brand_name, model_name = self._extract_brand_model(title)

        detail_bits = [x for x in [city_txt.strip(), fuel_txt.strip(), seller_txt.strip()] if x]
        if desc_text:
            detail_bits.append(desc_text[:250])

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "PE"
        item["url"] = response.url
        item["slug"] = response.url.rstrip("/").split("/")[-1]
        item["title"] = (title or item["slug"] or "Sin título")[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = " | ".join(detail_bits)[:1000] if detail_bits else None
        item["price"] = self._to_int(price_txt)
        item["year"] = year
        item["km"] = self._to_int(km_txt)
        item["external_id"] = self._extract_external_id(response.text, response.url)

        item["brand_name"] = brand_name
        item["model_name"] = model_name
        item["id_state"] = None
        item["id_city"] = None
        item["id_car_make"] = None
        item["id_car_model"] = None
        item["id_fuel"] = self._map_fuel(fuel_txt)
        item["id_body"] = None
        item["id_transmission"] = None
        yield item

    def _extract_external_id(self, html, url):
        m = re.search(r"postid-(\d+)", html or "")
        if m:
            return m.group(1)
        # fallback stable-ish id from slug
        return url.rstrip("/").split("/")[-1]

    def _extract_year(self, text):
        if not text:
            return None
        m = re.search(r"\b(19\d{2}|20\d{2})\b", text)
        return int(m.group(1)) if m else None

    def _extract_brand_model(self, text):
        t = (text or "").strip()
        if not t:
            return None, None
        t = re.sub(r"\b(19\d{2}|20\d{2})\b", "", t)
        parts = [p for p in re.split(r"\s+", t) if p]
        if not parts:
            return None, None
        brand = parts[0]
        model = " ".join(parts[1:]) if len(parts) > 1 else None
        return brand[:80], model[:120] if model else None

    def _to_int(self, value):
        if value in (None, ""):
            return None
        digits = "".join(ch for ch in str(value) if ch.isdigit())
        return int(digits) if digits else None

    def _map_fuel(self, value):
        t = (value or "").lower()
        if "gasolina" in t or "bencina" in t:
            return 1
        if "diesel" in t or "diésel" in t:
            return 2
        if "elect" in t:
            return 3
        if "hibr" in t:
            return 4
        if "glp" in t or "gnv" in t:
            return 5
        return None
