ALTER TABLE rental_packet_confirmations
    ADD snapshot_text LONGTEXT NULL,
    ADD raw_sha256 CHAR(64) NULL,
    ADD originalapproval_signature_text LONGTEXT NULL,
    ADD packet_signature_text LONGTEXT NULL;
