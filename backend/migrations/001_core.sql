CREATE TABLE users (
 id CHAR(32) PRIMARY KEY, name VARCHAR(120) NOT NULL, email VARCHAR(254) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL, role ENUM('customer','operations','admin','partner') NOT NULL DEFAULT 'customer',
 phone VARCHAR(40) NULL, email_verified_at DATETIME(6) NULL, session_version INT NOT NULL DEFAULT 1,
 disabled_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE auth_tokens (
 id CHAR(32) PRIMARY KEY, user_id CHAR(32) NOT NULL, purpose ENUM('verify','reset') NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME(6) NOT NULL, consumed_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id), INDEX(user_id,purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE rate_limits (
 bucket CHAR(64) PRIMARY KEY, hits INT NOT NULL, expires_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE addresses (
 id CHAR(32) PRIMARY KEY, user_id CHAR(32) NOT NULL, label VARCHAR(100) NOT NULL,
 recipient VARCHAR(120) NOT NULL, line1 VARCHAR(200) NOT NULL, line2 VARCHAR(200) NOT NULL DEFAULT '',
 city VARCHAR(100) NOT NULL, province VARCHAR(100) NOT NULL, postal_code VARCHAR(20) NOT NULL,
 country CHAR(2) NOT NULL, created_at DATETIME(6) NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id), INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE measurements (
 id CHAR(32) PRIMARY KEY, user_id CHAR(32) NOT NULL, name VARCHAR(120) NOT NULL,
 garment VARCHAR(30) NOT NULL, unit ENUM('cm','in') NOT NULL, values_json JSON NOT NULL,
 provenance ENUM('self','partner') NOT NULL DEFAULT 'self', consent_at DATETIME(6) NOT NULL,
 verified_at DATETIME(6) NULL, verified_by CHAR(32) NULL, verification_evidence TEXT NULL,
 created_at DATETIME(6) NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(verified_by) REFERENCES users(id), INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE designs (
 id CHAR(32) PRIMARY KEY, user_id CHAR(32) NOT NULL, name VARCHAR(120) NOT NULL,
 status ENUM('draft','submitted','revision_requested','approved','quoted','ordered') NOT NULL DEFAULT 'draft',
 specification JSON NOT NULL, measurement_id CHAR(32) NULL, revision INT NOT NULL DEFAULT 1,
 submitted_at DATETIME(6) NULL, review_notes TEXT NULL, reviewed_by CHAR(32) NULL,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(measurement_id) REFERENCES measurements(id),
 FOREIGN KEY(reviewed_by) REFERENCES users(id), INDEX(user_id,status), INDEX(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE design_versions (
 id CHAR(32) PRIMARY KEY, design_id CHAR(32) NOT NULL, revision INT NOT NULL,
 specification JSON NOT NULL, measurements_snapshot JSON NOT NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(design_id) REFERENCES designs(id), UNIQUE(design_id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE uploads (
 id CHAR(32) PRIMARY KEY, design_id CHAR(32) NOT NULL, user_id CHAR(32) NOT NULL,
 original_name VARCHAR(200) NOT NULL, storage_name CHAR(64) NOT NULL UNIQUE,
 mime VARCHAR(80) NOT NULL, byte_size INT NOT NULL, sha256 CHAR(64) NOT NULL,
 created_at DATETIME(6) NOT NULL, FOREIGN KEY(design_id) REFERENCES designs(id),
 FOREIGN KEY(user_id) REFERENCES users(id), INDEX(design_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE support_cases (
 id CHAR(32) PRIMARY KEY, user_id CHAR(32) NOT NULL, order_id CHAR(32) NULL,
 subject VARCHAR(180) NOT NULL, status ENUM('open','closed') NOT NULL DEFAULT 'open',
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), INDEX(user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE support_messages (
 id CHAR(32) PRIMARY KEY, case_id CHAR(32) NOT NULL, author_id CHAR(32) NOT NULL,
 message TEXT NOT NULL, internal BOOLEAN NOT NULL DEFAULT FALSE, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(case_id) REFERENCES support_cases(id), FOREIGN KEY(author_id) REFERENCES users(id), INDEX(case_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE notifications (
 id CHAR(32) PRIMARY KEY, user_id CHAR(32) NOT NULL, event VARCHAR(80) NOT NULL,
 payload JSON NOT NULL, read_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), INDEX(user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE notification_outbox (
 id CHAR(32) PRIMARY KEY, user_id CHAR(32) NOT NULL, event VARCHAR(80) NOT NULL,
 payload JSON NOT NULL, attempts INT NOT NULL DEFAULT 0, next_attempt_at DATETIME(6) NOT NULL,
 locked_at DATETIME(6) NULL, sent_at DATETIME(6) NULL, last_error VARCHAR(200) NULL,
 created_at DATETIME(6) NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id), INDEX(sent_at,next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE audit_events (
 id CHAR(32) PRIMARY KEY, actor_id CHAR(32) NULL, action VARCHAR(100) NOT NULL,
 entity VARCHAR(60) NOT NULL, entity_id VARCHAR(100) NOT NULL, details JSON NOT NULL,
 created_at DATETIME(6) NOT NULL, FOREIGN KEY(actor_id) REFERENCES users(id), INDEX(entity,entity_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE deletion_requests (
 id CHAR(32) PRIMARY KEY, user_id CHAR(32) NOT NULL UNIQUE, status ENUM('pending','reviewed','completed') NOT NULL DEFAULT 'pending',
 created_at DATETIME(6) NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TRIGGER design_versions_no_update BEFORE UPDATE ON design_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Submitted design snapshots are immutable';
CREATE TRIGGER design_versions_no_delete BEFORE DELETE ON design_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Submitted design snapshots are immutable';
CREATE TRIGGER audit_events_no_update BEFORE UPDATE ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit events are append-only';
CREATE TRIGGER audit_events_no_delete BEFORE DELETE ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit events are append-only';
