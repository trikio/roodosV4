#!/usr/bin/env python3
"""
Diveusados (Divemotor, Perú): https://diveusados.divemotor.com/  (~50 autos en nov-2026)

Akamai bloquea a los clientes HTTP planos (curl, requests, Scrapy dan 403, también en las
imágenes); un navegador real pasa. Se usa `scrapling.fetchers.StealthySession`, como en Bruno
Fritsch.

El sitio es Liferay. La portada muestra solo parte del stock, pero su propio buscador lee el
catálogo completo de la API headless de Liferay (structured-contents de la carpeta 275116). La API
se llama con fetch() desde dentro de la página, para ir con las cookies de Akamai y pedir JSON
(navegando directo a la URL devuelve XML).

Cada contenido trae sus campos por etiqueta: Precio (el que muestra la ficha), Marcas, Modelos,
Año, Kilometraje, Combustible, Transmisión, Sede, Bodies y las imágenes en un Fieldset anidado.
Ficha: https://diveusados.divemotor.com/w/{friendlyUrlPath}

Las imágenes también las baja el navegador (solo las de avisos nuevos o con foto cambiada) y se
pasan al pipeline en `image_bytes` para hacer la miniatura.
"""
import argparse
import base64
import json
import logging
import sys
from pathlib import Path

from scrapling.fetchers import StealthySession

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from roodos_scraper.pipelines import MySQLRoodosPipeline  # noqa: E402

BASE = "https://diveusados.divemotor.com"
FOLDER = 275116
API = f"/o/headless-delivery/v1.0/structured-content-folders/{FOLDER}/structured-contents?flatten=true&pageSize=100"

FETCH_JSON = """async (url) => {
  const r = await fetch(url, {headers: {Accept: 'application/json'}});
  return [r.status, await r.text()];
}"""
FETCH_IMAGES = """async (urls) => {
  const out = {};
  for (const url of urls) {
    try {
      const r = await fetch(url);
      if (!r.ok) continue;
      const bytes = new Uint8Array(await r.arrayBuffer());
      let bin = '';
      for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
      out[url] = btoa(bin);
    } catch (e) {}
  }
  return out;
}"""


class Spider:
    """Lo mínimo que el pipeline de Scrapy usa del spider."""
    name = "divemotor_peru"
    logger = logging.getLogger(name)


def fields(item):
    """{etiqueta: valor} de los campos de un contenido, más la lista de imágenes."""
    out, images = {}, []
    stack = list(item.get("contentFields") or [])
    while stack:
        field = stack.pop(0)
        stack.extend(field.get("nestedContentFields") or [])
        value = field.get("contentFieldValue") or {}
        if value.get("image"):
            images.append(BASE + value["image"]["contentUrl"])
        elif value.get("data") not in (None, ""):
            out[field.get("label")] = value["data"]
    return out, images


def to_int(value):
    digits = "".join(ch for ch in str(value or "").split(".")[0] if ch.isdigit())
    return int(digits) if digits else None


def build_item(content):
    data, images = fields(content)
    path = content.get("friendlyUrlPath")
    price, year = to_int(data.get("Precio")), to_int(data.get("Año"))
    if not path or not price or not year:
        return None
    brand, model = data.get("Marcas"), data.get("Modelos")
    version = " ".join((content.get("title") or "").split())
    title = version if brand and version.upper().startswith(brand.upper()) else f"{brand or ''} {version}".strip()
    details = [data.get("Bodies"), data.get("Transmisión"), data.get("Combustible"),
               f"Sede {data['Sede']}" if data.get("Sede") else None]
    details = [d.title() if d.isupper() else d for d in details if d] + ["USD"]
    return {
        "vertical": "car", "source_name": "divemotor_peru", "source_label": "Divemotor", "country": "PE",
        "url": f"{BASE}/w/{path}"[:250], "slug": path.split("/")[-1][:255], "title": title[:250],
        "image": images[0] if images else None, "image_big": images[0] if images else None,
        "details": " | ".join(details),
        "price": price, "year": year, "km": to_int(data.get("Kilometraje")),
        "external_id": str(content.get("id"))[:50], "brand_name": brand, "model_name": model,
        "id_state": None, "id_city": None, "id_car_make": None, "id_car_model": None,
        "id_fuel": None, "id_body": None, "id_transmission": None,
    }


def browser_call(session, script, arg):
    result = {}

    def action(page):
        result["value"] = page.evaluate(script, arg)
        return page

    session.fetch(BASE + "/", timeout=60000, page_action=action)
    return result["value"]


def main(dry_run):
    with StealthySession(headless=True, network_idle=True) as session:
        status, text = browser_call(session, FETCH_JSON, API)
        if status != 200:
            raise SystemExit(f"API respondió {status}: {text[:200]}")
        payload = json.loads(text)
        items = [i for i in map(build_item, payload.get("items") or []) if i]
        print(f"api total={payload.get('totalCount')} paginas={payload.get('lastPage')} validos={len(items)}")
        if (payload.get("lastPage") or 1) > 1:
            print("AVISO: la API tiene más de una página; subir pageSize")

        if dry_run:
            for item in items[:10]:
                print(item["price"], item["year"], item["km"], item["brand_name"], "|", item["model_name"], "|",
                      item["title"], "|", item["details"], "|", item["url"])
            return

        pipeline, spider = MySQLRoodosPipeline(), Spider()
        pipeline.open_spider(spider)
        try:
            # Solo se bajan las imágenes de avisos nuevos o con la foto cambiada.
            need = []
            for item in items:
                pipeline.cur.execute("SELECT image, image_big FROM cars WHERE id_country='PE' AND url=%s", (item["url"],))
                row = pipeline.cur.fetchone()
                if item["image"] and not (row and pipeline._is_cdn_url(row["image"]) and row["image_big"] == item["image"]):
                    need.append(item["image"])
            images = browser_call(session, FETCH_IMAGES, need) if need else {}
            print(f"imagenes pedidas={len(need)} bajadas={len(images)}")

            for item in items:
                if item["image"] in images:
                    item["image_bytes"] = base64.b64decode(images[item["image"]])
                pipeline.process_item(item, spider)
        finally:
            pipeline.close_spider(spider)
        print(f"inserted={pipeline.inserted} updated={pipeline.updated} failed={pipeline.failed}")


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    parser.add_argument("--dry-run", action="store_true", help="no escribe en la base")
    main(parser.parse_args().dry_run)
