CREATE TABLE enquiries (
 id CHAR(32) PRIMARY KEY, reference VARCHAR(16) NOT NULL UNIQUE, status VARCHAR(30) NOT NULL DEFAULT 'new',
 service VARCHAR(40) NOT NULL, name VARCHAR(100) NOT NULL, email VARCHAR(254) NOT NULL, phone VARCHAR(40) NULL,
 contact_method VARCHAR(10) NOT NULL, garment VARCHAR(160) NULL, quantity INT NULL, deadline DATE NULL,
 location VARCHAR(120) NULL, requirements TEXT NOT NULL, source JSON NOT NULL, consent_at DATETIME(6) NOT NULL,
 user_id CHAR(32) NULL, assigned_to CHAR(32) NULL, closed_reason VARCHAR(500) NULL,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(assigned_to) REFERENCES users(id),
 INDEX(status,created_at), INDEX(email,created_at),
 CONSTRAINT enquiry_quantity CHECK (quantity IS NULL OR quantity BETWEEN 1 AND 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE enquiry_events (
 id CHAR(32) PRIMARY KEY, seq BIGINT NOT NULL AUTO_INCREMENT UNIQUE, enquiry_id CHAR(32) NOT NULL, actor_id CHAR(32) NULL, kind VARCHAR(20) NOT NULL,
 from_status VARCHAR(30) NULL, to_status VARCHAR(30) NULL, note TEXT NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(enquiry_id) REFERENCES enquiries(id), FOREIGN KEY(actor_id) REFERENCES users(id), INDEX(enquiry_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE notification_outbox MODIFY user_id CHAR(32) NULL, ADD COLUMN recipient_email VARCHAR(254) NULL AFTER user_id,
 ADD CONSTRAINT outbox_recipient CHECK (user_id IS NOT NULL OR recipient_email IS NOT NULL);
