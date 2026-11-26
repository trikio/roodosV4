"""MercadoLibre Inmuebles México: casas y departamentos en venta y en renta.

Mismo listado que MercadoLibre Autos (tarjetas `poly-*`, máximo ~2.000 resultados por búsqueda), así
que reutiliza MercadolibreListingSpider: bandas de precio `_PriceRange_{lo}MXN-{hi}MXN_NoIndex_True`
que se parten solas hasta poder paginarse completas. Cada sección (casas/venta, departamentos/renta...)
es un spider aparte, para que el monitor registre y venza cada una por separado.

La tarjeta trae título, precio (MXN o US$), recámaras, baños, m² y la dirección
("Calle, Colonia, Municipio, Estado"). Se guardan en roodos.houses (vertical "house"); la dirección
la resuelve el normalizador del pipeline.
"""

import re

from roodos_scraper.items import ListingItem
from roodos_scraper.spiders.roodos_spiders import MercadolibreListingSpider

def first_number(text):
    """Primer número de "1,250 m²", "95.5 m²" o "2 a 3 recámaras" (la coma es de miles)."""
    m = re.search(r"\d[\d,]*(?:\.\d+)?", text or "")
    return int(float(m.group(0).replace(",", ""))) if m else None


VENTA_BOUNDS = [0, 800000, 1200000, 1600000, 2000000, 2500000, 3000000, 3500000, 4000000, 5000000,
                6000000, 7500000, 10000000, 15000000, 25000000, 0]
RENTA_BOUNDS = [0, 6000, 9000, 12000, 15000, 18000, 22000, 28000, 35000, 50000, 80000, 0]


class MercadolibreInmueblesMexicoSpider(MercadolibreListingSpider):
    """Base de las secciones; sin `name`, Scrapy no la registra."""

    country = "MX"
    item_prefix = "MLM"
    currency = "MXN"
    section = None          # "casas/venta"
    operation_id = None     # house_operation: 1 Venta, 2 Arriendo
    type_id = None          # house_type: 1 Casa, 2 Departamento
    min_band_width = 1000

    def __init__(self, *args, **kwargs):
        self.base_url = f"https://inmuebles.mercadolibre.com.mx/{self.section}/"
        self.ad_host = "mercadolibre.com.mx"
        super().__init__(*args, **kwargs)
        # Los avisos viven en casa.mercadolibre.com.mx, departamento.mercadolibre.com.mx, ...
        self.ad_re = re.compile(r"^https://[a-z]+\.mercadolibre\.com\.mx/MLM-\d+")

    def _parse_card(self, response, card):
        a = card.css("a.poly-component__title::attr(href)").get()
        title = " ".join((card.css("a.poly-component__title::text").get() or "").split())
        if not a or not title:
            return None
        url = response.urljoin(a.split("#")[0])
        if not self.ad_re.match(url):
            return None  # publicidad o links de seguimiento

        symbol = (card.css(".poly-price__current .andes-money-amount__currency-symbol::text").get() or "").strip()
        if "US" in symbol.upper():
            return None  # roodos.houses no guarda moneda: el precio se muestra en pesos
        price = self._to_int("".join(card.css(".poly-price__current .andes-money-amount__fraction::text").getall()))

        attrs = [" ".join(x.split()) for x in card.css(".poly-attributes_list__item::text").getall() if x.strip()]
        # Los desarrollos traen rangos ("1 a 2 baños", "45 - 82 m²"): vale el primer número.
        rooms = bath = size = None
        for attr in attrs:
            low = attr.lower()
            if "recámara" in low or "recamara" in low or "habitaci" in low:
                rooms = first_number(attr)
            elif "baño" in low:
                bath = first_number(attr)
            elif "m²" in low and size is None:
                size = first_number(attr)

        address = " ".join((card.css(".poly-component__location::text").get() or "").split())
        image = card.css("img.poly-component__picture::attr(src)").get() or card.css("img.poly-component__picture::attr(data-src)").get()
        if image and image.startswith("data:"):
            image = card.css("img.poly-component__picture::attr(data-src)").get()

        item = ListingItem()
        item["vertical"] = "house"
        item["source_name"] = self.name
        item["source_label"] = "Mercadolibre"
        item["country"] = "mx"
        item["url"] = url[:250]
        item["slug"] = url.rstrip("/").split("/")[-1][:255]
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image
        item["details"] = " | ".join(attrs + ([address] if address else []))[:2000] or None
        item["price"] = price
        m = self.id_re.search(url)
        item["external_id"] = m.group(1) if m else item["slug"][:50]
        item["location"] = self._address_to_location(address)
        item["id_state"] = None
        item["id_city"] = None
        item["id_house_operation"] = self.operation_id
        item["id_house_type"] = self.type_id
        item["rooms"] = rooms
        item["bath"] = bath
        item["size"] = size
        return item

    @staticmethod
    def _address_to_location(address):
        """"Calle 1, Colonia, Municipio, Estado" -> "Municipio | Colonia | region:Estado".
        "Cancún/Benito Juárez" se prueba por las dos partes."""
        parts = [p.strip() for p in (address or "").split(",") if p.strip()]
        if len(parts) < 2:
            return parts[0] if parts else None
        names = [x.strip() for x in parts[-2].split("/") if x.strip()]
        if len(parts) >= 3:
            names.append(parts[-3])
        return " | ".join(names + ["region:" + parts[-1]])


class CasasVentaMercadolibreMexicoSpider(MercadolibreInmueblesMexicoSpider):
    name = "mercadolibre_casas_venta_mexico"
    section, operation_id, type_id = "casas/venta", 1, 1
    seed_bounds = VENTA_BOUNDS
    default_max_pages = 1900


class DepartamentosVentaMercadolibreMexicoSpider(MercadolibreInmueblesMexicoSpider):
    name = "mercadolibre_departamentos_venta_mexico"
    section, operation_id, type_id = "departamentos/venta", 1, 2
    seed_bounds = VENTA_BOUNDS
    default_max_pages = 1400


class CasasRentaMercadolibreMexicoSpider(MercadolibreInmueblesMexicoSpider):
    name = "mercadolibre_casas_renta_mexico"
    section, operation_id, type_id = "casas/renta", 2, 1
    seed_bounds = RENTA_BOUNDS
    min_band_width = 100
    default_max_pages = 400


class DepartamentosRentaMercadolibreMexicoSpider(MercadolibreInmueblesMexicoSpider):
    name = "mercadolibre_departamentos_renta_mexico"
    section, operation_id, type_id = "departamentos/renta", 2, 2
    seed_bounds = RENTA_BOUNDS
    min_band_width = 100
    default_max_pages = 500
