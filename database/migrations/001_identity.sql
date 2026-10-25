CREATE TABLE IF NOT EXISTS app_roles (
 code VARCHAR(16) PRIMARY KEY
) ENGINE=InnoDB;
INSERT IGNORE INTO app_roles(code) VALUES ('user'),('admin'),('hr'),('operator');
CREATE TABLE IF NOT EXISTS departments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL UNIQUE,
 base_score TINYINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT chk_base_score CHECK (base_score BETWEEN 1 AND 5)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 first_name VARCHAR(100) NOT NULL,
 last_name VARCHAR(100) NOT NULL,
 employee_number VARCHAR(64) NULL,
 department_id BIGINT UNSIGNED NULL,
 organizational_position ENUM('employee','supervisor') NOT NULL DEFAULT 'employee',
 app_role VARCHAR(16) NOT NULL DEFAULT 'user',
 special_priority BOOLEAN NOT NULL DEFAULT FALSE,
 is_active BOOLEAN NOT NULL DEFAULT TRUE,
 email_verified_at DATETIME NULL,
 approval_status ENUM('pending_email','pending_approval','approved','rejected') NOT NULL DEFAULT 'pending_email',
 approved_by BIGINT UNSIGNED NULL,
 approved_at DATETIME NULL,
 auth_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (department_id) REFERENCES departments(id),
 FOREIGN KEY (app_role) REFERENCES app_roles(code),
 FOREIGN KEY (approved_by) REFERENCES users(id),
 INDEX idx_registration (approval_status, email_verified_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS settings (
 setting_key VARCHAR(80) PRIMARY KEY,
 value_json JSON NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT IGNORE INTO settings(setting_key,value_json) VALUES ('manual_action_hours','24'),('identity_guard','{}');
CREATE TABLE IF NOT EXISTS allowed_email_domains (
 domain VARCHAR(253) CHARACTER SET ascii COLLATE ascii_general_ci PRIMARY KEY
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS auth_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 purpose ENUM('verify_email','reset_password') NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 consumed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id),
 INDEX idx_token_user (user_id,purpose,consumed_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS rate_limits (
 bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 window_started DATETIME NOT NULL,
 blocked_until DATETIME NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS audit_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_id BIGINT UNSIGNED NULL,
 subject_id BIGINT UNSIGNED NULL,
 action VARCHAR(80) NOT NULL,
 details_json JSON NOT NULL,
 request_id VARCHAR(32) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (actor_id) REFERENCES users(id),
 FOREIGN KEY (subject_id) REFERENCES users(id),
 INDEX idx_audit_subject (subject_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 recipient_id BIGINT UNSIGNED NOT NULL,
 event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_type VARCHAR(80) NOT NULL,
 title VARCHAR(200) NOT NULL,
 body TEXT NOT NULL,
 action_url VARCHAR(1000) NOT NULL,
 read_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (recipient_id) REFERENCES users(id),
 UNIQUE KEY uq_notification (recipient_id,event_key),
 INDEX idx_notifications (recipient_id,read_at,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS mail_outbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 recipient_id BIGINT UNSIGNED NOT NULL,
 idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 message_id VARCHAR(200) CHARACTER SET ascii NOT NULL UNIQUE,
 payload_encrypted MEDIUMTEXT NULL,
 category VARCHAR(40) NOT NULL DEFAULT 'event',
 status ENUM('pending','sending','retry','sent','failed','uncertain','cancelled') NOT NULL DEFAULT 'pending',
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 claimed_at DATETIME NULL,
 sent_at DATETIME NULL,
 last_error_code VARCHAR(40) NULL,
 auth_token_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (recipient_id) REFERENCES users(id),
 FOREIGN KEY (auth_token_id) REFERENCES auth_tokens(id),
 INDEX idx_outbox (status,available_at,id)
) ENGINE=InnoDB;
-- Adapter contract for stage 2; no fictitious assignments/reminders are seeded.
CREATE TABLE IF NOT EXISTS scheduled_reminders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 recipient_id BIGINT UNSIGNED NOT NULL,
 source_type VARCHAR(40) NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 source_version INT UNSIGNED NOT NULL,
 event_type VARCHAR(80) NOT NULL,
 due_at DATETIME NOT NULL,
 payload_encrypted TEXT NOT NULL,
 dispatched_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (recipient_id) REFERENCES users(id),
 UNIQUE KEY uq_reminder (source_type,source_id,source_version,event_type),
 INDEX idx_reminders (dispatched_at,cancelled_at,due_at)
) ENGINE=InnoDB;
