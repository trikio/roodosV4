"""Busca en internet sitios de autos usados y de casas que todavía no son fuente (nexo.candidates).

Usa la versión liviana de DuckDuckGo. Cada resultado nuevo se visita una vez y se le da un puntaje
según señales de un listado (autos: precios, kilometraje, marcas; casas: m², dormitorios, baños,
venta/alquiler). Autos que pasan el umbral quedan `new` para que `bin/nexo dealers probe` los pruebe
con el spider genérico. Casas que pasan quedan `review` (cada portal necesita su spider) salvo que su
robots.txt prohíba recorrerlo (`blocked`); los lunes llega por Telegram la lista de la semana.
"""

import re
import time
import urllib.robotparser
from datetime import date
from html import unescape
from urllib.parse import parse_qs, quote_plus, urlparse

import requests

from . import db, sources, telegram

QUERIES = {
    "cl": ["autos usados chile", "venta autos usados santiago automotora", "seminuevos chile concesionario"],
    "mx": ["autos seminuevos mexico", "venta autos usados mexico agencia", "seminuevos cdmx autos"],
    "pe": ["autos usados peru", "venta autos usados lima concesionario", "seminuevos peru autos",
           "seminuevos certificados lima", "autos usados con garantía concesionario peru"],
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

# Casas: Perú y Ecuador no tienen fuente desde que se desactivó Doomos (27-11-2026); México solo
# MercadoLibre. Chile ya tiene doomos.cl, pero se buscan más.
HOUSE_QUERIES = {
    "pe": ["departamentos en venta lima", "alquiler de departamentos lima", "casas en venta peru inmobiliaria",
           "portal inmobiliario peru", "venta de terrenos peru"],
    "ec": ["casas en venta ecuador", "departamentos en venta quito", "alquiler departamentos guayaquil",
           "portal inmobiliario ecuador", "venta de terrenos ecuador"],
    "mx": ["casas en venta mexico portal inmobiliario", "departamentos en renta cdmx", "inmobiliaria casas en venta"],
    "cl": ["propiedades en venta chile", "arriendo departamentos santiago", "corredora de propiedades chile"],
}
HOUSE_WORDS = {
    "pe": ["inmobiliaria", "departamentos en venta"],
    "ec": ["inmobiliaria", "casas en venta"],
    "mx": ["inmobiliaria", "casas en venta"],
    "cl": ["corredora de propiedades", "casas en venta"],
}
HOUSE_QUERIES_PER_DAY = 10
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


def house_score(url):
    """Puntaje de 0 a 7 según qué tanto parece un listado de propiedades."""
    try:
        response = requests.get(url, headers=HEADERS, timeout=25)
    except requests.RequestException:
        return 0
    if response.status_code != 200:
        return 0
    page = response.text.lower()
    points = 1
    points += len(page) > 30_000
    points += len(re.findall(r"\b\d{2,5}(?:[.,]\d+)?\s*(?:m2|m²|mt2|mts2|metros)", page)) >= 5  # superficies
    points += len(re.findall(r"(?:\$|us\$|s/|usd|uf)\s*\d", page)) >= 5  # precios
    points += sum(word in page for word in ("dormitorio", "recámara", "recamara", "habitaci", "baño", "estacionamiento")) >= 2
    points += any(word in page for word in ("en venta", "en alquiler", "en arriendo", "en renta"))
    points += any(word in page for word in ("departamento", "casa", "terreno", "oficina"))
    return points


def robots_allows(url):
    """False si el robots.txt del sitio prohíbe recorrer la página encontrada. Sin robots.txt, se permite."""
    parsed = urlparse(url)
    parser = urllib.robotparser.RobotFileParser()
    try:
        response = requests.get(f"{parsed.scheme}://{parsed.netloc}/robots.txt", headers=HEADERS, timeout=15)
    except requests.RequestException:
        return True
    if response.status_code != 200:
        return True
    parser.parse(response.text.splitlines())
    return parser.can_fetch("*", url)


def todays_house_queries():
    """Las búsquedas generales de casas, más una tanda rotativa de inmobiliarias por ciudad."""
    general = [(country, query) for country, queries in HOUSE_QUERIES.items() for query in queries]
    local = [(c, f"{word} {city}") for c, cities in CITIES.items() for city in cities for word in HOUSE_WORDS[c]]
    start = (date.today().toordinal() * HOUSE_QUERIES_PER_DAY) % len(local)
    return general + (local + local)[start:start + HOUSE_QUERIES_PER_DAY]


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
    found = {"autos": 0, "casas": 0}

    queries = [("autos", c, q) for c, q in todays_queries()] + [("casas", c, q) for c, q in todays_house_queries()]
    for vertical, country, query in queries:
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
            if vertical == "autos":
                points = score(url)
                status = "new" if points >= MIN_SCORE else "rejected"
            else:
                points = house_score(url)
                status = "rejected" if points < MIN_SCORE else ("review" if robots_allows(url) else "blocked")
            db.execute(
                conn,
                "INSERT IGNORE INTO nexo.candidates (host, country, vertical, url, score, query, status) "
                "VALUES (%s, %s, %s, %s, %s, %s, %s)",
                (host, country, vertical, url[:500], points, query, status),
            )
            found[vertical] += status in ("new", "review")
        time.sleep(3)  # no martillar DuckDuckGo

    conn.close()
    print(f"candidatas nuevas: autos {found['autos']}, casas {found['casas']}")
    if date.today().weekday() == 0:
        house_review()


def house_review():
    """Los lunes, por Telegram: sitios de casas encontrados en la semana que se pueden recorrer."""
    conn = db.connect()
    rows = db.all(
        conn,
        "SELECT host, country, score FROM nexo.candidates WHERE vertical='casas' AND status='review' "
        "AND first_seen >= NOW() - INTERVAL 7 DAY ORDER BY country, score DESC LIMIT 40",
    )
    conn.close()
    if not rows:
        return
    lines = [f"🏠 {len(rows)} sitios de casas nuevos esta semana (robots.txt permite recorrerlos; falta revisar términos):"]
    lines += [f"• [{r['country'].upper()}] {r['host']} · puntaje {r['score']}" for r in rows]
    lines += ["", "Cada uno necesita su spider: avisa cuáles adaptar."]
    telegram.send("\n".join(lines))
