CREATE TABLE IF NOT EXISTS integration_configuration_sources (
 provider VARCHAR(24) PRIMARY KEY, variant VARCHAR(40) NOT NULL, source_receipt_sha256 CHAR(64) NOT NULL,
 source_revision BIGINT UNSIGNED NULL, selected_profile VARCHAR(100) NULL, enabled_in_source BOOLEAN NULL,
 metadata_json JSON NOT NULL, activated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS integration_group_bindings (
 source_variant VARCHAR(40) NOT NULL, source_group_id VARCHAR(100) NOT NULL,
 local_group_id CHAR(36) NOT NULL, provider VARCHAR(24) NOT NULL DEFAULT 'max',
 PRIMARY KEY(source_variant,source_group_id), UNIQUE KEY integration_group_local(local_group_id),
 FOREIGN KEY(local_group_id) REFERENCES messaging_groups(id)
) ENGINE=InnoDB;
