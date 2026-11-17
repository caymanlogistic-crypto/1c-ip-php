CREATE TABLE IF NOT EXISTS integration_connections (
 provider VARCHAR(24) PRIMARY KEY, enabled TINYINT(1) NOT NULL DEFAULT 0,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1, display_name VARCHAR(200) NOT NULL,
 config_json JSON NOT NULL, secret_ref VARCHAR(100) NULL, last_checked_at DATETIME(6) NULL,
 last_status VARCHAR(32) NOT NULL DEFAULT 'unconfigured', updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS integration_runs (
 id CHAR(36) PRIMARY KEY, provider VARCHAR(24) NOT NULL, operation VARCHAR(32) NOT NULL,
 request_key VARCHAR(100) NOT NULL, actor_id BIGINT UNSIGNED NOT NULL, revision BIGINT UNSIGNED NOT NULL,
 fingerprint CHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, result_code VARCHAR(64) NULL,
 coverage_json JSON NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), finished_at DATETIME(6) NULL,
 UNIQUE KEY integration_once(provider,actor_id,request_key), INDEX integration_status(provider,status)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS external_documents (
 id CHAR(36) PRIMARY KEY, provider VARCHAR(24) NOT NULL, source_id VARCHAR(500) NOT NULL,
 account_id VARCHAR(100) NOT NULL DEFAULT '', document_date DATE NOT NULL, number VARCHAR(200) NOT NULL DEFAULT '',
 title VARCHAR(300) NOT NULL, amount DECIMAL(24,8) NULL, status VARCHAR(100) NOT NULL DEFAULT '',
 raw_sha256 CHAR(64) NOT NULL, raw_path VARCHAR(240) NOT NULL, context_path VARCHAR(240) NULL,
 batch_id CHAR(36) NOT NULL, version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY external_identity(provider,source_id), INDEX external_date(provider,document_date)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS external_document_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, document_id CHAR(36) NOT NULL, version BIGINT UNSIGNED NOT NULL,
 raw_sha256 CHAR(64) NOT NULL, raw_path VARCHAR(240) NOT NULL, batch_id CHAR(36) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), UNIQUE KEY external_version(document_id,version),
 FOREIGN KEY(document_id) REFERENCES external_documents(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS external_files (
 id CHAR(36) PRIMARY KEY, document_id CHAR(36) NOT NULL, source_id VARCHAR(500) NOT NULL,
 filename VARCHAR(200) NOT NULL, mime VARCHAR(100) NOT NULL, sha256 CHAR(64) NOT NULL,
 byte_size BIGINT UNSIGNED NOT NULL, private_path VARCHAR(240) NOT NULL, provenance VARCHAR(40) NOT NULL,
 signature_path VARCHAR(240) NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY external_file_once(document_id,sha256,provenance), FOREIGN KEY(document_id) REFERENCES external_documents(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS messaging_groups (
 id CHAR(36) PRIMARY KEY, provider VARCHAR(24) NOT NULL DEFAULT 'max', chat_id VARCHAR(40) NOT NULL,
 name VARCHAR(200) NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY messaging_group_identity(provider,chat_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS messaging_attempts (
 id CHAR(36) PRIMARY KEY, provider VARCHAR(24) NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
 request_key VARCHAR(100) NOT NULL, operation VARCHAR(16) NOT NULL, target VARCHAR(320) NOT NULL,
 fingerprint CHAR(64) NOT NULL, subject VARCHAR(998) NOT NULL DEFAULT '', body_text MEDIUMTEXT NOT NULL,
 status VARCHAR(32) NOT NULL DEFAULT 'pending', result_code VARCHAR(64) NULL, remote_id VARCHAR(200) NULL,
 spool_path VARCHAR(240) NULL, revision BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), finished_at DATETIME(6) NULL,
 UNIQUE KEY messaging_once(provider,actor_id,request_key,target), INDEX messaging_pending(provider,status)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS messaging_templates (
 id CHAR(36) PRIMARY KEY, provider VARCHAR(24) NOT NULL, name VARCHAR(200) NOT NULL,
 subject VARCHAR(998) NOT NULL DEFAULT '', body_text MEDIUMTEXT NOT NULL, version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), UNIQUE KEY messaging_template_name(provider,name)
) ENGINE=InnoDB;
