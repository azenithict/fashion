ALTER TABLE measurements ADD COLUMN captured_by CHAR(32) NULL, ADD CONSTRAINT fk_measurement_captured_by FOREIGN KEY(captured_by) REFERENCES users(id);
ALTER TABLE partner_tasks
 ADD COLUMN customer_consent_at DATETIME(6) NULL,
 ADD COLUMN customer_consent_by CHAR(32) NULL,
 ADD COLUMN measurement_id CHAR(32) NULL,
 ADD CONSTRAINT fk_task_consent_user FOREIGN KEY(customer_consent_by) REFERENCES users(id),
 ADD CONSTRAINT fk_task_measurement FOREIGN KEY(measurement_id) REFERENCES measurements(id);
