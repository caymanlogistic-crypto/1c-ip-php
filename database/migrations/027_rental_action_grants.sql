INSERT IGNORE INTO permissions(permission_key,name) VALUES('rental.approve.executive','Утверждение шаблонов и специальные решения руководителя');
INSERT IGNORE INTO role_permissions(role_id,permission_key) VALUES('director','rental.approve.executive');
