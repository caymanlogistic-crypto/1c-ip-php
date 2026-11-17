ALTER TABLE contract_objects
    ADD physical_occupancy BOOLEAN NULL DEFAULT NULL,
    ADD occupancy_evidence_sha256 CHAR(64) NULL,
    ADD CONSTRAINT chk_contract_object_nonphysical_evidence
        CHECK(physical_occupancy IS NULL OR physical_occupancy <> 0 OR occupancy_evidence_sha256 IS NOT NULL);

ALTER TABLE contract_objects ALTER COLUMN physical_occupancy SET DEFAULT TRUE;

ALTER TABLE document_terms
    ADD physical_occupancy BOOLEAN NULL DEFAULT NULL,
    ADD occupancy_evidence_sha256 CHAR(64) NULL,
    ADD CONSTRAINT chk_document_term_nonphysical_evidence
        CHECK(physical_occupancy IS NULL OR physical_occupancy <> 0 OR occupancy_evidence_sha256 IS NOT NULL);

ALTER TABLE document_terms ALTER COLUMN physical_occupancy SET DEFAULT TRUE;

ALTER TABLE rental_object_holds
    ADD physical_occupancy BOOLEAN NULL DEFAULT NULL,
    ADD occupancy_evidence_sha256 CHAR(64) NULL,
    ADD CONSTRAINT chk_object_hold_nonphysical_evidence
        CHECK(physical_occupancy IS NULL OR physical_occupancy <> 0 OR occupancy_evidence_sha256 IS NOT NULL);

ALTER TABLE rental_object_holds ALTER COLUMN physical_occupancy SET DEFAULT TRUE;
