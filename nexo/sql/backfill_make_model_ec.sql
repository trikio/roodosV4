-- Backfill make/model IDs for Ecuador cars using already stored data (title/details/url)
-- Usage:
-- mysql -h localhost -u root -p -D roodos < sql/backfill_make_model_ec.sql

-- 1) Fast make mapping for Patiotuerca URLs: /vehicle/autos-{brand}-{model}-...
UPDATE cars c
JOIN (
  SELECT id,
         LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(url, '/vehicle/autos-', -1), '-', 1)) AS brand_slug
  FROM cars
  WHERE id_country='EC'
    AND id_car_make IS NULL
    AND url LIKE 'https://ecuador.patiotuerca.com/vehicle/%'
) x ON x.id = c.id
JOIN car_make cm ON LOWER(REPLACE(cm.name,' ','_')) = x.brand_slug
SET c.id_car_make = cm.id_car_make
WHERE c.id_country='EC'
  AND c.id_car_make IS NULL;

-- 2) Generic make mapping by keyword in title/details (longest make name wins)
UPDATE cars c
SET c.id_car_make = (
  SELECT cm.id_car_make
  FROM car_make cm
  WHERE LOCATE(LOWER(cm.name), LOWER(CONCAT(' ', c.title, ' ', IFNULL(c.details,''), ' '))) > 0
  ORDER BY CHAR_LENGTH(cm.name) DESC
  LIMIT 1
)
WHERE c.id_country='EC'
  AND c.id_car_make IS NULL;

-- 3) Model mapping constrained by already-known make, using keyword in title/details/url
UPDATE cars c
SET c.id_car_model = (
  SELECT m.id_car_model
  FROM car_model m
  WHERE m.id_car_make = c.id_car_make
    AND LOCATE(LOWER(m.name), LOWER(CONCAT(' ', c.title, ' ', IFNULL(c.details,''), ' ', c.url, ' '))) > 0
  ORDER BY CHAR_LENGTH(m.name) DESC
  LIMIT 1
)
WHERE c.id_country='EC'
  AND c.id_car_make IS NOT NULL
  AND c.id_car_model IS NULL;

-- 4) Report
SELECT 'EC_TOTAL' src, COUNT(*) total, SUM(id_car_make IS NULL) null_make, SUM(id_car_model IS NULL) null_model
FROM cars WHERE id_country='EC'
UNION ALL
SELECT 'patiotuerca', COUNT(*), SUM(id_car_make IS NULL), SUM(id_car_model IS NULL)
FROM cars WHERE url LIKE 'https://ecuador.patiotuerca.com/%'
UNION ALL
SELECT 'topmotors', COUNT(*), SUM(id_car_make IS NULL), SUM(id_car_model IS NULL)
FROM cars WHERE url LIKE 'https://www.topmotorsusados.com/%'
UNION ALL
SELECT 'mercadolibre', COUNT(*), SUM(id_car_make IS NULL), SUM(id_car_model IS NULL)
FROM cars WHERE url LIKE 'https://auto.mercadolibre.com.ec/%' OR url LIKE 'https://autos.mercadolibre.com.ec/%'
UNION ALL
SELECT 'autocosmos', COUNT(*), SUM(id_car_make IS NULL), SUM(id_car_model IS NULL)
FROM cars WHERE url LIKE 'https://www.autocosmos.com.ec/%';
