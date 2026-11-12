import io
import os
import re
import unicodedata
from datetime import datetime
from urllib.parse import urlparse

import boto3
import mysql.connector
import requests
from dotenv import load_dotenv
from PIL import Image, ImageOps


class MySQLRoodosPipeline:
    def open_spider(self, spider):
        load_dotenv()
        self.conn = mysql.connector.connect(
            host=os.getenv("MYSQL_HOST", "localhost"),
            port=int(os.getenv("MYSQL_PORT", "3306")),
            user=os.getenv("MYSQL_USER", "root"),
            password=os.getenv("MYSQL_PASSWORD", ""),
            database=os.getenv("MYSQL_DATABASE", "roodos"),
            autocommit=False,
        )
        self.cur = self.conn.cursor(dictionary=True)
        self.inserted = 0
        self.updated = 0
        self.failed = 0

        self.make_by_norm = {}
        self.make_names_norm = []
        self.model_by_make_and_norm = {}
        self.model_names_by_make = {}
        self._load_catalogs()

        self.cdn_base = (os.getenv("CF_CDN_PUBLIC_BASE_URL") or "").rstrip("/")
        self.r2_bucket = os.getenv("CF_R2_BUCKET")
        self.r2_enabled = False
        self.r2_client = None
        try:
            account_id = os.getenv("CF_R2_ACCOUNT_ID")
            access_key = os.getenv("CF_R2_ACCESS_KEY_ID")
            secret_key = os.getenv("CF_R2_SECRET_ACCESS_KEY")
            region = os.getenv("CF_R2_REGION", "auto")
            if account_id and access_key and secret_key and self.r2_bucket and self.cdn_base:
                endpoint = f"https://{account_id}.r2.cloudflarestorage.com"
                self.r2_client = boto3.client(
                    "s3",
                    endpoint_url=endpoint,
                    aws_access_key_id=access_key,
                    aws_secret_access_key=secret_key,
                    region_name=region,
                )
                self.r2_enabled = True
        except Exception:
            self.r2_enabled = False

    def close_spider(self, spider):
        self.conn.commit()
        self.cur.close()
        self.conn.close()
        spider.logger.info(
            "MySQLRoodosPipeline done | inserted=%s updated=%s failed=%s",
            self.inserted,
            self.updated,
            self.failed,
        )

    def _norm(self, text):
        if not text:
            return ""
        t = str(text).strip().lower()
        t = unicodedata.normalize("NFKD", t)
        t = "".join(ch for ch in t if not unicodedata.combining(ch))
        t = re.sub(r"[^a-z0-9]+", " ", t)
        return " ".join(t.split())

    def _load_catalogs(self):
        # makes
        self.cur.execute("SELECT id_car_make, name FROM car_make")
        for row in self.cur.fetchall():
            make_id = row["id_car_make"]
            name = row["name"] or ""
            n = self._norm(name)
            if n and n not in self.make_by_norm:
                self.make_by_norm[n] = make_id

        self.make_names_norm = sorted(self.make_by_norm.keys(), key=len, reverse=True)

        # models
        self.cur.execute("SELECT id_car_model, id_car_make, name FROM car_model")
        for row in self.cur.fetchall():
            model_id = row["id_car_model"]
            make_id = row["id_car_make"]
            name = row["name"] or ""
            n = self._norm(name)
            if n:
                self.model_by_make_and_norm[(make_id, n)] = model_id
                self.model_names_by_make.setdefault(make_id, []).append((n, model_id))

        for make_id in list(self.model_names_by_make.keys()):
            self.model_names_by_make[make_id].sort(key=lambda x: len(x[0]), reverse=True)

    def _extract_make_from_text(self, text):
        ntext = self._norm(text)
        if not ntext:
            return None
        for mname in self.make_names_norm:
            if re.search(rf"(^|\s){re.escape(mname)}(\s|$)", ntext):
                return self.make_by_norm.get(mname)
        return None

    def _extract_model_for_make(self, make_id, text):
        ntext = self._norm(text)
        if not ntext or not make_id:
            return None
        candidates = self.model_names_by_make.get(make_id, [])
        for mname, mid in candidates:
            if re.search(rf"(^|\s){re.escape(mname)}(\s|$)", ntext):
                return mid
        return None

    def _resolve_make_model_ids(self, d):
        make_id = self._to_int(d.get("id_car_make"))
        model_id = self._to_int(d.get("id_car_model"))

        brand_name = d.get("brand_name")
        model_name = d.get("model_name")
        title = d.get("title") or ""
        details = d.get("details") or ""

        if not make_id and brand_name:
            make_id = self.make_by_norm.get(self._norm(brand_name))

        if not make_id:
            make_id = self._extract_make_from_text(title) or self._extract_make_from_text(details)

        if make_id and not model_id and model_name:
            model_id = self.model_by_make_and_norm.get((make_id, self._norm(model_name)))

        if make_id and not model_id:
            model_id = self._extract_model_for_make(make_id, title) or self._extract_model_for_make(make_id, details)

        return make_id, model_id

    def _is_cdn_url(self, url):
        if not url or not self.cdn_base:
            return False
        return str(url).startswith(self.cdn_base)

    def _absolutize_image_url(self, image_url, ad_url):
        if not image_url:
            return None
        image_url = str(image_url)
        if image_url.startswith("http://") or image_url.startswith("https://"):
            return image_url
        if image_url.startswith("//"):
            return "https:" + image_url
        if image_url.startswith("/"):
            if ad_url and (str(ad_url).startswith("http://") or str(ad_url).startswith("https://")):
                p = urlparse(str(ad_url))
                return f"{p.scheme}://{p.netloc}{image_url}"
            return f"https://autos.roodos.ec{image_url}"
        return image_url

    def _center_crop_150(self, image_bytes):
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

    def _upload_thumb_and_rewrite_images(self, d, spider):
        if not self.r2_enabled:
            return d

        ad_url = d.get("url")
        image = d.get("image")
        image_big = d.get("image_big")

        original = image_big if image_big and not self._is_cdn_url(image_big) else image
        original = self._absolutize_image_url(original, ad_url)

        if not original or self._is_cdn_url(original):
            return d

        try:
            r = requests.get(original, timeout=25, headers={"User-Agent": "Mozilla/5.0"})
            r.raise_for_status()
            thumb = self._center_crop_150(r.content)

            now = datetime.utcnow()
            country = (d.get("country") or "ec").lower()
            # Solo caracteres seguros: un "?" en el id (p. ej. Salfa) cortaba la URL pública y daba 404.
            name = re.sub(r"[^A-Za-z0-9_-]+", "-", str(d.get("external_id") or d.get("slug") or "item")).strip("-") or "item"
            key = f"autos/{country}/{now:%Y}/{now:%m}/{now:%d}/{name}_thumb_150.jpg"
            self.r2_client.upload_fileobj(thumb, self.r2_bucket, key, ExtraArgs={"ContentType": "image/jpeg"})

            d["image"] = f"{self.cdn_base}/{key}"
            d["image_big"] = original
            return d
        except Exception as e:
            spider.logger.warning("thumb upload failed for %s: %s", d.get("url"), e)
            return d

    def process_item(self, item, spider):
        d = dict(item)
        try:
            vertical = d.get("vertical")
            if vertical != "car":
                return item

            resolved_make_id, resolved_model_id = self._resolve_make_model_ids(d)

            now = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
            url = d.get("url")
            if not url:
                return item

            country_code = (d.get("country") or "EC")[:3]
            self.cur.execute(
                "SELECT id, image, image_big FROM cars WHERE id_country=%s AND url=%s LIMIT 1", (country_code, url)
            )
            existing = self.cur.fetchone()
            exists = existing is not None

            # Si el aviso ya tiene miniatura en el CDN y la imagen original no cambió, se reutiliza:
            # descargarla y subirla de nuevo en cada ejecución alargaba horas las fuentes grandes.
            original = (self._absolutize_image_url(d.get("image_big") or d.get("image"), url) or "")[:255]
            if existing and self._is_cdn_url(existing["image"]) and original and existing["image_big"] == original:
                d["image"], d["image_big"] = existing["image"], existing["image_big"]
            else:
                d = self._upload_thumb_and_rewrite_images(d, spider)

            sql = """
            INSERT INTO cars (
                id_country, url, slug, title, image, image_big,
                id_car_make, id_car_model, id_state, id_city, details,
                price, year, km, id_fuel, id_body, id_transmission,
                status, created, updated, nexo_id, nexo_flag
            ) VALUES (
                %s, %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s,
                1, NOW(), %s, %s, 0
            )
            ON DUPLICATE KEY UPDATE
                slug=VALUES(slug),
                title=VALUES(title),
                image=VALUES(image),
                image_big=VALUES(image_big),
                id_car_make=VALUES(id_car_make),
                id_car_model=VALUES(id_car_model),
                id_state=VALUES(id_state),
                id_city=VALUES(id_city),
                details=VALUES(details),
                price=VALUES(price),
                year=VALUES(year),
                km=VALUES(km),
                id_fuel=VALUES(id_fuel),
                id_body=VALUES(id_body),
                id_transmission=VALUES(id_transmission),
                updated=VALUES(updated),
                nexo_id=VALUES(nexo_id),
                status=1
            """
            slug_value = self._slugify(d.get("title") or d.get("slug") or "")

            source_name = (d.get("source_name") or "").lower()
            source_label = ""
            if "patiotuerca" in source_name:
                source_label = "Patiotuerca"
            elif "topmotors" in source_name:
                source_label = "Topmotors"
            elif "mercadolibre" in source_name:
                source_label = "Mercadolibre"
            elif "autocosmos" in source_name:
                source_label = "Autocosmos"
            elif "peruautos" in source_name:
                source_label = "Peruautos"
            elif "koopa" in source_name:
                source_label = "Koopa"
            elif "neoauto" in source_name:
                source_label = "NeoAuto"
            elif "autoland" in source_name:
                source_label = "Autoland"
            elif "tuauto" in source_name:
                source_label = "TuAuto"
            elif "autosell" in source_name:
                source_label = "Autosell"
            elif "wigo" in source_name:
                source_label = "Wigo"
            elif "leonford" in source_name:
                source_label = "LeonFord"
            elif "autosummit" in source_name:
                source_label = "AutoSummit"
            elif "brunofritsch" in source_name:
                source_label = "BrunoFritsch"
            elif "kovacs" in source_name:
                source_label = "Kovacs"
            elif "portillo" in source_name or "clicar" in source_name:
                source_label = "Portillo"
            elif "salfa" in source_name:
                source_label = "Salfa"
            elif "seminuevos" in source_name:
                source_label = "Seminuevos"

            params = (
                country_code,
                url[:250],
                slug_value,
                (self._utf8mb3_safe(d.get("title") or "Sin título") or "Sin título")[:250],
                (d.get("image") or "")[:255],
                (d.get("image_big") or d.get("image") or "")[:255],
                resolved_make_id,
                resolved_model_id,
                self._to_int(d.get("id_state")),
                self._to_int(d.get("id_city")),
                self._utf8mb3_safe(d.get("details")),
                self._to_int(d.get("price")),
                self._to_int(d.get("year")),
                self._to_int(d.get("km")),
                self._to_int(d.get("id_fuel")),
                self._to_int(d.get("id_body")),
                self._to_int(d.get("id_transmission")),
                now,
                source_label[:50],
            )
            self.cur.execute(sql, params)
            self.conn.commit()

            if exists:
                self.updated += 1
            else:
                self.inserted += 1
            return item
        except Exception:
            self.failed += 1
            self.conn.rollback()
            raise

    def _utf8mb3_safe(self, text):
        if text is None:
            return None
        s = str(text)
        # MySQL utf8mb3 cannot store 4-byte unicode (e.g. many emojis)
        return "".join(ch for ch in s if ord(ch) <= 0xFFFF)

    def _slugify(self, text):
        if not text:
            return ""
        t = self._utf8mb3_safe(text)
        t = str(t).strip().lower()
        t = unicodedata.normalize("NFKD", t)
        t = "".join(ch for ch in t if not unicodedata.combining(ch))
        t = re.sub(r"[^a-z0-9]+", "-", t)
        t = re.sub(r"-+", "-", t).strip("-")
        return t[:255]

    def _to_int(self, value):
        if value in (None, "", "-"):
            return None
        try:
            digits = "".join(ch for ch in str(value) if ch.isdigit())
            return int(digits) if digits else None
        except Exception:
            return None
