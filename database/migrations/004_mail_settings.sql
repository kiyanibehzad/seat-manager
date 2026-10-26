-- Separate private encrypted configuration; never changes users or legacy app config.
CREATE TABLE IF NOT EXISTS mail_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    settings_encrypted MEDIUMTEXT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_mail_settings_singleton CHECK (id=1)
) ENGINE=InnoDB;
INSERT IGNORE INTO mail_settings(id) VALUES(1);
CREATE TABLE IF NOT EXISTS mail_service_state (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    worker_last_started_at DATETIME NULL,
    worker_last_finished_at DATETIME NULL,
    worker_last_error_code VARCHAR(64) NULL,
    cron_last_started_at DATETIME NULL,
    cron_last_finished_at DATETIME NULL,
    cron_last_error_code VARCHAR(64) NULL,
    CONSTRAINT chk_mail_service_singleton CHECK (id=1)
) ENGINE=InnoDB;
INSERT IGNORE INTO mail_service_state(id) VALUES(1);
CREATE TABLE IF NOT EXISTS mail_test_commands (
    idempotency_key CHAR(64) NOT NULL PRIMARY KEY,
    request_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
