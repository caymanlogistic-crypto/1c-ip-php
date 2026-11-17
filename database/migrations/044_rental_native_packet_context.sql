CREATE TABLE rental_runtime_context (
    id TINYINT NOT NULL PRIMARY KEY,
    epoch CHAR(36) NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    source_pair_sha256 CHAR(64) NULL,
    CHECK (id=1)
) ENGINE=InnoDB;
INSERT INTO rental_runtime_context(id,epoch,revision) VALUES(1,UUID(),0);
CREATE TABLE rental_packet_confirmations (
    id CHAR(32) NOT NULL PRIMARY KEY,
    record_kind VARCHAR(16) NOT NULL,
    record_id BIGINT NOT NULL,
    record_version INT NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    role_id VARCHAR(32) NOT NULL,
    auth_version INT NOT NULL,
    command_key VARCHAR(100) NOT NULL UNIQUE,
    checks_json JSON NOT NULL,
    snapshot_json JSON NOT NULL,
    snapshot_sha256 CHAR(64) NOT NULL,
    legal_baseline CHAR(64) NOT NULL,
    legal_file_id CHAR(32) NOT NULL,
    legal_file_sha256 CHAR(64) NOT NULL,
    file_revision INT NOT NULL,
    workflow_epoch CHAR(36) NOT NULL,
    workflow_revision BIGINT UNSIGNED NOT NULL,
    source_pins_json JSON NOT NULL,
    confirmed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX packet_confirmation_record (record_kind,record_id,record_version),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK (record_kind IN ('contract','document'))
) ENGINE=InnoDB;
