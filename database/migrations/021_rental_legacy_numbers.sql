ALTER TABLE contracts DROP INDEX number;
CREATE INDEX contracts_number_lookup ON contracts(number);
CREATE TABLE IF NOT EXISTS rental_number_guard (id INT PRIMARY KEY);
INSERT IGNORE INTO rental_number_guard(id) VALUES(1);
