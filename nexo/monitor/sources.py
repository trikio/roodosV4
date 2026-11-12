"""Registro de las fuentes de autos. De aquí salen el crontab y la tabla nexo.sources.

Cada fuente escribe en roodos.cars; sus avisos se reconocen por el país y el host de la URL.
`schedule` es una expresión de cron en hora de Chile (la zona horaria del servidor).
`full_crawl` se marca solo cuando el scraper recorre el listado completo de la fuente: en ese
caso un aviso que no se ve en EXPIRE_DAYS ya no existe y se desactiva. Con tope de páginas,
los avisos más allá del tope nunca se ven y no se pueden dar por vencidos.
"""

from dataclasses import dataclass, field

from .config import PYTHON, SCRAPY

EC_BACKFILL = (
    ("mysql-file", "sql/backfill_make_model_ec.sql"),
    ("command", PYTHON, "scripts/backfill_location_ec.py"),
)


def peru_sitemap_sync(source_label):
    return (("command", PYTHON, "scripts/sync_peru_auto_sitemap_status.py", "--source", source_label),)


@dataclass(frozen=True)
class Source:
    id: str
    country: str
    name: str
    hosts: tuple
    schedule: str
    command: tuple = ()
    after: tuple = ()
    timeout_hours: int = 6
    full_crawl: bool = False
    enabled: bool = True
    vertical: str = "autos"
    dealers: bool = False  # sus hosts son los sitios activos del país en nexo.dealer_sites

    @property
    def max_age_days(self):
        """Días sin una ejecución buena antes de considerarla atrasada."""
        day_of_week = self.schedule.split()[4]
        return 2 if day_of_week == "*" else 8


def spider(id, country, name, hosts, schedule, **kwargs):
    return Source(id, country, name, tuple(hosts), schedule, (SCRAPY, "crawl", id, "-L", "INFO"), **kwargs)


def script(id, country, name, hosts, schedule, *args, **kwargs):
    return Source(id, country, name, tuple(hosts), schedule, (PYTHON, *args), **kwargs)


def dealers(country, schedule):
    """Las automotoras chicas de un país, todas con el spider genérico `dealers`. Recorre sus sitemaps
    completos, y la regla de vencidos se aplica sitio por sitio (ver cars.deactivate_stale)."""
    return Source(
        f"automotoras_{country}", country, f"Automotoras {country.upper()}", (), schedule,
        (SCRAPY, "crawl", "dealers", "-a", f"country={country}", "-L", "INFO"), dealers=True, full_crawl=True,
    )


