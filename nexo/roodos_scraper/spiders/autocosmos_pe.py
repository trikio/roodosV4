"""Autocosmos Perú: autos usados de https://www.autocosmos.com.pe/auto/usado

El spider anterior recorría /catalogo/ (las fichas técnicas de autos nuevos), así que guardaba el menú
de la página en vez de avisos. Este lee las tarjetas del listado de usados (`article.listing-card`,
schema.org/Car): marca, modelo y versión por separado, año, km, ciudad y región, y el precio con su
moneda (u$s o soles).

Paginación: ?pidx=N con un enlace rel="next". Se pide ordenado por precio (sort=8) para que el orden
sea estable entre páginas. robots.txt pide Crawl-delay: 20.
"""

import re

import scrapy

from roodos_scraper.items import ListingItem

SITE = "https://www.autocosmos.com.pe"
START = SITE + "/auto/usado?sort=8"


class AutosAutocosmosPeruSpider(scrapy.Spider):
    name = "autocosmos_peru"
    allowed_domains = ["www.autocosmos.com.pe", "autocosmos.com.pe"]
    custom_settings = {
        "ROBOTSTXT_OBEY": True,
        "DOWNLOAD_DELAY": 20,
        "RANDOMIZE_DOWNLOAD_DELAY": False,  # el aleatorio baja la espera hasta la mitad
        "AUTOTHROTTLE_ENABLED": False,
        "CONCURRENT_REQUESTS_PER_DOMAIN": 1,
        "DOWNLOADER_MIDDLEWARES": {"roodos_scraper.middlewares.WebshareProxyRotationMiddleware": None},
    }

    def start_requests(self):
        yield scrapy.Request(START, callback=self.parse, cb_kwargs={"page": 1})

    def parse(self, response, page):
        cards = response.css("article.listing-card")
        self.logger.info("Autocosmos PE: página %d, %d avisos", page, len(cards))
        for card in cards:
            item = self._item(response, card)
            if item:
                yield item
        next_href = response.css("a[rel='next']::attr(href)").get()
        if cards and next_href:
            url = response.urljoin(next_href)
            if "sort=" not in url:
                url += ("&" if "?" in url else "?") + "sort=8"
            yield scrapy.Request(url, callback=self.parse, cb_kwargs={"page": page + 1})

    @staticmethod
    def _text(card, selector):
        return re.sub(r"\s+", " ", " ".join(card.css(selector).getall())).strip(" |")

    def _item(self, response, card):
        href = card.css("a[itemprop='url']::attr(href)").get()
        if not href:
            return None
        url = response.urljoin(href)
        m_id = re.search(r"/([a-f0-9]{16,})$", url)
        brand = self._text(card, ".listing-card__brand::text")
        model = self._text(card, ".listing-card__model::text")
        version = self._text(card, ".listing-card__version::text")
        year_txt = self._text(card, ".listing-card__year::text")
        year = int(year_txt) if re.fullmatch(r"(19|20)\d\d", year_txt) else None
        km_txt = card.css(".listing-card__km::attr(content)").get() or self._text(card, ".listing-card__km::text")
        km = int(re.sub(r"\D", "", km_txt)) if re.search(r"\d", km_txt or "") else None
        city = self._text(card, ".listing-card__city::text")
        province = self._text(card, ".listing-card__province::text")
        price_txt = card.css(".listing-card__price-value::attr(content)").get() or ""
        price = int(re.sub(r"\D", "", price_txt)) if re.search(r"\d", price_txt) else None
        currency = (card.css("[itemprop='priceCurrency']::attr(content)").get() or "").upper()
        if not currency:
            shown = self._text(card, ".listing-card__price-value::text").lower()
            currency = "USD" if "u$s" in shown or "us$" in shown else "PEN"
        if not (brand and model and year and price):
            return None
        image = card.css("img[itemprop='image']::attr(content)").get() or card.css("img::attr(src)").get()
        location = " | ".join(x for x in (city, province) if x)
        title = " ".join(x for x in (brand, model, version, str(year)) if x)

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["source_label"] = "Autocosmos"
        item["country"] = "PE"
        item["url"] = url[:250]
        item["slug"] = url.rstrip("/").split("/")[-1][:255]
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = " | ".join(x for x in (f"{km} km" if km is not None else None, location, currency) if x)
        item["price"] = price
        item["year"] = year
        item["km"] = km
        item["external_id"] = (m_id.group(1) if m_id else item["slug"])[:50]
        item["brand_name"] = brand
        item["model_name"] = model
        item["version"] = version or None
        item["location"] = location or None
        for key in ("id_state", "id_city", "id_car_make", "id_car_model", "id_fuel", "id_body", "id_transmission"):
            item[key] = None
        return item
