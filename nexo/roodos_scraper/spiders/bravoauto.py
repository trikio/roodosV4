"""Bravoauto (Perú): seminuevos de Inchcape (~30 en nov-2026).

`/buy/` trae el stock completo en el HTML: una tarjeta por auto con los datos en atributos
`data-vehicle-*` (marca, modelo, año, km, precio en dólares, combustible...). La paginación y los
filtros se hacen en el navegador, así que una sola página basta. La ficha no tiene enlace: la web
la pide con un POST, pero la URL que devuelve se puede abrir directo:
`/CarInformation/id%3D{id}/store%3D{sede}`.
"""

from urllib.parse import quote

import scrapy

from roodos_scraper.items import ListingItem

SITE = "https://www.bravoauto.com.pe"


class AutosBravoautoPeSpider(scrapy.Spider):
    name = "autos_bravoauto_pe"
    allowed_domains = ["www.bravoauto.com.pe"]
    start_urls = [SITE + "/buy/"]
    custom_settings = {"ROBOTSTXT_OBEY": True, "DOWNLOAD_DELAY": 1.0}

    def parse(self, response):
        cards = response.css("div.vehicle[data-vehicle-make]")
        self.logger.info("Bravoauto: %d tarjetas", len(cards))
        for card in cards:
            item = self._item(card)
            if item:
                yield item

    def _item(self, card):
        data = lambda key: (card.attrib.get(f"data-vehicle-{key}") or "").strip()
        car_id = card.css("button[data-extradata2]::attr(data-extradata2)").get()  # lxml baja a minúsculas
        store = (card.css("p.vehicle-card-store::text").get() or "").strip()
        price = self._to_int(data("price"))
        year = self._to_int(data("model-year"))
        if not car_id or not store or not price or not year:
            return None
        make, model = data("make"), data("model")
        image = card.css("div.carousel-item img::attr(src)").get()
        details = [data("body-style").title(), data("transmission").title(), data("fuel").title(),
                   data("colour").title(), store, "USD"]

        item = ListingItem()
        item["vertical"] = "car"
        item["source_name"] = self.name
        item["source_label"] = "Bravoauto"
        item["country"] = "PE"
        item["url"] = f"{SITE}/CarInformation/id%3D{quote(car_id)}/store%3D{quote(store)}"[:250]
        item["slug"] = f"{make}-{model}-{year}-{car_id}".lower().replace(" ", "-")[:255]
        title = model if make and model.upper().startswith(make.upper()) else f"{make} {model}"
        item["title"] = f"{title} {year}".strip()[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = " | ".join(d for d in details if d)
        item["price"] = price
        item["year"] = year
        item["km"] = self._to_int(data("odometer"))
        item["external_id"] = car_id[:50]
        item["brand_name"] = make or None
        item["model_name"] = model or None
        for key in ("id_state", "id_city", "id_car_make", "id_car_model", "id_fuel", "id_body", "id_transmission"):
            item[key] = None
        return item

    def _to_int(self, value):
        try:
            return int(float(value)) if value not in (None, "") else None
        except ValueError:
            return None
