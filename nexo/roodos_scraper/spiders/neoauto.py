"""Neoauto (Perú), el clasificado de autos más grande del país.

Los listados paginan con `?page=N`, que robots.txt prohíbe (`Disallow: /*?`), y solo traen unas pocas
fichas en el HTML. Los sitemaps de avisos sí están permitidos y listan todos los usados y seminuevos
(~4.200 en nov-2026), así que se recorren completos. Cada ficha publica un Product de schema.org con
precio, moneda, marca, modelo, km, año, carrocería, transmisión, combustible y ubicación.
"""

import json
import re

import scrapy

from roodos_scraper.items import ListingItem

SITEMAPS = [
    "https://neoauto.com/sitemap-avisos-autos-usados.xml",
    "https://neoauto.com/sitemap-avisos-autos-seminuevos.xml",
]
DETAIL = re.compile(r"^https://neoauto\.com/auto/(usado|seminuevo)/[a-z0-9-]+-(\d+)$")
CURRENCIES = {"USD", "PEN"}


class AutosNeoautoPeSpider(scrapy.Spider):
    name = "autos_neoauto_pe"
    allowed_domains = ["neoauto.com"]
    custom_settings = {"ROBOTSTXT_OBEY": True, "DOWNLOAD_DELAY": 0.5, "CONCURRENT_REQUESTS_PER_DOMAIN": 4}

    def start_requests(self):
        for url in SITEMAPS:
            yield scrapy.Request(url, callback=self.parse_sitemap)

    def parse_sitemap(self, response):
        response.selector.remove_namespaces()
        urls = response.xpath("//url/loc/text()").getall()
        self.logger.info("%s: %d avisos", response.url, len(urls))
        for url in urls:
            url = url.strip()
            if DETAIL.match(url):
                yield scrapy.Request(url, callback=self.parse_detail)

    def parse_detail(self, response):
        match = DETAIL.match(response.url)
        product = self._product(response)
        if not match or not product:
            return
        offers = product.get("offers") or {}
        if isinstance(offers, list):
            offers = offers[0] if offers else {}
        if "InStock" not in str(offers.get("availability", "InStock")):
            return
        currency = str(offers.get("priceCurrency") or "").upper()
        price = self._to_int(offers.get("price"))
        if not price or price < 500 or currency not in CURRENCIES:
            return

        props = {p.get("name"): p.get("value") for p in product.get("additionalProperty") or [] if isinstance(p, dict)}
        title = re.sub(r"\s+", " ", str(product.get("name") or "")).strip()
        year = self._to_int(props.get("Año del Modelo") or props.get("Año de Fabricación"))
        if not title or not year:
            return

        brand = product.get("brand")
        brand = brand.get("name") if isinstance(brand, dict) else brand
        model = str(product.get("model") or "")
        # Neoauto repite la marca en el modelo ("MG MG ZX", "MG ZX"); el modelo va sin ella.
        if brand and model.lower().startswith(str(brand).lower() + " "):
            model = model[len(brand) + 1:]

        address = (offers.get("seller") or {}).get("address") or {}
        images = product.get("image") or []
        image = images[0] if isinstance(images, list) and images else images or None
        details = [props.get("Tipo de Carrocería"), props.get("Transmisión"), props.get("Combustible"),
                   address.get("addressLocality"), address.get("addressRegion"), currency]

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["country"] = "PE"
        item["url"] = response.url[:250]
        item["slug"] = response.url.rsplit("/", 1)[-1][:255]
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = " | ".join(str(d) for d in details if d) or None
        item["price"] = price
        item["year"] = year
        item["km"] = self._to_int(props.get("Kilometraje"))
        item["external_id"] = match.group(2)
        item["brand_name"] = brand
        item["model_name"] = model or None
        for key in ("id_state", "id_city", "id_car_make", "id_car_model", "id_fuel", "id_body", "id_transmission"):
            item[key] = None
        yield item

    def _product(self, response):
        for raw in response.css("script[type='application/ld+json']::text").getall():
            try:
                data = json.loads(raw)
            except ValueError:
                continue
            for node in data.get("@graph", [data]) if isinstance(data, dict) else data:
                if isinstance(node, dict) and node.get("@type") in ("Product", "Car", "Vehicle"):
                    return node
        return None

    def _to_int(self, value):
        if isinstance(value, (int, float)):
            return int(value)
        if re.fullmatch(r"\s*\d+(\.\d+)?\s*", str(value or "")):
            return int(float(value))
        digits = re.sub(r"\D", "", str(value or ""))
        return int(digits) if digits else None
