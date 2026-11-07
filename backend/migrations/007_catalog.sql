CREATE TABLE catalog_products (
 id CHAR(32) PRIMARY KEY, sku VARCHAR(60) NOT NULL UNIQUE, slug VARCHAR(80) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL,
 category VARCHAR(40) NOT NULL, garment VARCHAR(40) NOT NULL, description TEXT NOT NULL, faq JSON NOT NULL,
 supplier_reference VARCHAR(160) NULL, min_order_quantity INT NULL, status VARCHAR(30) NOT NULL DEFAULT 'draft',
 verification_reference VARCHAR(200) NULL, verified_at DATETIME(6) NULL, published_at DATETIME(6) NULL,
 created_by CHAR(32) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 FOREIGN KEY(created_by) REFERENCES users(id), INDEX(status,category),
 CONSTRAINT product_moq CHECK (min_order_quantity IS NULL OR min_order_quantity >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE catalog_materials (
 id CHAR(32) PRIMARY KEY, kind VARCHAR(30) NOT NULL, supplier_code VARCHAR(80) NOT NULL, name VARCHAR(160) NOT NULL,
 composition VARCHAR(200) NULL, colour_name VARCHAR(80) NULL, colour_hex CHAR(7) NULL, pattern VARCHAR(80) NULL,
 weight_gsm INT NULL, weave VARCHAR(80) NULL, finish VARCHAR(80) NULL, stretch VARCHAR(80) NULL, care VARCHAR(500) NULL,
 suitable_garments JSON NOT NULL, supplier_reference VARCHAR(160) NULL, private_cost_minor INT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'draft', verification_reference VARCHAR(200) NULL, verified_at DATETIME(6) NULL,
 created_by CHAR(32) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 FOREIGN KEY(created_by) REFERENCES users(id), UNIQUE(kind,supplier_code), INDEX(kind,status),
 CONSTRAINT material_cost CHECK (private_cost_minor IS NULL OR private_cost_minor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE catalog_options (
 id CHAR(32) PRIMARY KEY, garment VARCHAR(40) NOT NULL, group_key VARCHAR(40) NOT NULL, label VARCHAR(120) NOT NULL,
 description VARCHAR(500) NULL, status VARCHAR(30) NOT NULL DEFAULT 'draft', verification_reference VARCHAR(200) NULL,
 verified_at DATETIME(6) NULL, created_by CHAR(32) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 FOREIGN KEY(created_by) REFERENCES users(id), UNIQUE(garment,group_key,label), INDEX(garment,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE catalog_links (
 product_id CHAR(32) NOT NULL, target_type VARCHAR(10) NOT NULL, target_id CHAR(32) NOT NULL, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(product_id,target_type,target_id), FOREIGN KEY(product_id) REFERENCES catalog_products(id), INDEX(target_type,target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE catalog_media (
 id CHAR(32) PRIMARY KEY, owner_type VARCHAR(10) NOT NULL, owner_id CHAR(32) NOT NULL, storage_name CHAR(64) NOT NULL,
 display_name CHAR(64) NULL, thumb_name CHAR(64) NULL, mime VARCHAR(40) NOT NULL, byte_size INT NOT NULL,
 width INT NOT NULL, height INT NOT NULL, sha256 CHAR(64) NOT NULL, alt_text VARCHAR(250) NOT NULL DEFAULT '',
 sort_order INT NOT NULL DEFAULT 0, featured TINYINT(1) NOT NULL DEFAULT 0, source VARCHAR(40) NOT NULL,
 licence_reference VARCHAR(200) NOT NULL, rights_holder VARCHAR(160) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending',
 created_by CHAR(32) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 FOREIGN KEY(created_by) REFERENCES users(id), INDEX(owner_type,owner_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
