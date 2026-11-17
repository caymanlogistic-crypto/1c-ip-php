CREATE TABLE IF NOT EXISTS external_document_details (
 document_id CHAR(36) PRIMARY KEY, account_display VARCHAR(300) NOT NULL DEFAULT '',
 address TEXT NULL, sender TEXT NULL, recipient TEXT NULL, document_type VARCHAR(300) NOT NULL DEFAULT '',
 bank_payer TEXT NULL, bank_organization TEXT NULL, bank_account VARCHAR(100) NOT NULL DEFAULT '',
 bank_purpose TEXT NULL, bank_operation VARCHAR(300) NOT NULL DEFAULT '',
 search_text LONGTEXT NOT NULL, FOREIGN KEY(document_id) REFERENCES external_documents(id)
) ENGINE=InnoDB;
