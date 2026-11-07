CREATE TABLE partner_applications (
 id CHAR(32) PRIMARY KEY, user_id CHAR(32) NOT NULL, business_name VARCHAR(180) NOT NULL,
 contact VARCHAR(500) NOT NULL, services JSON NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'pending',
 created_at DATETIME(6) NOT NULL, approved_at DATETIME(6) NULL,
 CONSTRAINT fk_application_user FOREIGN KEY(user_id) REFERENCES users(id), INDEX(user_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE partners (
 id CHAR(32) PRIMARY KEY, application_id CHAR(32) NOT NULL UNIQUE, business_name VARCHAR(180) NOT NULL,
 agreement_reference VARCHAR(500) NOT NULL, approved_by CHAR(32) NOT NULL, active_at DATETIME(6) NOT NULL,
 FOREIGN KEY(application_id) REFERENCES partner_applications(id), FOREIGN KEY(approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE partner_branches (
 id CHAR(32) PRIMARY KEY, partner_id CHAR(32) NOT NULL, name VARCHAR(180) NOT NULL,
 created_at DATETIME(6) NOT NULL, FOREIGN KEY(partner_id) REFERENCES partners(id), INDEX(partner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE partner_members (
 user_id CHAR(32) NOT NULL, branch_id CHAR(32) NOT NULL, PRIMARY KEY(user_id,branch_id),
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(branch_id) REFERENCES partner_branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE rate_cards (
 id CHAR(32) PRIMARY KEY, branch_id CHAR(32) NOT NULL, version INT NOT NULL,
 approval_reference VARCHAR(500) NOT NULL, approved_by CHAR(32) NOT NULL, created_at DATETIME(6) NOT NULL,
 UNIQUE(branch_id,version), FOREIGN KEY(branch_id) REFERENCES partner_branches(id), FOREIGN KEY(approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE rate_card_items (
 rate_card_id CHAR(32) NOT NULL, service_type VARCHAR(40) NOT NULL, amount_minor BIGINT NOT NULL,
 PRIMARY KEY(rate_card_id,service_type), CHECK(amount_minor>=0),
 FOREIGN KEY(rate_card_id) REFERENCES rate_cards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE partner_tasks (
 id CHAR(32) PRIMARY KEY, order_id CHAR(32) NOT NULL, branch_id CHAR(32) NOT NULL,
 service_type VARCHAR(40) NOT NULL, rate_card_id CHAR(32) NOT NULL, amount_minor BIGINT NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'assigned', scheduled_at DATETIME(6) NULL, evidence TEXT NULL,
 assigned_by CHAR(32) NOT NULL, completed_by CHAR(32) NULL, approved_by CHAR(32) NULL,
 created_at DATETIME(6) NOT NULL, accepted_at DATETIME(6) NULL, completed_at DATETIME(6) NULL, approved_at DATETIME(6) NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(branch_id) REFERENCES partner_branches(id),
 FOREIGN KEY(rate_card_id) REFERENCES rate_cards(id), FOREIGN KEY(assigned_by) REFERENCES users(id),
 FOREIGN KEY(completed_by) REFERENCES users(id), FOREIGN KEY(approved_by) REFERENCES users(id),
 CHECK(amount_minor>=0), INDEX(branch_id,status), INDEX(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE partner_ledger (
 id CHAR(32) PRIMARY KEY, partner_id CHAR(32) NOT NULL, branch_id CHAR(32) NOT NULL,
 order_id CHAR(32) NOT NULL, task_id CHAR(32) NOT NULL, rate_card_id CHAR(32) NOT NULL,
 service_type VARCHAR(40) NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'CAD', amount_minor BIGINT NOT NULL,
 event_key VARCHAR(100) NOT NULL UNIQUE, earning_condition VARCHAR(500) NOT NULL,
 reversal_of CHAR(32) NULL UNIQUE, responsible_user_id CHAR(32) NOT NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(partner_id) REFERENCES partners(id), FOREIGN KEY(branch_id) REFERENCES partner_branches(id),
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(task_id) REFERENCES partner_tasks(id),
 FOREIGN KEY(rate_card_id) REFERENCES rate_cards(id), FOREIGN KEY(reversal_of) REFERENCES partner_ledger(id),
 FOREIGN KEY(responsible_user_id) REFERENCES users(id), CHECK(currency='CAD'), INDEX(branch_id,created_at), INDEX(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE partner_settlements (
 id CHAR(32) PRIMARY KEY, branch_id CHAR(32) NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'CAD',
 amount_minor BIGINT NOT NULL, evidence TEXT NOT NULL, reference VARCHAR(180) NOT NULL UNIQUE,
 responsible_user_id CHAR(32) NOT NULL, created_at DATETIME(6) NOT NULL, CHECK(amount_minor>0),
 FOREIGN KEY(branch_id) REFERENCES partner_branches(id), FOREIGN KEY(responsible_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE settlement_items (
 settlement_id CHAR(32) NOT NULL, entry_id CHAR(32) NOT NULL UNIQUE, PRIMARY KEY(settlement_id,entry_id),
 FOREIGN KEY(settlement_id) REFERENCES partner_settlements(id), FOREIGN KEY(entry_id) REFERENCES partner_ledger(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TRIGGER partner_ledger_no_update BEFORE UPDATE ON partner_ledger FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Partner ledger is append-only';
CREATE TRIGGER partner_ledger_no_delete BEFORE DELETE ON partner_ledger FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Partner ledger is append-only';
CREATE TRIGGER rate_cards_no_update BEFORE UPDATE ON rate_cards FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Rate cards are immutable';
CREATE TRIGGER rate_cards_no_delete BEFORE DELETE ON rate_cards FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Rate cards are immutable';
CREATE TRIGGER rate_card_items_no_update BEFORE UPDATE ON rate_card_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Rate cards are immutable';
CREATE TRIGGER rate_card_items_no_delete BEFORE DELETE ON rate_card_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Rate cards are immutable';
