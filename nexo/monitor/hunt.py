"""Busca en internet sitios de autos usados que todavía no son fuente y los guarda en nexo.candidates.

Usa la versión liviana de DuckDuckGo. Cada resultado nuevo se visita una vez y se le da un puntaje
según señales de un listado de autos (precios, kilometraje, marcas). Los que pasan el umbral quedan
como `new` para que `bin/nexo dealers probe` los pruebe con el spider genérico.
"""

import re
import time
from datetime import date
from html import unescape
from urllib.parse import parse_qs, quote_plus, urlparse

import requests

from . import db, sources

QUERIES = {
    "cl": ["autos usados chile", "venta autos usados santiago automotora", "seminuevos chile concesionario"],
    "mx": ["autos seminuevos mexico", "venta autos usados mexico agencia", "seminuevos cdmx autos"],
    "pe": ["autos usados peru", "venta autos usados lima concesionario", "seminuevos peru autos"],
    "ec": ["autos usados ecuador", "venta autos usados quito guayaquil", "patio de autos ecuador"],
}
# Automotoras chicas: "automotora + ciudad" y variantes por país, en rotación diaria.
CITIES = {
    "cl": ["Santiago", "Viña del Mar", "Concepción", "Temuco", "Antofagasta", "La Serena", "Rancagua", "Talca",
           "Puerto Montt", "Iquique", "Chillán", "Valdivia", "Osorno", "Calama", "Arica", "Copiapó"],
    "mx": ["Ciudad de México", "Guadalajara", "Monterrey", "Puebla", "Querétaro", "León", "Tijuana", "Mérida",
           "Toluca", "San Luis Potosí", "Aguascalientes", "Hermosillo", "Chihuahua", "Morelia", "Saltillo", "Culiacán"],
    "pe": ["Lima", "Arequipa", "Trujillo", "Chiclayo", "Piura", "Cusco", "Huancayo", "Ica", "Tacna", "Cajamarca",
           "Chimbote", "Puno"],
    "ec": ["Quito", "Guayaquil", "Cuenca", "Ambato", "Manta", "Santo Domingo", "Loja", "Machala", "Riobamba",
           "Ibarra", "Portoviejo", "Latacunga"],
}
DEALER_WORDS = {
    "cl": ["automotora", "autos usados"],
    "mx": ["agencia de seminuevos", "lote de autos"],
    "pe": ["concesionario autos usados", "venta de autos usados"],
    "ec": ["patio de autos", "autos usados"],
}
DEALER_QUERIES_PER_DAY = 16
NOISE = re.compile(
    r"(^|\.)(duckduckgo|google|bing|youtube|facebook|instagram|tiktok|x|twitter|wikipedia|linkedin|"
    r"pinterest|reddit|olx|amazon|apple|gob|gov|blogspot|wordpress)\.",
)
MIN_SCORE = 4
HEADERS = {"User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36"}


def search(query):
    response = requests.get(f"https://lite.duckduckgo.com/lite/?q={quote_plus(query)}", headers=HEADERS, timeout=30)
    response.raise_for_status()
    urls = []
    for href in re.findall(r'href="([^"]+)"', response.text):
        href = unescape(href)
        if "uddg=" in href:
            href = parse_qs(urlparse(href).query).get("uddg", [""])[0]
        if href.startswith("//"):
            href = "https:" + href
        if href.startswith("http"):
            urls.append(href)
    return urls


def score(url):
    """Puntaje de 0 a 7 según qué tanto parece un listado de autos usados."""
    try:
        response = requests.get(url, headers=HEADERS, timeout=25)
    except requests.RequestException:
        return 0
    if response.status_code != 200:
        return 0
    page = response.text.lower()
    points = 1
    points += len(page) > 30_000
    points += len(re.findall(r"\b\d{1,3}(?:[.,]\d{3})+\s*km\b", page)) >= 5  # kilometrajes
    points += len(re.findall(r"(?:\$|us\$|s/|usd)\s*\d", page)) >= 5  # precios
    points += sum(make in page for make in ("toyota", "chevrolet", "nissan", "hyundai", "kia", "suzuki")) >= 3
    points += any(word in page for word in ("usado", "seminuevo"))
    points += any(word in page for word in ("año", "modelo", "kilometraje"))
    return points


def todays_queries():
    """Las búsquedas generales todos los días, más una tanda rotativa de automotoras por ciudad."""
    general = [(country, query) for country, queries in QUERIES.items() for query in queries]
    dealer = [(c, f"{word} {city}") for c, cities in CITIES.items() for city in cities for word in DEALER_WORDS[c]]
    start = (date.today().toordinal() * DEALER_QUERIES_PER_DAY) % len(dealer)
    return general + (dealer + dealer)[start:start + DEALER_QUERIES_PER_DAY]


def run():
    conn = db.connect()
    known = sources.known_domains()
    seen = {row["host"] for row in db.all(conn, "SELECT host FROM nexo.candidates")}
    found = 0

    for country, query in todays_queries():
        try:
            urls = search(query)
        except requests.RequestException as exc:
            print(f"búsqueda falló ({query}): {exc.__class__.__name__}")
            continue
        for url in urls:
            host = (urlparse(url).hostname or "").lower().removeprefix("www.")
            if not host or host in seen or sources.base_domain(host) in known or NOISE.search(host):
                continue
            seen.add(host)
            points = score(url)
            db.execute(
                conn,
                "INSERT IGNORE INTO nexo.candidates (host, country, url, score, query, status) "
                "VALUES (%s, %s, %s, %s, %s, %s)",
                (host, country, url[:500], points, query, "new" if points >= MIN_SCORE else "rejected"),
            )
            found += points >= MIN_SCORE
        time.sleep(3)  # no martillar DuckDuckGo

    conn.close()
    print(f"candidatas nuevas: {found}")
