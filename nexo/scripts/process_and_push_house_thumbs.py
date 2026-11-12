#!/usr/bin/env python3
import argparse
import io
import os
import sys
from datetime import datetime

import boto3
import mysql.connector
import requests
from dotenv import load_dotenv
from PIL import Image, ImageOps, UnidentifiedImageError


def get_env(name, required=True, default=None):
    v = os.getenv(name, default)
    if required and not v:
        raise RuntimeError(f"Missing env var: {name}")
    return v


def make_r2_client():
    account_id = get_env("CF_R2_ACCOUNT_ID")
    access_key = get_env("CF_R2_ACCESS_KEY_ID")
    secret_key = get_env("CF_R2_SECRET_ACCESS_KEY")
    region = os.getenv("CF_R2_REGION", "auto")
    endpoint = f"https://{account_id}.r2.cloudflarestorage.com"
    return boto3.client(
        "s3",
        endpoint_url=endpoint,
        aws_access_key_id=access_key,
        aws_secret_access_key=secret_key,
        region_name=region,
    )


def make_db_conn():
    return mysql.connector.connect(
        host=get_env("MYSQL_HOST", required=False, default="localhost"),
        port=int(get_env("MYSQL_PORT", required=False, default="3306")),
        user=get_env("MYSQL_USER", required=False, default="trikio"),
        password=get_env("MYSQL_PASSWORD", required=False, default=""),
        database=get_env("MYSQL_DATABASE", required=False, default="roodos"),
        autocommit=False,
    )


def center_crop_150(image_bytes):
    img = Image.open(io.BytesIO(image_bytes)).convert("RGB")
    img = ImageOps.exif_transpose(img)
    w, h = img.size
    side = min(w, h)
    left = (w - side) // 2
    top = (h - side) // 2
    img = img.crop((left, top, left + side, top + side))
    img = img.resize((150, 150), Image.Resampling.LANCZOS)
    out = io.BytesIO()
    img.save(out, format="JPEG", quality=85, optimize=True)
    out.seek(0)
    return out


def fetch_image(url, timeout=12):
    r = requests.get(url, timeout=timeout, headers={"User-Agent": "Mozilla/5.0"})
    r.raise_for_status()
    return r.content


def is_cdn_url(url, cdn_base):
    return bool(url and url.startswith(cdn_base.rstrip("/")))


def mark_source_unavailable(cur, house_id):
    cur.execute(
        """
        UPDATE houses
        SET image='',
            image_big=NULL,
            updated=%s
        WHERE id=%s
        """,
        (datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S"), house_id),
    )


def process(limit=5000, country="lab", dry_run=False):
    load_dotenv('/home/trikio/roodos_v4/nexo/.env')

    cdn_base = get_env("CF_CDN_PUBLIC_BASE_URL")
    bucket = get_env("CF_R2_BUCKET")
    s3 = make_r2_client()

    conn = make_db_conn()
    cur = conn.cursor(dictionary=True)

    cdn_prefix = f"{cdn_base.rstrip('/')}%"
    cur.execute(
        """
        SELECT id, url, image, image_big, id_country,
               CASE
                 WHEN image IS NOT NULL AND image<>'' AND image LIKE 'http%' AND image NOT LIKE %s THEN image
                 WHEN (image IS NULL OR image='') AND image_big IS NOT NULL AND image_big<>'' AND image_big LIKE 'http%' AND image_big NOT LIKE %s THEN image_big
                 ELSE NULL
               END AS source_url
        FROM houses
        WHERE id_country=%s
          AND (
            (image IS NOT NULL AND image<>'' AND image LIKE 'http%' AND image NOT LIKE %s)
            OR ((image IS NULL OR image='') AND image_big IS NOT NULL AND image_big<>'' AND image_big LIKE 'http%' AND image_big NOT LIKE %s)
          )
        ORDER BY id ASC
        LIMIT %s
        """,
        (cdn_prefix, cdn_prefix, country, cdn_prefix, cdn_prefix, limit),
    )
    rows = cur.fetchall()

    ok = skipped = failed = unavailable = 0
    for idx, row in enumerate(rows, 1):
        hid = row["id"]
        original = (row.get("source_url") or "")[:255]
        if not original or is_cdn_url(original, cdn_base):
            skipped += 1
            continue

        try:
            raw = fetch_image(original)
            try:
                thumb = center_crop_150(raw)
            except (OSError, UnidentifiedImageError) as e:
                failed += 1
                unavailable += 1
                if not dry_run:
                    mark_source_unavailable(cur, hid)
                    if unavailable % 200 == 0:
                        conn.commit()
                if unavailable <= 10 or unavailable % 100 == 0:
                    print(f"[UNAVAILABLE] id={hid} invalid_image url={original} err={e}", file=sys.stderr)
                continue
            now = datetime.utcnow()
            ctry = (row.get("id_country") or country).lower()
            key = f"houses/{ctry}/{now:%Y}/{now:%m}/{now:%d}/{hid}_thumb_150.jpg"
            final_url = f"{cdn_base.rstrip('/')}/{key}"

            if not dry_run:
                s3.upload_fileobj(thumb, bucket, key, ExtraArgs={"ContentType": "image/jpeg"})
                cur.execute(
                    """
                    UPDATE houses
                    SET image=%s,
                        image_big=%s,
                        updated=%s
                    WHERE id=%s
                    """,
                    (final_url, original, datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S"), hid),
                )
            ok += 1
            if ok % 200 == 0 and not dry_run:
                conn.commit()
                print(f"progress ok={ok} failed={failed} checked={idx}/{len(rows)}")
        except requests.HTTPError as e:
            failed += 1
            status = e.response.status_code if e.response is not None else None
            if status in (404, 410):
                unavailable += 1
                if not dry_run:
                    mark_source_unavailable(cur, hid)
                    if unavailable % 200 == 0:
                        conn.commit()
                if unavailable <= 10 or unavailable % 100 == 0:
                    print(f"[UNAVAILABLE] id={hid} status={status} url={original}", file=sys.stderr)
            else:
                print(f"[ERR] id={hid} url={original} err={e}", file=sys.stderr)
        except Exception as e:
            failed += 1
            print(f"[ERR] id={hid} url={original} err={e}", file=sys.stderr)

    if not dry_run:
        conn.commit()
    cur.close()
    conn.close()
    print(f"done ok={ok} skipped={skipped} failed={failed} unavailable={unavailable} total={len(rows)}")


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=5000)
    ap.add_argument("--country", default="lab")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()
    process(limit=args.limit, country=args.country, dry_run=args.dry_run)
