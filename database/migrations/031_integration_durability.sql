CREATE TABLE IF NOT EXISTS integration_capture_pages (
 id CHAR(36) PRIMARY KEY, run_id CHAR(36) NOT NULL, account_key VARCHAR(100) NOT NULL DEFAULT '',
 page_number INT UNSIGNED NOT NULL, cursor_json JSON NULL, next_cursor_json JSON NULL,
 total_count INT UNSIGNED NULL, item_count INT UNSIGNED NOT NULL,
 raw_path VARCHAR(240) NOT NULL, raw_sha256 CHAR(64) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY integration_capture_once(run_id,account_key,page_number),
 FOREIGN KEY(run_id) REFERENCES integration_runs(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS external_fetch_attempts (
 id CHAR(36) PRIMARY KEY, document_id CHAR(36) NOT NULL, document_version BIGINT UNSIGNED NOT NULL,
 request_key VARCHAR(100) NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
 operation VARCHAR(40) NOT NULL, status VARCHAR(32) NOT NULL,
 result_code VARCHAR(64) NULL, receipt_path VARCHAR(240) NULL, file_id CHAR(36) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), finished_at DATETIME(6) NULL,
 UNIQUE KEY external_fetch_once(document_id,document_version,operation,request_key),
 FOREIGN KEY(document_id) REFERENCES external_documents(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS messaging_receipts (
 attempt_id CHAR(36) PRIMARY KEY, receipt_path VARCHAR(240) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY(attempt_id) REFERENCES messaging_attempts(id)
) ENGINE=InnoDB;
