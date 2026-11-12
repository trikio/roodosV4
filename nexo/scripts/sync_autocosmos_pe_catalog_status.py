#!/usr/bin/env python3
import argparse
import os
import re
import sys
import urllib.request
from xml.etree import ElementTree as ET
from urllib.parse import urlparse

import mysql.connector


SITEMAP_URL = "https://www.autocosmos.com.pe/sitemap.xml"
COUNTRY = "PE"
SOURCE = "Autocosmos"
MIN_ACTIVE_URLS = 100
DETAIL_RE = re.compile(r"^/catalogo/\d{4}/[^/]+/[^/]+/.+/\d+$")


def fetch_catalog_urls(sitemap_url):
    req = urllib.request.Request(
        sitemap_url,
        headers={"User-Agent": "RoodosBot/1.0 (+https://roodos.com)"},
    )
    with urllib.request.urlopen(req, timeout=45) as resp:
        body = resp.read()

    root = ET.fromstring(body)
    ns = {"sm": "http://www.sitemaps.org/schemas/sitemap/0.9"}
    urls = set()
    for loc in root.findall(".//sm:loc", ns):
        url = (loc.text or "").strip()
        if "/catalogo/" not in url:
            continue
        if not DETAIL_RE.match(urlparse(url).path.rstrip("/")):
            continue
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


def sync_status(urls, dry_run=False):
    conn = connect()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                CREATE TEMPORARY TABLE active_autocosmos_pe_urls (
                    url VARCHAR(250) NOT NULL PRIMARY KEY
                ) ENGINE=MEMORY
                """
            )
            batch = [(u,) for u in urls]
            for i in range(0, len(batch), 500):
                cur.executemany(
                    "INSERT IGNORE INTO active_autocosmos_pe_urls (url) VALUES (%s)",
                    batch[i : i + 500],
                )

            cur.execute(
                """
                SELECT COUNT(*)
                FROM cars c
                LEFT JOIN active_autocosmos_pe_urls a ON a.url = c.url
                WHERE c.id_country=%s
                  AND c.nexo_id=%s
                  AND c.status=1
                  AND a.url IS NULL
                """,
                (COUNTRY, SOURCE),
            )
            expire_count = int(cur.fetchone()[0] or 0)

            cur.execute(
                """
                SELECT COUNT(*)
                FROM cars c
                JOIN active_autocosmos_pe_urls a ON a.url = c.url
                WHERE c.id_country=%s
                  AND c.nexo_id=%s
                  AND c.status<>1
                """,
                (COUNTRY, SOURCE),
            )
            reactivate_count = int(cur.fetchone()[0] or 0)

            if not dry_run:
                cur.execute(
                    """
                    UPDATE cars c
                    LEFT JOIN active_autocosmos_pe_urls a ON a.url = c.url
                    SET c.status=0
                    WHERE c.id_country=%s
                      AND c.nexo_id=%s
                      AND c.status=1
                      AND a.url IS NULL
                    """,
                    (COUNTRY, SOURCE),
                )
                cur.execute(
                    """
                    UPDATE cars c
                    JOIN active_autocosmos_pe_urls a ON a.url = c.url
                    SET c.status=1
                    WHERE c.id_country=%s
                      AND c.nexo_id=%s
                      AND c.status<>1
                    """,
                    (COUNTRY, SOURCE),
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
                (COUNTRY, SOURCE),
            )
            total, active, inactive = cur.fetchone()
            return {
                "active_urls": len(urls),
                "expired": expire_count,
                "reactivated": reactivate_count,
                "total": int(total or 0),
                "active": int(active or 0),
                "inactive": int(inactive or 0),
                "dry_run": dry_run,
            }
    finally:
        conn.close()


def main():
    parser = argparse.ArgumentParser(
        description="Sync Autocosmos Peru car status from catalog URLs in sitemap."
    )
    parser.add_argument("--sitemap-url", default=SITEMAP_URL)
    parser.add_argument("--min-active-urls", type=int, default=MIN_ACTIVE_URLS)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    urls = fetch_catalog_urls(args.sitemap_url)
    if len(urls) < args.min_active_urls:
        print(
            f"refusing to sync: only {len(urls)} active catalog URLs found "
            f"(min {args.min_active_urls})",
            file=sys.stderr,
        )
        return 2

    result = sync_status(urls, dry_run=args.dry_run)
    print(
        "Autocosmos PE sitemap status sync | "
        f"active_urls={result['active_urls']} "
        f"expired={result['expired']} "
        f"reactivated={result['reactivated']} "
        f"total={result['total']} "
        f"active={result['active']} "
        f"inactive={result['inactive']} "
        f"dry_run={int(result['dry_run'])}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
