ALTER TABLE enquiries ADD COLUMN design_id CHAR(32) NULL AFTER user_id, ADD CONSTRAINT enquiry_design FOREIGN KEY(design_id) REFERENCES designs(id);
