-- Base de monitoreo de nexo, separada de `roodos` (que es la que lee producción).
-- Se aplica con `bin/nexo db-init`; es idempotente.

CREATE DATABASE IF NOT EXISTS nexo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Copia de nexo/monitor/sources.py, para cruzar con las demás tablas.
CREATE TABLE IF NOT EXISTS nexo.sources (
  id            VARCHAR(64)  NOT NULL PRIMARY KEY,
  vertical      VARCHAR(10)  NOT NULL,
  country       VARCHAR(3)   NOT NULL,
  name          VARCHAR(100) NOT NULL,
  hosts         VARCHAR(500) NOT NULL,
  schedule      VARCHAR(50)  NOT NULL,
  full_crawl    TINYINT(1)   NOT NULL DEFAULT 0,
  enabled       TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Una fila por ejecución de una fuente.
CREATE TABLE IF NOT EXISTS nexo.runs (
  id            BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_id     VARCHAR(64)  NOT NULL,
  started_at    DATETIME     NOT NULL,
  finished_at   DATETIME     NULL,
  exit_code     INT          NULL,
  outcome       VARCHAR(10)  NULL,  -- ok | empty | drop | failed
  seen          INT          NULL,  -- avisos que la ejecución tocó (nuevos + actualizados)
  inserted      INT          NULL,
  log_errors    INT          NULL,
  finish_reason VARCHAR(50)  NULL,
  error_excerpt VARCHAR(500) NULL,
  KEY idx_runs_source (source_id, started_at)
);

-- Foto diaria por fuente.
CREATE TABLE IF NOT EXISTS nexo.daily_stats (
  day             DATE        NOT NULL,
  source_id       VARCHAR(64) NOT NULL,
  active          INT         NOT NULL,
  seen_24h        INT         NOT NULL,
  new_24h         INT         NOT NULL,
  stale           INT         NOT NULL,  -- activos sin verse en EXPIRE_DAYS
  deactivated     INT         NOT NULL,
  images_checked  INT         NOT NULL,
  images_broken   INT         NOT NULL,
  last_seen       DATETIME    NULL,      -- último aviso de la fuente que se vio
  PRIMARY KEY (day, source_id)
);

-- Alertas abiertas y cerradas; solo puede haber una abierta por (kind, source_id).
CREATE TABLE IF NOT EXISTS nexo.alerts (
  id           BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind         VARCHAR(20)  NOT NULL,  -- run | overdue | images | tunnel | disk
  source_id    VARCHAR(64)  NOT NULL DEFAULT '',
  message      VARCHAR(1000) NOT NULL,
  opened_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at  DATETIME     NULL,
  KEY idx_alerts_open (resolved_at, kind, source_id)
);

-- Revisión por muestreo de las imágenes de los avisos activos.
CREATE TABLE IF NOT EXISTS nexo.image_checks (
  id          BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_id   VARCHAR(64)  NOT NULL,
  car_id      INT          NOT NULL,
  field       VARCHAR(10)  NOT NULL,  -- image (miniatura en R2) | image_big (original en la fuente)
  url         VARCHAR(255) NOT NULL,
  http_status INT          NULL,
  ok          TINYINT(1)   NOT NULL,
  checked_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_image_checks (checked_at, source_id)
);

-- Sitios de autos encontrados en internet que todavía no son fuente.
CREATE TABLE IF NOT EXISTS nexo.candidates (
  id          BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
  host        VARCHAR(190) NOT NULL UNIQUE,
  country     VARCHAR(3)   NOT NULL,
  url         VARCHAR(500) NOT NULL,
  score       INT          NOT NULL,
  query       VARCHAR(200) NOT NULL,
  status      VARCHAR(12)  NOT NULL DEFAULT 'new',  -- new | adapting | review | approved | rejected
  notes       TEXT         NULL,
  first_seen  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
