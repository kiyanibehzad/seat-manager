INSERT IGNORE INTO settings(setting_key,value_json) VALUES ('operations_guard','{}');
CREATE TABLE IF NOT EXISTS openai_accounts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 owner_id BIGINT UNSIGNED NOT NULL,
 email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,
 alias VARCHAR(120) NULL,
 state_version INT UNSIGNED NOT NULL DEFAULT 0,
 ownership_status ENUM('clear','review_required') NOT NULL DEFAULT 'clear',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (owner_id) REFERENCES users(id), INDEX idx_account_owner(owner_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS seat_assignments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 account_id BIGINT UNSIGNED NOT NULL,
 owner_id BIGINT UNSIGNED NOT NULL,
 seat ENUM('standard','premium') NOT NULL,
 starts_at DATETIME NULL, ends_at DATETIME NULL,
 return_seat ENUM('none','standard','premium') NOT NULL,
 assigned_at DATETIME NOT NULL,
 recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 closed_at DATETIME NULL,
 current_account BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN closed_at IS NULL THEN account_id ELSE NULL END) STORED,
 UNIQUE KEY uq_current_assignment(current_account),
 FOREIGN KEY (account_id) REFERENCES openai_accounts(id), FOREIGN KEY (owner_id) REFERENCES users(id),
 INDEX idx_assignment_end(closed_at,ends_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS request_reasons (
 code VARCHAR(40) CHARACTER SET ascii PRIMARY KEY,
 label VARCHAR(150) NOT NULL, enabled BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS seat_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 owner_id BIGINT UNSIGNED NOT NULL, account_id BIGINT UNSIGNED NOT NULL,
 kind ENUM('seat','renewal') NOT NULL,
 requested_seat ENUM('standard','premium') NOT NULL,
 temporary BOOLEAN NOT NULL DEFAULT FALSE,
 requested_starts_at DATETIME NULL, requested_ends_at DATETIME NULL,
 approved_starts_at DATETIME NULL, approved_ends_at DATETIME NULL,
 expected_state_version INT UNSIGNED NOT NULL,
 assignment_id BIGINT UNSIGNED NULL, assignment_version INT UNSIGNED NULL,
 reason_code VARCHAR(40) NULL, reason_label VARCHAR(150) NULL, reason_text TEXT NULL,
 status ENUM('pending','ongoing','completed','rejected','cancelled') NOT NULL DEFAULT 'pending',
 rejection_reason TEXT NULL,
 reviewed_by BIGINT UNSIGNED NULL, reviewed_at DATETIME NULL, completed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 open_slot VARCHAR(80) GENERATED ALWAYS AS (CASE WHEN status IN ('pending','ongoing') THEN CONCAT(account_id,':',kind) ELSE NULL END) STORED,
 UNIQUE KEY uq_open_request(open_slot),
 FOREIGN KEY (owner_id) REFERENCES users(id), FOREIGN KEY (account_id) REFERENCES openai_accounts(id),
 FOREIGN KEY (assignment_id) REFERENCES seat_assignments(id), FOREIGN KEY (reviewed_by) REFERENCES users(id),
 INDEX idx_request_queue(status,kind,created_at,id), INDEX idx_request_owner(owner_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS seat_operations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NULL,
 kind VARCHAR(32) NOT NULL,
 status ENUM('ongoing','completed','cancelled') NOT NULL DEFAULT 'ongoing',
 outcome ENUM('pending','target_completed','cancelled_before_result','rolled_back','corrected') NOT NULL DEFAULT 'pending',
 capacity_reversed BOOLEAN NOT NULL DEFAULT FALSE,
 phase ENUM('working','waiting_invite','partial','scheduled','completed','cancelled') NOT NULL DEFAULT 'working',
 capacity_strategy ENUM('reserve','convert','deferred') NOT NULL DEFAULT 'reserve',
 capacity_applied BOOLEAN NOT NULL DEFAULT FALSE,
 held_standard INT UNSIGNED NOT NULL DEFAULT 0, held_premium INT UNSIGNED NOT NULL DEFAULT 0,
 effect_at DATETIME NULL,
 reason TEXT NOT NULL, override_reason TEXT NULL,
 started_by BIGINT UNSIGNED NOT NULL,
 completed_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME NULL,
 FOREIGN KEY (request_id) REFERENCES seat_requests(id), FOREIGN KEY (started_by) REFERENCES users(id), FOREIGN KEY (completed_by) REFERENCES users(id),
 INDEX idx_operation_status(status,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS operation_accounts (
 operation_id BIGINT UNSIGNED NOT NULL, account_id BIGINT UNSIGNED NOT NULL, owner_id BIGINT UNSIGNED NOT NULL,
 before_json JSON NOT NULL, target_json JSON NOT NULL,
 result_json JSON NULL, result_recorded BOOLEAN NOT NULL DEFAULT FALSE,
 PRIMARY KEY (operation_id,account_id),
 FOREIGN KEY (operation_id) REFERENCES seat_operations(id), FOREIGN KEY (account_id) REFERENCES openai_accounts(id), FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS account_operation_locks (
 account_id BIGINT UNSIGNED PRIMARY KEY, operation_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY (account_id) REFERENCES openai_accounts(id), FOREIGN KEY (operation_id) REFERENCES seat_operations(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS seat_capacity (
 id TINYINT UNSIGNED PRIMARY KEY,
 standard INT UNSIGNED NOT NULL DEFAULT 0, premium INT UNSIGNED NOT NULL DEFAULT 0,
 revision INT UNSIGNED NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT chk_single_capacity CHECK (id=1)
) ENGINE=InnoDB;
INSERT IGNORE INTO seat_capacity(id) VALUES(1);
CREATE TABLE IF NOT EXISTS capacity_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 operation_id BIGINT UNSIGNED NULL, actor_id BIGINT UNSIGNED NOT NULL,
 before_json JSON NOT NULL, after_json JSON NOT NULL,
 reason TEXT NOT NULL, evidence TEXT NOT NULL, finance_json JSON NULL,
 recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (operation_id) REFERENCES seat_operations(id), FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS seat_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 operation_id BIGINT UNSIGNED NULL, request_id BIGINT UNSIGNED NULL,
 account_id BIGINT UNSIGNED NOT NULL, owner_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(50) NOT NULL,
 before_json JSON NOT NULL, after_json JSON NOT NULL,
 reason TEXT NOT NULL, evidence TEXT NOT NULL,
 observed_at DATETIME NULL, recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (operation_id) REFERENCES seat_operations(id), FOREIGN KEY (request_id) REFERENCES seat_requests(id),
 FOREIGN KEY (account_id) REFERENCES openai_accounts(id), FOREIGN KEY (owner_id) REFERENCES users(id), FOREIGN KEY (actor_id) REFERENCES users(id),
 INDEX idx_history_owner(owner_id,id), INDEX idx_history_account(account_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS operation_commands (
 actor_id BIGINT UNSIGNED NOT NULL, command_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii NOT NULL, response_json JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (actor_id,command_key), FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS user_bulk_batches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor_id BIGINT UNSIGNED NOT NULL,
 payload_encrypted MEDIUMTEXT NOT NULL,
 status ENUM('preview','committed') NOT NULL DEFAULT 'preview',
 result_json JSON NULL, expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS assignment_alert_markers (
 assignment_id BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL,
 event_type VARCHAR(40) NOT NULL, identity_version INT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(assignment_id,version,event_type,identity_version),
 FOREIGN KEY(assignment_id) REFERENCES seat_assignments(id)
) ENGINE=InnoDB;
