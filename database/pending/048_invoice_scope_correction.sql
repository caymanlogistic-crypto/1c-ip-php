CREATE TABLE rental_scope_corrections (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    manifest_sha256 CHAR(64) NOT NULL UNIQUE,
    artifact_sha256 CHAR(64) NOT NULL,
    audit_sha256 CHAR(64) NOT NULL,
    state_sha256 CHAR(64) NOT NULL,
    mapping_sha256 CHAR(64) NOT NULL,
    preimage_sha256 CHAR(64) NOT NULL,
    after_sha256 CHAR(64) NOT NULL,
    artifact_path VARCHAR(500) NOT NULL,
    before_path VARCHAR(500) NOT NULL,
    after_path VARCHAR(500) NOT NULL,
    invoice_count INT UNSIGNED NOT NULL,
    verified_count INT UNSIGNED NOT NULL,
    unknown_count INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE invoice_scope_availability (
    invoice_id BIGINT PRIMARY KEY,
    origin VARCHAR(40) NOT NULL,
    objects_state VARCHAR(7) NOT NULL,
    periods_state VARCHAR(7) NOT NULL,
    availability VARCHAR(7) NOT NULL,
    classification VARCHAR(24) NOT NULL,
    runtime_hold VARCHAR(160) NULL,
    evidence_sha256 CHAR(64) NOT NULL,
    admission_sha256 CHAR(64) NOT NULL,
    correction_id CHAR(36) NULL,
    source_sha256 CHAR(64) NULL,
    command_key VARCHAR(100) NULL,
    invoice_version INT UNSIGNED NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY(invoice_id) REFERENCES invoices(id),
    FOREIGN KEY(correction_id) REFERENCES rental_scope_corrections(id),
    CHECK(objects_state IN ('KNOWN','PARTIAL','UNKNOWN')),
    CHECK(periods_state IN ('KNOWN','PARTIAL','UNKNOWN')),
    CHECK(availability IN ('KNOWN','PARTIAL','UNKNOWN'))
) ENGINE=InnoDB;

CREATE TABLE rental_scope_correction_rows (
    correction_id CHAR(36) NOT NULL,
    invoice_id BIGINT NOT NULL,
    verified BOOLEAN NOT NULL,
    old_version INT UNSIGNED NOT NULL,
    new_version INT UNSIGNED NOT NULL,
    before_sha256 CHAR(64) NOT NULL,
    after_sha256 CHAR(64) NOT NULL,
    evidence_sha256 CHAR(64) NOT NULL,
    PRIMARY KEY(correction_id,invoice_id),
    FOREIGN KEY(correction_id) REFERENCES rental_scope_corrections(id),
    FOREIGN KEY(invoice_id) REFERENCES invoices(id)
) ENGINE=InnoDB;