SOURCES = [
    # Chile
    script("autosusados_cl", "cl", "Autosusados", ["autosusados.cl"], "50 4 * * 4",
           "scripts/scrape_autosusados_cl.py", "--max-pages", "120"),
    spider("portillo_chile", "cl", "Portillo", ["www.portillo.cl", "www.clicar.cl"], "10 5 * * 5"),
    script("auto_cl", "cl", "Auto.cl", ["www.auto.cl"], "30 5 * * 5",
           "scripts/scrape_auto_cl_challenge.py", "--max-pages", "80"),
    spider("brunofritsch_chile", "cl", "Bruno Fritsch", ["www.brunofritsch.cl"], "40 3 * * 4"),
    spider("kovacs_chile", "cl", "Kovacs", ["www.kovacsusados.cl"], "40 4 * * 4"),
    # Sin proxies (MercadoLibre manda las IPs de datacenter a verificar cuenta) y por tramos de precio.
    # La primera ejecución sube ~27.000 miniaturas; después se reutilizan.
    spider("mercadolibre_chile", "cl", "MercadoLibre CL", ["auto.mercadolibre.cl"], "20 5 * * 5",
           timeout_hours=12, full_crawl=True),
    spider("salfa_chile", "cl", "Salfa", ["salfausados.cl"], "40 5 * * 5", full_crawl=True),
    spider("autocosmos_chile", "cl", "Autocosmos CL", ["www.autocosmos.cl"], "10 4 * * 4"),
    # México
    # Bloqueada por el desafío de Cloudflare desde oct-2026; sus avisos se desactivaron el 12-11-2026.
    spider("seminuevos_mexico", "mx", "Seminuevos", ["www.seminuevos.com"], "50 3 * * 2", enabled=False),
    spider("autocosmos_mexico", "mx", "Autocosmos MX", ["www.autocosmos.com.mx"], "20 4 * * 2", timeout_hours=4,
           full_crawl=True),
    spider("mercadolibre_mexico", "mx", "MercadoLibre MX", ["auto.mercadolibre.com.mx"], "20 3 * * 2",
           timeout_hours=12),
    spider("autos_carplanet_mexico", "mx", "Carplanet", ["carplanet.mx"], "45 4 * * 6"),
    spider("autos_seminuevossonora_mexico", "mx", "Seminuevos Sonora", ["seminuevossonora.com"], "50 4 * * 6"),
    # Sus términos de uso prohíben el scraping; solo se activaría con un acuerdo o feed de Kavak.
    spider("autos_kavak_mexico", "mx", "Kavak", ["www.kavak.com"], "55 3 * * 2", enabled=False),
    # Casi todo su listado es de 2019-2022 y nunca expira avisos (revisado en nov-2026).
    spider("autos_carros_mexico", "mx", "Carros.com", ["www.carros.com"], "35 4 * * 6", enabled=False),
    # Perú
    spider("autos_peruautos_pe", "pe", "Peruautos", ["www.peruautos.pe"], "0 4 * * 3"),
    spider("autocosmos_peru", "pe", "Autocosmos PE", ["www.autocosmos.com.pe"], "0 6 * * 3",
           after=peru_sitemap_sync("Autocosmos")),
    # MercadoLibre pide iniciar sesión a esta IP y a los proxies (12-11-2026).
    spider("mercadolibre_peru", "pe", "MercadoLibre PE", ["auto.mercadolibre.com.pe"], "0 5 * * 3",
           enabled=False),
    spider("autos_koopa_pe", "pe", "Koopa", ["koopa.pe"], "30 4 * * 3"),
    spider("autos_autosell_pe", "pe", "Autosell", ["www.autosell.pe"], "35 6 * * 3", timeout_hours=2),
    spider("autos_tuauto_pe", "pe", "TuAuto", ["tuauto.com.pe"], "40 6 * * 3", timeout_hours=2),
    spider("autos_neoauto_pe", "pe", "Neoauto", ["neoauto.com"], "45 6 * * 3", timeout_hours=2),
    spider("autos_wigo_pe", "pe", "Wigo Motors", ["www.wigomotors.com"], "50 6 * * 3", timeout_hours=2,
           after=peru_sitemap_sync("Wigo")),
    spider("autos_autoland_pe", "pe", "Autoland", ["www.autoland.com.pe"], "55 6 * * 3", timeout_hours=2),
    spider("autos_leonford_pe", "pe", "Leon Ford", ["www.leonford.pe"], "0 7 * * 3", timeout_hours=2,
           after=peru_sitemap_sync("LeonFord")),
    spider("autos_autosummit_pe", "pe", "Autosummit Ford", ["www.autosummitford.pe"], "5 7 * * 3", timeout_hours=2,
           after=peru_sitemap_sync("AutoSummit")),
    # Ecuador
    # Bloqueada por el desafío de Cloudflare; sus avisos se desactivaron el 12-11-2026.
    spider("patiotuerca_ecuador", "ec", "Patiotuerca", ["ecuador.patiotuerca.com"], "0 3 * * 1", after=EC_BACKFILL,
           enabled=False),
    spider("autos_topmotors_ec", "ec", "Topmotors", ["www.topmotorsusados.com"], "30 3 * * 1", after=EC_BACKFILL),
    # MercadoLibre pide iniciar sesión a esta IP y a los proxies; sus avisos se desactivaron el 12-11-2026.
    spider("mercadolibre_ecuador", "ec", "MercadoLibre EC", ["auto.mercadolibre.com.ec"], "0 6 * * 6", after=EC_BACKFILL,
           enabled=False),
    spider("autocosmos_ecuador", "ec", "Autocosmos EC", ["www.autocosmos.com.ec"], "30 6 * * 6", after=EC_BACKFILL),
]

# Automotoras con sitio propio (nexo.dealer_sites), dos veces por semana.
SOURCES += [
    dealers("cl", "0 1 * * 0,3"),
    dealers("mx", "30 1 * * 0,3"),
    dealers("pe", "0 2 * * 0,3"),
    dealers("ec", "30 2 * * 0,3"),
]

BY_ID = {source.id: source for source in SOURCES}


TWO_LEVEL_SUFFIXES = {"com.mx", "com.pe", "com.ec", "com.co", "com.ar", "co.uk"}


def base_domain(host):
    """auto.mercadolibre.com.mx -> mercadolibre.com.mx; www.portillo.cl -> portillo.cl."""
    labels = host.lower().split(".")
    keep = 3 if ".".join(labels[-2:]) in TWO_LEVEL_SUFFIXES else 2
    return ".".join(labels[-keep:])


def known_domains():
    """Dominios que ya son fuente, para no proponerlos como candidatos (el listado y los avisos
    pueden estar en subdominios distintos, como autos. y auto. en MercadoLibre)."""
    return {base_domain(host) for source in SOURCES for host in source.hosts}


def sync(conn):
    """Copia el registro a nexo.sources."""
    from . import db

    for s in SOURCES:
        db.execute(
            conn,
            """
            INSERT INTO nexo.sources (id, vertical, country, name, hosts, schedule, full_crawl, enabled)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE vertical=VALUES(vertical), country=VALUES(country), name=VALUES(name),
              hosts=VALUES(hosts), schedule=VALUES(schedule), full_crawl=VALUES(full_crawl), enabled=VALUES(enabled)
            """,
            (s.id, s.vertical, s.country, s.name, "nexo.dealer_sites" if s.dealers else ",".join(s.hosts),
             s.schedule, s.full_crawl, s.enabled),
        )
    placeholders = ",".join(["%s"] * len(SOURCES))
    db.execute(conn, f"DELETE FROM nexo.sources WHERE id NOT IN ({placeholders})", tuple(BY_ID))
