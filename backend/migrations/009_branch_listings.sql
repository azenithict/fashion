CREATE TABLE branch_listings (
 branch_id CHAR(32) PRIMARY KEY, address_line VARCHAR(200) NOT NULL, city VARCHAR(80) NOT NULL, province CHAR(2) NOT NULL,
 postal_code VARCHAR(10) NOT NULL, services JSON NOT NULL, booking_note VARCHAR(400) NOT NULL, listed TINYINT(1) NOT NULL DEFAULT 0,
 verification_reference VARCHAR(300) NOT NULL, verified_by CHAR(32) NOT NULL, verified_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 FOREIGN KEY(branch_id) REFERENCES partner_branches(id), FOREIGN KEY(verified_by) REFERENCES users(id), INDEX(listed,city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
