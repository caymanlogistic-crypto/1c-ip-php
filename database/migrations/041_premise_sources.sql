ALTER TABLE aux_premises ADD area_variants JSON NULL, ADD account_variants JSON NULL;
CREATE TABLE aux_source_rows(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,batch_id BIGINT UNSIGNED NOT NULL,source_row_number INT NOT NULL,hidden TINYINT NOT NULL,raw_evidence JSON NOT NULL,reason TEXT NULL,UNIQUE(batch_id,source_row_number),FOREIGN KEY(batch_id) REFERENCES aux_import_batches(id)) ENGINE=InnoDB;
CREATE TABLE aux_premise_source_links(source_row_id BIGINT UNSIGNED NOT NULL,premise_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(source_row_id,premise_id),FOREIGN KEY(source_row_id) REFERENCES aux_source_rows(id),FOREIGN KEY(premise_id) REFERENCES aux_premises(id)) ENGINE=InnoDB;
CREATE TABLE aux_import_reports(batch_id BIGINT UNSIGNED PRIMARY KEY,parser_version VARCHAR(30) NOT NULL,sheet VARCHAR(200) NULL,report_json JSON NOT NULL,FOREIGN KEY(batch_id) REFERENCES aux_import_batches(id)) ENGINE=InnoDB;
CREATE TABLE aux_write_guard(id INT PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO aux_write_guard(id) VALUES(1);
