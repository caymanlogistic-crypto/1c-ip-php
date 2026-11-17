CREATE TABLE rental_contract_versions (
    contract_id BIGINT NOT NULL,
    version INT NOT NULL,
    snapshot_text LONGTEXT NOT NULL,
    snapshot_sha256 CHAR(64) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    actor_role_id VARCHAR(32) NOT NULL,
    actor_name VARCHAR(255) NOT NULL,
    command_key VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (contract_id,version),
    INDEX contract_history_command (command_key),
    FOREIGN KEY (contract_id) REFERENCES contracts(id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK (JSON_VALID(snapshot_text))
) ENGINE=InnoDB;
