-- A selected applied addendum admits servicing of imported contracts from its effective date.
-- Original source_json and imported calendar remain immutable.
ALTER TABLE contracts ADD COLUMN servicing_document_id BIGINT UNSIGNED NULL, ADD COLUMN servicing_effective_date DATE NULL;
CREATE TABLE rental_scheduler_control (
 id TINYINT UNSIGNED PRIMARY KEY,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 calendar_admission VARCHAR(40) NOT NULL DEFAULT 'UNVERIFIED',
 calendar_receipt_sha256 CHAR(64) NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT INTO rental_scheduler_control(id) VALUES(1);
