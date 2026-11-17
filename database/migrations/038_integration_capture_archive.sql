CREATE TABLE integration_captures (
 id CHAR(64) PRIMARY KEY, root_sha256 CHAR(64) NOT NULL, source_release CHAR(40) NOT NULL,
 status VARCHAR(32) NOT NULL, eligible TINYINT NOT NULL, global_pit TINYINT NOT NULL, restore_ready TINYINT NOT NULL,
 archive_only TINYINT NOT NULL DEFAULT 1, manifest_path VARCHAR(240) NOT NULL,
 completed_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;
CREATE TABLE integration_capture_tables (
 capture_id CHAR(64) NOT NULL, source_table VARCHAR(100) NOT NULL, row_count BIGINT UNSIGNED NOT NULL,
 byte_size BIGINT UNSIGNED NOT NULL, sha256 CHAR(64) NOT NULL, private_path VARCHAR(240) NOT NULL,
 PRIMARY KEY(capture_id,source_table), FOREIGN KEY(capture_id) REFERENCES integration_captures(id)
) ENGINE=InnoDB;
CREATE TABLE integration_capture_rows (
 capture_id CHAR(64) NOT NULL, source_table VARCHAR(100) NOT NULL, source_pk_sha256 CHAR(64) NOT NULL,
 source_pk_json JSON NOT NULL, ordinal BIGINT UNSIGNED NOT NULL, byte_offset BIGINT UNSIGNED NOT NULL,
 byte_size INT UNSIGNED NOT NULL, row_sha256 CHAR(64) NOT NULL,
 PRIMARY KEY(capture_id,source_table,source_pk_sha256), UNIQUE KEY capture_ordinal(capture_id,source_table,ordinal),
 FOREIGN KEY(capture_id,source_table) REFERENCES integration_capture_tables(capture_id,source_table)
) ENGINE=InnoDB;
CREATE TABLE integration_capture_cells (
 capture_id CHAR(64) NOT NULL, source_table VARCHAR(100) NOT NULL, ordinal BIGINT UNSIGNED NOT NULL,
 source_column VARCHAR(100) NOT NULL, source_status VARCHAR(64) NOT NULL, semantics VARCHAR(100) NULL,
 sha256 CHAR(64) NULL, byte_size BIGINT UNSIGNED NULL, private_path VARCHAR(240) NULL,
 PRIMARY KEY(capture_id,source_table,ordinal,source_column),
 FOREIGN KEY(capture_id,source_table) REFERENCES integration_capture_tables(capture_id,source_table)
) ENGINE=InnoDB;
CREATE TABLE integration_capture_blobs (
 capture_id CHAR(64) NOT NULL, sha256 CHAR(64) NOT NULL, byte_size BIGINT UNSIGNED NOT NULL,
 private_path VARCHAR(240) NOT NULL, PRIMARY KEY(capture_id,sha256),
 FOREIGN KEY(capture_id) REFERENCES integration_captures(id)
) ENGINE=InnoDB;
CREATE TABLE integration_capture_file_refs (
 capture_id CHAR(64) NOT NULL, source_id VARCHAR(64) NOT NULL, namespace VARCHAR(200) NOT NULL,
 owner_key VARCHAR(500) NOT NULL, role VARCHAR(100) NOT NULL, original_name TEXT NOT NULL,
 content_type VARCHAR(200) NOT NULL, blob_sha256 CHAR(64) NOT NULL, provenance_path VARCHAR(240) NOT NULL,
 target_document_id CHAR(36) NULL, link_status VARCHAR(32) NOT NULL DEFAULT 'unresolved',
 PRIMARY KEY(capture_id,source_id), INDEX capture_file_owner(capture_id,namespace,owner_key),
 FOREIGN KEY(capture_id,blob_sha256) REFERENCES integration_capture_blobs(capture_id,sha256)
) ENGINE=InnoDB;
CREATE TABLE integration_capture_documents (
 capture_id CHAR(64) NOT NULL, source_id VARCHAR(64) NOT NULL, provider VARCHAR(24) NOT NULL,
 native_source_id VARCHAR(500) NOT NULL, identity_sha256 CHAR(64) NOT NULL, batch_source_id VARCHAR(64) NOT NULL,
 document_date DATE NOT NULL, number VARCHAR(200) NOT NULL, title LONGTEXT NOT NULL,
 amount DECIMAL(24,8) NULL, status VARCHAR(200) NOT NULL, account_id VARCHAR(100) NOT NULL,
 account_display TEXT NOT NULL, address LONGTEXT NOT NULL, sender LONGTEXT NOT NULL, recipient LONGTEXT NOT NULL,
 document_type VARCHAR(200) NOT NULL, raw_path VARCHAR(240) NOT NULL, raw_sha256 CHAR(64) NOT NULL,
 target_document_id CHAR(36) NULL, PRIMARY KEY(capture_id,source_id),
 UNIQUE KEY capture_document_identity(capture_id,provider,identity_sha256),
 FOREIGN KEY(capture_id) REFERENCES integration_captures(id)
) ENGINE=InnoDB;
CREATE TABLE integration_capture_message_attempts (
 capture_id CHAR(64) NOT NULL, source_id VARCHAR(100) NOT NULL, provider VARCHAR(24) NOT NULL,
 historical_status VARCHAR(32) NOT NULL, context_path VARCHAR(240) NOT NULL,
 attachments_state VARCHAR(32) NOT NULL DEFAULT 'NOT_RECORDED', config_revision_state VARCHAR(32) NOT NULL DEFAULT 'UNKNOWN',
 operative TINYINT NOT NULL DEFAULT 0, PRIMARY KEY(capture_id,source_id),
 CHECK(operative=0), FOREIGN KEY(capture_id) REFERENCES integration_captures(id)
) ENGINE=InnoDB;
