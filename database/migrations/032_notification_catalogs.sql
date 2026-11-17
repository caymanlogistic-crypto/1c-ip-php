CREATE TABLE IF NOT EXISTS integration_template_catalog (
 channel VARCHAR(12) NOT NULL, source_key VARCHAR(100) NOT NULL, source_id VARCHAR(100) NOT NULL,
 name VARCHAR(200) NOT NULL, trigger_text TEXT NOT NULL, format VARCHAR(12) NOT NULL DEFAULT 'plain',
 subject VARCHAR(800) NOT NULL DEFAULT '', body_text TEXT NOT NULL, title VARCHAR(400) NOT NULL DEFAULT '',
 tone VARCHAR(20) NULL, recipients_policy VARCHAR(100) NULL, attachment_policy VARCHAR(100) NULL,
 version BIGINT UNSIGNED NOT NULL, actor_label VARCHAR(200) NULL, changed_at VARCHAR(50) NULL,
 source_sha256 CHAR(64) NOT NULL, source_path VARCHAR(240) NOT NULL, source_version VARCHAR(100) NOT NULL,
 PRIMARY KEY(channel,source_key)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS integration_template_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, channel VARCHAR(12) NOT NULL, source_key VARCHAR(100) NOT NULL,
 version BIGINT UNSIGNED NOT NULL, format VARCHAR(12) NOT NULL, subject VARCHAR(800) NOT NULL DEFAULT '',
 body_text TEXT NOT NULL, title VARCHAR(400) NOT NULL DEFAULT '', actor_label VARCHAR(200) NULL,
 changed_at VARCHAR(50) NULL, original_path VARCHAR(240) NOT NULL,
 UNIQUE KEY integration_template_version(channel,source_key,version)
) ENGINE=InnoDB;
