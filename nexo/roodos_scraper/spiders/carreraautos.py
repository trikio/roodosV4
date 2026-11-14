"""Carrera Autos (Ecuador, Quito): ~54 autos en nov-2026.

Es una tienda WooCommerce sin datos de schema.org de auto en la ficha, así que el spider genérico no
la lee. La Store API pública (`/wp-json/wc/store/v1/products`) trae el stock completo, y la
descripción de cada producto lista los datos con etiqueta: "Año : 2025 Marca : Mercedes Benz
Modelo : GLA250 ... Recorrido : 4400 kms ... Transmisión : Automática ... Combustible : Gasolina".
El precio viene en la unidad menor (centavos): `prices.price` / 10**currency_minor_unit.
"""

import html
import json
import re

import scrapy

from roodos_scraper.items import ListingItem

SITE = "https://carreraautos.com"
API = SITE + "/wp-json/wc/store/v1/products?per_page=100&page={page}"
LABELS = ("Año", "Marca", "Modelo", "Subtipo", "Recorrido", "Tracción", "Color", "Transmisión", "Combustible",
          "Motor (Cilindraje)", "Dirección", "Tapizado", "Vidrios", "Sistema de climatización", "Sistema de catización")


class AutosCarreraautosEcSpider(scrapy.Spider):
    name = "autos_carreraautos_ec"
    allowed_domains = ["carreraautos.com"]
    custom_settings = {"ROBOTSTXT_OBEY": True, "DOWNLOAD_DELAY": 1.0}

    def start_requests(self):
        yield scrapy.Request(API.format(page=1), callback=self.parse, cb_kwargs={"page": 1})

    def parse(self, response, page):
        products = json.loads(response.text)
        self.logger.info("Carrera Autos: página %d, %d productos", page, len(products))
        for product in products:
            item = self._item(product)
            if item:
                yield item
        if len(products) == 100:
            yield scrapy.Request(API.format(page=page + 1), callback=self.parse, cb_kwargs={"page": page + 1})

    def _fields(self, product):
        text = html.unescape(re.sub(r"<[^>]+>", " ", f"{product.get('short_description', '')} {product.get('description', '')}"))
        text = re.sub(r"\s+", " ", text)
        labels = "|".join(re.escape(label) for label in LABELS)
        return {m.group(1): m.group(2).strip() for m in re.finditer(rf"({labels})\s*:\s*(.*?)(?=\s(?:{labels})\s*:|$)", text)}

    def _item(self, product):
        if not product.get("is_in_stock", True):
            return None
        prices = product.get("prices") or {}
        if (prices.get("currency_code") or "USD") != "USD":
            return None
        try:
            price = int(prices.get("price") or 0) // 10 ** int(prices.get("currency_minor_unit") or 0)
        except ValueError:
            return None
        data = self._fields(product)
        year = int(data["Año"]) if re.fullmatch(r"(19|20)\d\d", data.get("Año", "")) else None
        if price < 500 or not year:
            return None
        km = re.sub(r"\D", "", data.get("Recorrido", ""))
        brand, model = data.get("Marca"), data.get("Modelo")
        name = re.sub(r"\s+", " ", html.unescape(product.get("name") or "")).strip()
        title = f"{brand} {model}" if brand and model else name
        images = product.get("images") or []
        image = images[0].get("src") if images else None
        details = [data.get("Subtipo"), data.get("Transmisión"), data.get("Combustible"), data.get("Tracción"),
                   data.get("Color"), "USD"]

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["source_label"] = "Carrera Autos"
        item["country"] = "EC"
        item["url"] = (product.get("permalink") or "")[:250]
        item["slug"] = (product.get("slug") or "")[:255]
        item["title"] = f"{title} {year}"[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = " | ".join(d for d in details if d)
        item["price"] = price
        item["year"] = year
        item["km"] = int(km) if km else None
        item["external_id"] = str(product.get("id"))[:50]
        item["brand_name"] = brand
        item["model_name"] = model
        for key in ("id_state", "id_city", "id_car_make", "id_car_model", "id_fuel", "id_body", "id_transmission"):
            item[key] = None
        return item if item["url"] else None
