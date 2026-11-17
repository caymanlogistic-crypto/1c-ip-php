ALTER TABLE rental_files ADD COLUMN baseline_hash CHAR(64) NULL;
ALTER TABLE contracts ADD COLUMN finish_date DATE NULL, ADD COLUMN finish_reason TEXT NULL;
ALTER TABLE invoices ADD COLUMN control_closed BOOLEAN NOT NULL DEFAULT FALSE;
CREATE TABLE IF NOT EXISTS rental_reviews (contract_id BIGINT NOT NULL, kind VARCHAR(30) NOT NULL, baseline_hash CHAR(64) NOT NULL, actor_id BIGINT UNSIGNED NOT NULL, confirmed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(contract_id,kind), FOREIGN KEY(contract_id) REFERENCES contracts(id));
CREATE TABLE IF NOT EXISTS rental_approvals (entity_type VARCHAR(30) NOT NULL, entity_id BIGINT NOT NULL, role VARCHAR(30) NOT NULL, baseline_hash CHAR(64) NOT NULL, file_id CHAR(32) NOT NULL, actor_id BIGINT UNSIGNED NOT NULL, confirmed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(entity_type,entity_id,role));
