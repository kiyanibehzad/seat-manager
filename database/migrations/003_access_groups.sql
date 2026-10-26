CREATE TABLE IF NOT EXISTS access_capability_codes (
 code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY
) ENGINE=InnoDB;
INSERT IGNORE INTO access_capability_codes(code) VALUES
 ('dashboard.read'),('seats.manage'),('registrations.review'),('people.manage'),('departments.manage');
CREATE TABLE IF NOT EXISTS access_groups (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL UNIQUE,
 system_key VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
 is_super_admin BOOLEAN NOT NULL DEFAULT FALSE,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT chk_chief_group CHECK (is_super_admin=0 OR COALESCE(system_key,'')='admin')
) ENGINE=InnoDB;
INSERT IGNORE INTO access_groups(id,name,system_key,is_super_admin) VALUES
 (1,'کاربر','user',0),(2,'منابع انسانی','hr',0),(3,'اپراتور حساب‌ها','operator',0),(4,'مدیر کل','admin',1);
CREATE TABLE IF NOT EXISTS access_group_capabilities (
 group_id BIGINT UNSIGNED NOT NULL,
 capability VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(group_id,capability),
 FOREIGN KEY(group_id) REFERENCES access_groups(id),
 FOREIGN KEY(capability) REFERENCES access_capability_codes(code)
) ENGINE=InnoDB;
INSERT IGNORE INTO access_group_capabilities(group_id,capability) VALUES
 (2,'registrations.review'),(2,'people.manage'),(2,'departments.manage'),
 (3,'dashboard.read'),(3,'seats.manage'),(3,'registrations.review');
-- Conditional DDL allows a retry after an interrupted initial migration on MySQL/MariaDB.
SET @acc_group_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='access_group_id')=0,
 'ALTER TABLE users ADD COLUMN access_group_id BIGINT UNSIGNED NOT NULL DEFAULT 1','SELECT 1');
PREPARE acc_group_stmt FROM @acc_group_ddl;
EXECUTE acc_group_stmt;
DEALLOCATE PREPARE acc_group_stmt;
UPDATE users u JOIN access_groups g ON g.system_key=u.app_role
 SET u.access_group_id=g.id,u.auth_version=u.auth_version+1;
SET @acc_group_ddl = IF((SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='users' AND CONSTRAINT_NAME='fk_users_access_group')=0,
 'ALTER TABLE users ADD CONSTRAINT fk_users_access_group FOREIGN KEY(access_group_id) REFERENCES access_groups(id)','SELECT 1');
PREPARE acc_group_stmt FROM @acc_group_ddl;
EXECUTE acc_group_stmt;
DEALLOCATE PREPARE acc_group_stmt;
SET @acc_group_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='required_permission')=0,
 'ALTER TABLE notifications ADD COLUMN required_permission VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL','SELECT 1');
PREPARE acc_group_stmt FROM @acc_group_ddl;
EXECUTE acc_group_stmt;
DEALLOCATE PREPARE acc_group_stmt;
UPDATE notifications SET required_permission=CASE
 WHEN event_type='registration.ready' THEN 'registrations.review'
 WHEN event_type IN ('request.created','expiry.action_required') THEN 'seats.manage'
 ELSE required_permission END;
INSERT INTO audit_log(actor_id,subject_id,action,details_json,request_id)
 VALUES(NULL,NULL,'access_groups.migrated',JSON_OBJECT('after',JSON_OBJECT('model','single_access_group','session_versions_rotated',TRUE)),'migration-003');
