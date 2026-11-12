#!/usr/bin/env python3
import argparse
import gzip
import os
import re
import sys
import urllib.request
from dataclasses import dataclass
from xml.etree import ElementTree as ET
from urllib.parse import urlparse

import mysql.connector


COUNTRY = "PE"
USER_AGENT = "RoodosBot/1.0 (+https://roodos.com)"
NS = {"sm": "http://www.sitemaps.org/schemas/sitemap/0.9"}


@dataclass(frozen=True)
class SourceConfig:
    label: str
    sitemap_urls: tuple[str, ...]
    detail_re: re.Pattern
    min_active_urls: int


SOURCES = {
    "Autocosmos": SourceConfig(
        label="Autocosmos",
        sitemap_urls=("https://www.autocosmos.com.pe/sitemap.xml",),
        detail_re=re.compile(r"^/catalogo/\d{4}/[^/]+/[^/]+/.+/\d+$", re.I),
        min_active_urls=100,
    ),
    "Koopa": SourceConfig(
        label="Koopa",
        sitemap_urls=("https://koopa.pe/product-sitemap.xml",),
        detail_re=re.compile(r"^/producto/\d+/?$", re.I),
        min_active_urls=30,
    ),
    "Wigo": SourceConfig(
        label="Wigo",
        sitemap_urls=("https://www.wigomotors.com/sitemap.xml",),
        detail_re=re.compile(r"^/marcas/[^/]+/usado/[^/]+-\d+$", re.I),
        min_active_urls=5,
    ),
    "LeonFord": SourceConfig(
        label="LeonFord",
        sitemap_urls=("https://www.leonford.pe/sitemap.xml",),
        detail_re=re.compile(r"^/vehiculos/stock/\d+/\d+/[^/]+$", re.I),
        min_active_urls=1,
    ),
    "AutoSummit": SourceConfig(
        label="AutoSummit",
        sitemap_urls=("https://www.autosummitford.pe/sitemap.xml",),
        detail_re=re.compile(r"^/vehiculos/stock/\d+/\d+/[^/]+$", re.I),
        min_active_urls=1,
    ),
}


def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=45) as resp:
        body = resp.read()
        if url.endswith(".gz") or resp.headers.get("Content-Encoding") == "gzip":
            body = gzip.decompress(body)
        return body


def xml_locs(body):
    root = ET.fromstring(body)
    locs = [(loc.text or "").strip() for loc in root.findall(".//sm:loc", NS)]
    return "sitemapindex" in root.tag.lower(), [url for url in locs if url]


def fetch_sitemap_locs(sitemap_url, depth=0):
    body = fetch(sitemap_url)
    is_index, locs = xml_locs(body)
    if not is_index:
        return locs

    urls = []
    for child_url in locs:
        if depth >= 2:
            continue
        urls.extend(fetch_sitemap_locs(child_url, depth + 1))
    return urls


def fetch_active_urls(config):
    urls = set()
    for sitemap_url in config.sitemap_urls:
        for url in fetch_sitemap_locs(sitemap_url):
            path = urlparse(url).path.rstrip("/")
            if config.detail_re.match(path):
                urls.add(url[:250])
    return urls


def connect():
    return mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "localhost"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        user=os.getenv("MYSQL_USER", "root"),
        password=os.getenv("MYSQL_PASSWORD", ""),
        database=os.getenv("MYSQL_DATABASE", "roodos"),
        autocommit=False,
    )


def sync_source(config, dry_run=False):
    active_urls = fetch_active_urls(config)
    if len(active_urls) < config.min_active_urls:
        raise RuntimeError(
            f"{config.label}: refusing to sync, only {len(active_urls)} active URLs "
            f"found (min {config.min_active_urls})"
        )

    conn = connect()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                CREATE TEMPORARY TABLE active_peru_auto_urls (
                    url VARCHAR(250) NOT NULL PRIMARY KEY
                ) ENGINE=MEMORY
                """
            )
            batch = [(url,) for url in active_urls]
            for i in range(0, len(batch), 500):
                cur.executemany(
                    "INSERT IGNORE INTO active_peru_auto_urls (url) VALUES (%s)",
                    batch[i : i + 500],
                )

            params = (COUNTRY, config.label)
            cur.execute(
                """
                SELECT COUNT(*)
                FROM cars c
                LEFT JOIN active_peru_auto_urls a ON a.url = c.url
                WHERE c.id_country=%s AND c.nexo_id=%s AND c.status=1 AND a.url IS NULL
                """,
                params,
            )
            expire_count = int(cur.fetchone()[0] or 0)

            cur.execute(
                """
                SELECT COUNT(*)
                FROM cars c
                JOIN active_peru_auto_urls a ON a.url = c.url
                WHERE c.id_country=%s AND c.nexo_id=%s AND c.status<>1
                """,
                params,
            )
            reactivate_count = int(cur.fetchone()[0] or 0)

            if not dry_run:
                cur.execute(
                    """
                    UPDATE cars c
                    LEFT JOIN active_peru_auto_urls a ON a.url = c.url
                    SET c.status=0
                    WHERE c.id_country=%s AND c.nexo_id=%s AND c.status=1 AND a.url IS NULL
                    """,
                    params,
                )
                cur.execute(
                    """
                    UPDATE cars c
                    JOIN active_peru_auto_urls a ON a.url = c.url
                    SET c.status=1
                    WHERE c.id_country=%s AND c.nexo_id=%s AND c.status<>1
                    """,
                    params,
                )
                conn.commit()
            else:
                conn.rollback()

            cur.execute(
                """
                SELECT COUNT(*), SUM(status=1), SUM(status<>1)
                FROM cars
                WHERE id_country=%s AND nexo_id=%s
                """,
                params,
            )
            total, active, inactive = cur.fetchone()
            return {
                "source": config.label,
                "sitemap_urls": len(active_urls),
                "expired": expire_count,
                "reactivated": reactivate_count,
                "total": int(total or 0),
                "active": int(active or 0),
                "inactive": int(inactive or 0),
                "dry_run": dry_run,
            }
    finally:
        conn.close()


def print_result(result):
    print(
        "Peru auto sitemap status sync | "
        f"source={result['source']} "
        f"sitemap_urls={result['sitemap_urls']} "
        f"expired={result['expired']} "
        f"reactivated={result['reactivated']} "
        f"total={result['total']} "
        f"active={result['active']} "
        f"inactive={result['inactive']} "
        f"dry_run={int(result['dry_run'])}"
    )


def main():
    parser = argparse.ArgumentParser(
        description="Sync Peru auto listing status from source sitemaps."
    )
    parser.add_argument("--source", choices=[*SOURCES.keys(), "all"], default="all")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    selected = SOURCES.values() if args.source == "all" else (SOURCES[args.source],)
    failed = False
    for config in selected:
        try:
            print_result(sync_source(config, dry_run=args.dry_run))
        except Exception as exc:
            failed = True
            print(f"{config.label}: {exc}", file=sys.stderr)
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
