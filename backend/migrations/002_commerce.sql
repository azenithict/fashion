CREATE TABLE quotes (
 id CHAR(32) PRIMARY KEY,
 design_id CHAR(32) NOT NULL,
 user_id CHAR(32) NOT NULL,
 version INT UNSIGNED NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'CAD',
 subtotal_minor BIGINT NOT NULL,
 tax_minor BIGINT NOT NULL,
 total_minor BIGINT NOT NULL,
 deposit_minor BIGINT NOT NULL,
 estimated_cost_minor BIGINT NOT NULL,
 tax_bps INT NOT NULL,
 `lines` JSON NOT NULL,
 specification JSON NOT NULL,
 delivery_terms TEXT NOT NULL,
 terms TEXT NOT NULL,
 approval_reference VARCHAR(500) NOT NULL,
 expires_at DATETIME(6) NOT NULL,
 accepted_at DATETIME(6) NULL,
 created_by CHAR(32) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 UNIQUE KEY quote_design_version(design_id,version),
 FOREIGN KEY(design_id) REFERENCES designs(id),
 FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(created_by) REFERENCES users(id),
 CHECK(currency='CAD'),
 CHECK(subtotal_minor > 0 AND tax_minor >= 0 AND total_minor=subtotal_minor+tax_minor),
 CHECK(deposit_minor >= 0 AND deposit_minor <= total_minor),
 CHECK(estimated_cost_minor >= 0 AND tax_bps BETWEEN 0 AND 10000),
 INDEX quote_customer(user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE orders (
 id CHAR(32) PRIMARY KEY,
 user_id CHAR(32) NOT NULL,
 quote_id CHAR(32) NOT NULL UNIQUE,
 design_id CHAR(32) NOT NULL UNIQUE,
 status VARCHAR(40) NOT NULL DEFAULT 'awaiting_payment',
 currency CHAR(3) NOT NULL DEFAULT 'CAD',
 subtotal_minor BIGINT NOT NULL,
 tax_minor BIGINT NOT NULL,
 total_minor BIGINT NOT NULL,
 deposit_minor BIGINT NOT NULL,
 snapshot JSON NOT NULL,
 accepted_terms_at DATETIME(6) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 updated_at DATETIME(6) NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(quote_id) REFERENCES quotes(id),
 FOREIGN KEY(design_id) REFERENCES designs(id),
 CHECK(currency='CAD'),
 CHECK(total_minor > 0 AND total_minor=subtotal_minor+tax_minor),
 INDEX orders_customer(user_id,created_at), INDEX orders_status(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE invoices (
 id CHAR(32) PRIMARY KEY,
 order_id CHAR(32) NOT NULL UNIQUE,
 invoice_number VARCHAR(50) NOT NULL UNIQUE,
 currency CHAR(3) NOT NULL DEFAULT 'CAD',
 subtotal_minor BIGINT NOT NULL,
 tax_minor BIGINT NOT NULL,
 total_minor BIGINT NOT NULL,
 snapshot JSON NOT NULL,
 issued_at DATETIME(6) NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id),
 CHECK(currency='CAD' AND total_minor=subtotal_minor+tax_minor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE payments (
 id CHAR(32) PRIMARY KEY,
 order_id CHAR(32) NOT NULL,
 provider VARCHAR(20) NOT NULL,
 provider_reference VARCHAR(255) NOT NULL UNIQUE,
 provider_intent VARCHAR(255) NULL UNIQUE,
 kind VARCHAR(20) NOT NULL,
 amount_minor BIGINT NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'CAD',
 evidence TEXT NOT NULL,
 recorded_by CHAR(32) NULL,
 created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id),
 FOREIGN KEY(recorded_by) REFERENCES users(id),
 CHECK(amount_minor > 0 AND currency='CAD'),
 INDEX payments_order(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE refunds (
 id CHAR(32) PRIMARY KEY,
 order_id CHAR(32) NOT NULL,
 payment_id CHAR(32) NOT NULL,
 provider_reference VARCHAR(255) NOT NULL UNIQUE,
 amount_minor BIGINT NOT NULL,
 reason TEXT NOT NULL,
 evidence TEXT NOT NULL,
 recorded_by CHAR(32) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id),
 FOREIGN KEY(payment_id) REFERENCES payments(id),
 FOREIGN KEY(recorded_by) REFERENCES users(id),
 CHECK(amount_minor > 0), INDEX refunds_order(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE checkout_attempts (
 id CHAR(32) PRIMARY KEY,
 order_id CHAR(32) NOT NULL,
 kind VARCHAR(20) NOT NULL,
 amount_minor BIGINT NOT NULL,
 paid_before_minor BIGINT NOT NULL,
 stripe_session_id VARCHAR(255) NULL UNIQUE,
 checkout_url TEXT NULL,
 expires_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id),
 CHECK(amount_minor > 0), INDEX checkout_order(order_id,kind,paid_before_minor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE payment_events (
 provider_event_id VARCHAR(255) PRIMARY KEY,
 event_type VARCHAR(100) NOT NULL,
 payload_sha256 CHAR(64) NOT NULL,
 processed_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE order_events (
 id CHAR(32) PRIMARY KEY,
 order_id CHAR(32) NOT NULL,
 status VARCHAR(40) NOT NULL,
 note TEXT NOT NULL,
 evidence TEXT NOT NULL,
 actor_id CHAR(32) NULL,
 created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id),
 FOREIGN KEY(actor_id) REFERENCES users(id),
 INDEX order_history(order_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE production_jobs (
 id CHAR(32) PRIMARY KEY,
 order_id CHAR(32) NOT NULL UNIQUE,
 supplier_reference VARCHAR(500) NOT NULL,
 specification TEXT NOT NULL,
 acknowledgement TEXT NOT NULL,
 due_at DATETIME(6) NOT NULL,
 created_by CHAR(32) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE quality_inspections (
 sequence_no BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
 id CHAR(32) PRIMARY KEY,
 order_id CHAR(32) NOT NULL,
 result VARCHAR(10) NOT NULL,
 report TEXT NOT NULL,
 evidence TEXT NOT NULL,
 created_by CHAR(32) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(created_by) REFERENCES users(id),
 CHECK(result IN ('pass','fail')), INDEX qc_order(order_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE shipments (
 id CHAR(32) PRIMARY KEY,
 order_id CHAR(32) NOT NULL,
 carrier VARCHAR(200) NOT NULL,
 tracking VARCHAR(200) NOT NULL,
 stage VARCHAR(100) NOT NULL,
 note TEXT NOT NULL,
 created_by CHAR(32) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(created_by) REFERENCES users(id),
 INDEX shipments_order(order_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE order_expenses (
 id CHAR(32) PRIMARY KEY,
 order_id CHAR(32) NOT NULL,
 category VARCHAR(100) NOT NULL,
 amount_minor BIGINT NOT NULL,
 evidence TEXT NOT NULL,
 created_by CHAR(32) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(created_by) REFERENCES users(id),
 CHECK(amount_minor > 0), INDEX expenses_order(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
