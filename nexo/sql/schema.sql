CREATE DATABASE IF NOT EXISTS roodos_index CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE roodos_index;

CREATE TABLE IF NOT EXISTS sources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  base_url VARCHAR(255) NOT NULL,
  vertical ENUM('car','house') NOT NULL,
  country CHAR(2) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_source_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS source_runs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  source_id INT NOT NULL,
  spider_name VARCHAR(100) NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status ENUM('running','ok','error') NOT NULL DEFAULT 'running',
  inserted_count INT NOT NULL DEFAULT 0,
  updated_count INT NOT NULL DEFAULT 0,
  failed_count INT NOT NULL DEFAULT 0,
  error_text TEXT NULL,
  INDEX idx_source_runs_source_started (source_id, started_at),
  CONSTRAINT fk_source_runs_source FOREIGN KEY (source_id) REFERENCES sources(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS raw_listings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  source_id INT NOT NULL,
  url VARCHAR(500) NOT NULL,
  content_hash CHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  fetched_at DATETIME NOT NULL,
  INDEX idx_raw_source_fetched (source_id, fetched_at),
  INDEX idx_raw_hash (content_hash),
  CONSTRAINT fk_raw_source FOREIGN KEY (source_id) REFERENCES sources(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cars (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  id_country VARCHAR(3) NOT NULL,
  source_id INT NOT NULL,
  url VARCHAR(500) NOT NULL,
  slug VARCHAR(255) NULL,
  title VARCHAR(250) NOT NULL,
  image VARCHAR(255) NULL,
  image_big VARCHAR(255) NULL,
  id_car_make INT NULL,
  id_car_model INT NULL,
  id_state INT NULL,
  id_city INT NULL,
  details TEXT NULL,
  price INT UNSIGNED NULL,
  year INT UNSIGNED NULL,
  km INT UNSIGNED NULL,
  id_fuel INT NULL,
  id_body INT NULL,
  id_transmission INT NULL,
  external_id VARCHAR(120) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cars_url (url),
  UNIQUE KEY uq_cars_source_external (source_id, external_id),
  INDEX idx_cars_geo_price (id_country, id_state, id_city, price),
  INDEX idx_cars_title (title),
  CONSTRAINT fk_cars_source FOREIGN KEY (source_id) REFERENCES sources(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS houses (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  id_country VARCHAR(3) NOT NULL,
  source_id INT NOT NULL,
  url VARCHAR(500) NOT NULL,
  slug VARCHAR(255) NULL,
  title VARCHAR(250) NOT NULL,
  image VARCHAR(255) NULL,
  image_big VARCHAR(255) NULL,
  id_house_operation INT NULL,
  id_house_type INT NULL,
  id_state INT NULL,
  id_city INT NULL,
  details TEXT NULL,
  price INT UNSIGNED NULL,
  rooms INT UNSIGNED NULL,
  bath INT UNSIGNED NULL,
  size INT UNSIGNED NULL,
  external_id VARCHAR(120) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_houses_url (url),
  UNIQUE KEY uq_houses_source_external (source_id, external_id),
  INDEX idx_houses_geo_price (id_country, id_state, id_city, price),
  INDEX idx_houses_title (title),
  CONSTRAINT fk_houses_source FOREIGN KEY (source_id) REFERENCES sources(id)
) ENGINE=InnoDB;

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
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;
