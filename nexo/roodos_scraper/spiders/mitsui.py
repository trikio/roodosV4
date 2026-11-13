"""Mitsui Seminuevos (Perú): seminuevos de Mitsui Automotriz en Lima y Arequipa (~90 en nov-2026).

La web es una SPA en React sin fichas en el HTML. Los autos vienen de su API: un POST a
`manager/api/filtrar-autos` con `{}` devuelve el catálogo completo en una sola respuesta. La API
contesta "Acceso no autorizado" si no va el Referer del propio sitio.

`precio-original` es el precio en dólares; `precio` y `precio-soles` son ese mismo valor por 4.
"""

import json

import scrapy

from roodos_scraper.items import ListingItem

SITE = "https://mitsuiseminuevos.com/"
API = SITE + "manager/api/filtrar-autos"
FUELS = {"G": "Gasolina", "H": "Híbrido", "D": "Diésel", "GNV": "GNV", "GLP": "GLP", "E": "Eléctrico"}
TRANSMISSIONS = {"AT": "Automática", "CVT": "Automática CVT", "MT": "Mecánica"}


class AutosMitsuiPeSpider(scrapy.Spider):
    name = "autos_mitsui_pe"
    allowed_domains = ["mitsuiseminuevos.com"]
    custom_settings = {"ROBOTSTXT_OBEY": True, "DOWNLOAD_DELAY": 10}  # Crawl-delay: 10 en robots.txt

    def start_requests(self):
        yield scrapy.Request(
            API, method="POST", body="{}", callback=self.parse,
            headers={"Content-Type": "application/json", "Accept": "application/json",
                     "Referer": SITE + "compra-un-auto", "Origin": SITE.rstrip("/")},
        )

    def parse(self, response):
        cars = json.loads(response.text)
        if not isinstance(cars, list):
            raise ValueError(f"respuesta inesperada de la API: {response.text[:200]}")
        self.logger.info("Mitsui: %d autos en la API", len(cars))
        for car in cars:
            item = self._item(car)
            if item:
                yield item

    def _item(self, car):
        slug = (car.get("url") or "").strip()
        price = car.get("precio-oferta") or car.get("precio-original")
        year = car.get("ano")
        if not slug or not price or not year:
            return None
        brand = (car.get("marca") or "").strip()
        title = (car.get("titulo") or "").strip()
        # Algunos títulos no traen la marca ("C5 AIRCROSS 1.6 THP", "X2 XDRIVE20I").
        if brand and not title.upper().startswith(brand.upper()):
            title = f"{brand} {title}"
        city, _, district = (car.get("ubicacion") or "").partition(" / ")
        images = car.get("imgs") or []
        image = SITE + images[0].lstrip("/") if images else None
        details = [TRANSMISSIONS.get(car.get("transmision"), car.get("transmision")),
                   FUELS.get(car.get("combustible"), car.get("combustible")), car.get("color"),
                   district, city, "Certificado" if car.get("certificado") else None, "USD"]

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["source_label"] = "Mitsui"
        item["country"] = "PE"
        item["url"] = f"{SITE}compra-un-auto/{slug}"[:250]
        item["slug"] = slug[:255]
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = " | ".join(str(d) for d in details if d)
        item["price"] = int(price)
        item["year"] = int(year)
        item["km"] = int(car["kilometraje"]) if car.get("kilometraje") is not None else None
        item["external_id"] = str(car.get("id") or slug)[:50]
        item["brand_name"] = brand or None
        item["model_name"] = (car.get("modelo") or "").strip() or None
        for key in ("id_state", "id_city", "id_car_make", "id_car_model", "id_fuel", "id_body", "id_transmission"):
            item[key] = None
        return item
