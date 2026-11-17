-- Incomplete editor drafts have no invented counterparty. Existing rows are retained.
ALTER TABLE contracts MODIFY party_id BIGINT NULL,
 ADD CONSTRAINT contracts_party_for_workflow CHECK (party_id IS NOT NULL OR status IN ('draft','returned','cancelled'));
