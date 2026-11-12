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
from PIL import Image, ImageOps


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
        user=get_env("MYSQL_USER", required=False, default="root"),
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


def fetch_image(url, timeout=25):
    headers = {"User-Agent": "Mozilla/5.0"}
    r = requests.get(url, timeout=timeout, headers=headers)
    r.raise_for_status()
    return r.content


def is_cdn_url(url, cdn_base):
    if not url:
        return False
    return url.startswith(cdn_base.rstrip("/"))


def absolutize_image_url(image_url, ad_url):
    if not image_url:
        return image_url
    if image_url.startswith("http://") or image_url.startswith("https://"):
        return image_url
    if image_url.startswith("//"):
        return "https:" + image_url
    if image_url.startswith("/"):
        if ad_url and (ad_url.startswith("http://") or ad_url.startswith("https://")):
            parts = ad_url.split("/")
            if len(parts) >= 3:
                return parts[0] + "//" + parts[2] + image_url
        # fallback for legacy local paths
        return "https://autos.roodos.ec" + image_url
    return image_url


def process(limit=500, country="EC", dry_run=False, only_absolute=False):
    load_dotenv()

    cdn_base = get_env("CF_CDN_PUBLIC_BASE_URL")
    bucket = get_env("CF_R2_BUCKET")

    s3 = make_r2_client()
    conn = make_db_conn()
    cur = conn.cursor(dictionary=True)

    # Pick rows not yet in CDN (supports image empty + original in image_big)
    cdn_prefix = f"{cdn_base.rstrip('/')}%"
    abs_clause = " AND ((image LIKE 'http%') OR (image_big LIKE 'http%'))" if only_absolute else ""
    cur.execute(
        f"""
        SELECT id, url, image, image_big
        FROM cars
        WHERE id_country=%s
          AND (
                (image IS NOT NULL AND image <> '' AND image NOT LIKE %s)
             OR ((image IS NULL OR image='') AND image_big IS NOT NULL AND image_big <> '' AND image_big NOT LIKE %s)
          )
          {abs_clause}
        ORDER BY id ASC
        LIMIT %s
        """,
        (country, cdn_prefix, cdn_prefix, limit),
    )
    rows = cur.fetchall()

    ok = 0
    skipped = 0
    failed = 0

    for row in rows:
        cid = row["id"]
        ad_url = row.get("url") or ""
        image = row.get("image") or ""
        image_big = row.get("image_big") or ""

        original = image_big if image_big and not is_cdn_url(image_big, cdn_base) else image
        original = absolutize_image_url(original, ad_url)

        if not original:
            skipped += 1
            continue

        if is_cdn_url(image, cdn_base) and image_big:
            skipped += 1
            continue

        try:
            raw = fetch_image(original)
            thumb = center_crop_150(raw)

            now = datetime.utcnow()
            key = f"autos/ec/{now:%Y}/{now:%m}/{now:%d}/{cid}_thumb_150.jpg"
            final_url = f"{cdn_base.rstrip('/')}/{key}"

            if not dry_run:
                s3.upload_fileobj(
                    thumb,
                    bucket,
                    key,
                    ExtraArgs={"ContentType": "image/jpeg"},
                )

                # image = 150x150 CDN URL
                # image_big = original listing URL
                cur.execute(
                    """
                    UPDATE cars
                    SET image=%s,
                        image_big=%s,
                        updated=%s
                    WHERE id=%s
                    """,
                    (final_url, original[:255], datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S"), cid),
                )

            ok += 1
            if ok % 100 == 0 and not dry_run:
                conn.commit()
        except Exception as e:
            failed += 1
            print(f"[ERR] id={cid} url={original} err={e}", file=sys.stderr)

    if not dry_run:
        conn.commit()

    cur.close()
    conn.close()

    print(f"done ok={ok} skipped={skipped} failed={failed} total={len(rows)}")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--limit", type=int, default=500)
    parser.add_argument("--country", default="EC")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--only-absolute", action="store_true")
    args = parser.parse_args()

    process(limit=args.limit, country=args.country, dry_run=args.dry_run, only_absolute=args.only_absolute)


if __name__ == "__main__":
    main()
