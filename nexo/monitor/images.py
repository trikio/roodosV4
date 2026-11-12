"""Revisa por muestreo que carguen las imágenes de los avisos activos de cada fuente.

`image` es la miniatura que el pipeline sube a R2; `image_big` es la imagen original en la fuente.
"""

from concurrent.futures import ThreadPoolExecutor

import requests

from . import cars, db, sources

SAMPLE_PER_SOURCE = 20
HEADERS = {"User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36"}


def check(url):
    """(status HTTP o None, ok). Algunos servidores no aceptan HEAD, así que se reintenta con GET."""
    try:
        response = requests.head(url, headers=HEADERS, timeout=15, allow_redirects=True)
        if response.status_code in (403, 405) or response.status_code >= 500:
            response = requests.get(url, headers=HEADERS, timeout=15, stream=True)
            response.close()
    except requests.RequestException:
        return None, False
    content_type = response.headers.get("Content-Type", "")
    ok = response.status_code == 200 and (not content_type or content_type.startswith(("image/", "binary/", "application/octet-stream")))
    return response.status_code, ok


def run():
    conn = db.connect()
    jobs = []
    for source in sources.SOURCES:
        if not source.enabled:
            continue
        for car in cars.sample_active(conn, source, SAMPLE_PER_SOURCE):
            for field in ("image", "image_big"):
                url = (car[field] or "").strip()
                if url.startswith("http"):
                    jobs.append((source.id, car["id"], field, url))

    with ThreadPoolExecutor(max_workers=8) as pool:
        results = list(pool.map(lambda job: check(job[3]), jobs))

    for (source_id, car_id, field, url), (status, ok) in zip(jobs, results):
        db.execute(
            conn,
            "INSERT INTO nexo.image_checks (source_id, car_id, field, url, http_status, ok) VALUES (%s, %s, %s, %s, %s, %s)",
            (source_id, car_id, field, url[:255], status, ok),
        )
    conn.close()
    print(f"imágenes revisadas: {len(jobs)}, rotas: {sum(not ok for _, ok in results)}")
