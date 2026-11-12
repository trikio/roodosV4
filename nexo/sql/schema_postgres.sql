CREATE DATABASE roodos_index;
\c roodos_index;

CREATE TABLE IF NOT EXISTS sources (
  id SERIAL PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  base_url VARCHAR(255) NOT NULL,
  vertical VARCHAR(10) NOT NULL CHECK (vertical IN ('car','house')),
  country CHAR(2) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS source_runs (
  id BIGSERIAL PRIMARY KEY,
  source_id INT NOT NULL REFERENCES sources(id),
  spider_name VARCHAR(100) NOT NULL,
  started_at TIMESTAMP NOT NULL,
  finished_at TIMESTAMP NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'running' CHECK (status IN ('running','ok','error')),
  inserted_count INT NOT NULL DEFAULT 0,
  updated_count INT NOT NULL DEFAULT 0,
  failed_count INT NOT NULL DEFAULT 0,
  error_text TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_source_runs_source_started ON source_runs(source_id, started_at);

CREATE TABLE IF NOT EXISTS raw_listings (
  id BIGSERIAL PRIMARY KEY,
  source_id INT NOT NULL REFERENCES sources(id),
  url VARCHAR(500) NOT NULL,
  content_hash CHAR(64) NOT NULL,
  payload_json JSONB NOT NULL,
  fetched_at TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_raw_source_fetched ON raw_listings(source_id, fetched_at);
CREATE INDEX IF NOT EXISTS idx_raw_hash ON raw_listings(content_hash);

CREATE TABLE IF NOT EXISTS cars (
  id BIGSERIAL PRIMARY KEY,
  id_country VARCHAR(3) NOT NULL,
  source_id INT NOT NULL REFERENCES sources(id),
  url VARCHAR(500) NOT NULL UNIQUE,
  slug VARCHAR(255),
  title VARCHAR(250) NOT NULL,
  image VARCHAR(255),
  image_big VARCHAR(255),
  id_car_make INT,
  id_car_model INT,
  id_state INT,
  id_city INT,
  details TEXT,
  price INT,
  year INT,
  km INT,
  id_fuel INT,
  id_body INT,
  id_transmission INT,
  external_id VARCHAR(120),
  active BOOLEAN NOT NULL DEFAULT TRUE,
  first_seen_at TIMESTAMP NOT NULL,
  last_seen_at TIMESTAMP NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_cars_source_external ON cars(source_id, external_id) WHERE external_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_cars_geo_price ON cars(id_country, id_state, id_city, price);
CREATE INDEX IF NOT EXISTS idx_cars_title ON cars(title);

CREATE TABLE IF NOT EXISTS houses (
  id BIGSERIAL PRIMARY KEY,
  id_country VARCHAR(3) NOT NULL,
  source_id INT NOT NULL REFERENCES sources(id),
  url VARCHAR(500) NOT NULL UNIQUE,
  slug VARCHAR(255),
  title VARCHAR(250) NOT NULL,
  image VARCHAR(255),
  image_big VARCHAR(255),
  id_house_operation INT,
  id_house_type INT,
  id_state INT,
  id_city INT,
  details TEXT,
  price INT,
  rooms INT,
  bath INT,
  size INT,
  external_id VARCHAR(120),
  active BOOLEAN NOT NULL DEFAULT TRUE,
  first_seen_at TIMESTAMP NOT NULL,
  last_seen_at TIMESTAMP NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_houses_source_external ON houses(source_id, external_id) WHERE external_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_houses_geo_price ON houses(id_country, id_state, id_city, price);
CREATE INDEX IF NOT EXISTS idx_houses_title ON houses(title);

INSERT INTO sources (name, base_url, vertical, country)
VALUES
('autos_roodos_cl', 'https://autos.roodos.cl', 'car', 'CL'),
('autos_roodos_ec', 'https://autos.roodos.ec', 'car', 'EC'),
('autos_roodos_pe', 'https://autos.roodos.pe', 'car', 'PE'),
('autos_roodos_mx', 'https://autos.roodos.mx', 'car', 'MX'),
('casas_roodos_cl', 'https://casas.roodos.cl', 'house', 'CL'),
('casas_roodos_ec', 'https://casas.roodos.ec', 'house', 'EC'),
('casas_roodos_pe', 'https://casas.roodos.pe', 'house', 'PE'),
('casas_roodos_mx', 'https://casas.roodos.mx', 'house', 'MX')
ON CONFLICT (name) DO NOTHING;
