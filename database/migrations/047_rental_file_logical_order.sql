ALTER TABLE rental_files
    ADD upload_order BIGINT UNSIGNED NULL,
    ADD UNIQUE KEY rental_file_logical_order (upload_order);
CREATE TABLE rental_file_order (
    id TINYINT NOT NULL PRIMARY KEY,
    next_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    CHECK (id=1)
) ENGINE=InnoDB;
INSERT INTO rental_file_order(id,next_value) VALUES(1,0);
