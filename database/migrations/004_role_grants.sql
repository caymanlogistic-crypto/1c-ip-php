ALTER TABLE roles ADD description TEXT NULL, ADD revision INT UNSIGNED NOT NULL DEFAULT 1, ADD grants_configured TINYINT NOT NULL DEFAULT 0, ADD UNIQUE KEY role_unique_name(name);
CREATE TABLE role_profiles(role_id VARCHAR(32) PRIMARY KEY,kind VARCHAR(16) NOT NULL,FOREIGN KEY(role_id) REFERENCES roles(id)) ENGINE=InnoDB;
INSERT INTO role_profiles(role_id,kind) VALUES('manager','manager'),('lawyer','lawyer'),('accountant','accountant'),('director','director');
CREATE TABLE role_page_grants(role_id VARCHAR(32) NOT NULL,page_key VARCHAR(100) NOT NULL,level TINYINT UNSIGNED NOT NULL,PRIMARY KEY(role_id,page_key),FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE,CHECK(level IN(0,1,2))) ENGINE=InnoDB;
CREATE TABLE identity_write_guard(id INT PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO identity_write_guard(id) VALUES(1);
