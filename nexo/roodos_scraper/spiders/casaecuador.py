"""Casa Ecuador (casaecuador.net): portal de avisos de inmuebles de Ecuador (~740 en nov-2026).

robots.txt solo cierra /wp-admin/ y sus términos (mayo de 2026) no prohíben el acceso automatizado;
reservan el diseño, logos, textos y código propios del portal, no los avisos de los anunciantes.

Es un WordPress con el tema Houzez, y la API REST pública (`/wp-json/wp/v2/properties`) entrega el
catálogo completo en páginas de 100: tipo, operación, provincia, ciudad y sector (en `class_list`),
precio, dormitorios, baños y m² (en `property_meta`), y la foto destacada con `_embed`. Los precios
son en dólares, la moneda de Ecuador.
"""

import html
import json
import re

import scrapy

from roodos_scraper.items import ListingItem

API = "https://casaecuador.net/wp-json/wp/v2/properties"
FIELDS = "id,link,title,class_list,property_meta,_links.wp:featuredmedia,_embedded"

# house_type de roodos. Un aviso puede traer varios tipos ("casa" + "terreno"): vale el primero de esta lista.
TYPES = {"casa": 1, "departamentos": 2, "quinta": 19, "hacienda": 18, "oficina": 6, "local-comercial": 4,
         "terreno": 3}
OPERATIONS = {"en-venta": 1, "alquilar": 2}  # house_operation: 1 Venta, 2 Arriendo


def meta(prop, key):
    return str((prop.get("property_meta", {}).get(key) or [""])[0] or "").strip()


def classes(prop, prefix):
    """Slugs de una taxonomía en class_list: "property_city-santo-domingo" -> "santo-domingo"."""
    return [c[len(prefix):] for c in prop.get("class_list") or [] if c.startswith(prefix)]


def place(slug):
    """"santo-domingo-de-los-tsachilas" -> "santo domingo de los tsachilas" (WordPress agrega "-2" a los
    slugs repetidos). El normalizador compara sin tildes, así que "canar" calza con Cañar."""
    return re.sub(r"-\d+$", "", slug).replace("-", " ")


def parse_price(text):
    """"60.000", "$ 39500", "65000.00" -> entero; los decimales de centavos se descartan."""
    s =re.sub(r"[^\d.,]", "", text or "")
    s = re.sub(r"[.,]\d{1,2}$", "", s)
    digits = re.sub(r"\D", "", s)
    return int(digits) if digits and int(digits) >= 50 else None  # "1" o "0" son "consultar precio"


def parse_area(text):
    """Primer número de "120,37 mts2", "228.36", "1.250 m²"; fuera de rango ("1 cuadra") queda vacío."""
    m = re.search(r"\d[\d.,]*", text or "")
    if not m:
        return None
    s = m.group(0).rstrip(".,")
    if re.fullmatch(r"\d{1,3}([.,]\d{3})+", s):
        value = float(re.sub(r"[.,]", "", s))
    else:
        value = float(s.replace(",", ".")) if s.count(",") + s.count(".") <= 1 else None
    return int(value) if value and 10 <= value <= 1_000_000 else None


def small_int(text):
    m = re.search(r"\d+", text or "")
    return int(m.group(0)) if m and 0 < int(m.group(0)) < 50 else None


class CasasCasaecuadorEcSpider(scrapy.Spider):
    name = "casas_casaecuador_ec"
    allowed_domains = ["casaecuador.net"]
    custom_settings = {"CONCURRENT_REQUESTS": 2}

    def start_requests(self):
        yield self._page(1)

    def _page(self, page):
        url = f"{API}?per_page=100&page={page}&orderby=id&order=asc&_embed=wp:featuredmedia&_fields={FIELDS}"
        return scrapy.Request(url, callback=self.parse, cb_kwargs={"page": page},
                              headers={"Accept": "application/json"})

    def parse(self, response, page):
        props = json.loads(response.text)
        if not isinstance(props, list):
            raise ValueError(f"respuesta inesperada de la API: {response.text[:200]}")
        if page == 1:
            total_pages = int(response.headers.get("X-WP-TotalPages", b"1").decode() or 1)
            total = response.headers.get("X-WP-Total", b"?").decode()
            self.logger.info("Casa Ecuador: %s propiedades en %d páginas", total, total_pages)
            for next_page in range(2, total_pages + 1):
                yield self._page(next_page)
        for prop in props:
            item = self._item(prop)
            if item:
                yield item

    def _item(self, prop):
        url = (prop.get("link") or "").split("#")[0]
        title = " ".join(html.unescape(re.sub(r"<[^>]+>", " ", (prop.get("title") or {}).get("rendered") or "")).split())
        if not url or not title:
            return None
        types = classes(prop, "property_type-")
        type_id = next((TYPES[t] for t in TYPES if t in types), None)
        if type_id is None:
            return None  # solo "habitacion" (2 avisos), sin tipo equivalente en roodos
        statuses = classes(prop, "property_status-")
        operation_id = next((OPERATIONS[s] for s in OPERATIONS if s in statuses), None)

        rooms = small_int(meta(prop, "fave_property_bedrooms"))
        bath = small_int(meta(prop, "fave_property_bathrooms"))
        size = parse_area(meta(prop, "fave_property_size"))
        if size is None and type_id == 3:
            size = parse_area(meta(prop, "fave_property_land"))

        area = [place(x) for x in classes(prop, "property_area-")]
        city = [place(x) for x in classes(prop, "property_city-")]
        state = [place(x) for x in classes(prop, "property_state-")]
        # La ciudad va antes que el sector: el normalizador se queda con el primero que calza, y sectores de
        # Quito como "Carolina" o "Mariscal" calzan con localidades homónimas del catálogo. El sector
        # solo cuenta cuando el aviso no trae ciudad.
        location = " | ".join(city + area + ["region:" + s for s in state]) or None
        address = ", ".join(x.title() for x in area[:1] + city[:1] + state[:1])

        image = image_big = None
        media = ((prop.get("_embedded") or {}).get("wp:featuredmedia") or [None])[0] or {}
        if media.get("source_url"):
            image_big = media["source_url"]
            sizes = (media.get("media_details") or {}).get("sizes") or {}
            image = (sizes.get("houzez-item-image-6") or sizes.get("medium_large") or sizes.get("medium") or {}).get("source_url") or image_big

        details = [f"{rooms} dormitorios" if rooms else None, f"{bath} baños" if bath else None,
                   f"{size} m²" if size else None, address or None, "USD"]

        item = ListingItem()
        item["vertical"] = "house"
        item["source_name"] = self.name
        item["source_label"] = "Casa Ecuador"
        item["country"] = "ec"
        item["url"] = url[:250]
        item["slug"] = url.rstrip("/").split("/")[-1][:255]
        item["title"] = title[:250]
        item["image"] = image
        item["image_big"] = image_big
        item["details"] = " | ".join(x for x in details if x)[:2000]
        item["price"] = parse_price(meta(prop, "fave_property_price"))
        item["external_id"] = str(prop.get("id"))
        item["location"] = location
        item["id_state"] = None
        item["id_city"] = None
        item["id_house_operation"] = operation_id
        item["id_house_type"] = type_id
        item["rooms"] = rooms
        item["bath"] = bath
        item["size"] = size
        return item
