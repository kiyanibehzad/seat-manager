CREATE TABLE IF NOT EXISTS credit_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 owner_id BIGINT UNSIGNED NOT NULL, account_id BIGINT UNSIGNED NOT NULL,
 requested_credits INT UNSIGNED NOT NULL, approved_credits INT UNSIGNED NULL,
 reason_text TEXT NOT NULL,
 status ENUM('pending','ongoing','completed','rejected','cancelled') NOT NULL DEFAULT 'pending',
 review_note TEXT NULL, rejection_reason TEXT NULL,
 reviewed_by BIGINT UNSIGNED NULL, reviewed_at DATETIME NULL,
 approved_state_version INT UNSIGNED NULL,
 limit_before INT UNSIGNED NULL, limit_after INT UNSIGNED NULL,
 evidence TEXT NULL, observed_at DATETIME NULL, period_ends_at DATETIME NULL,
 completed_by BIGINT UNSIGNED NULL, completed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 open_account BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status IN ('pending','ongoing') THEN account_id ELSE NULL END) STORED,
 UNIQUE KEY uq_open_credit_request(open_account),
 FOREIGN KEY (owner_id) REFERENCES users(id), FOREIGN KEY (account_id) REFERENCES openai_accounts(id),
 FOREIGN KEY (reviewed_by) REFERENCES users(id), FOREIGN KEY (completed_by) REFERENCES users(id),
 INDEX idx_credit_queue(status,created_at,id), INDEX idx_credit_owner(owner_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS credit_request_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL, owner_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL, action VARCHAR(40) NOT NULL,
 before_json JSON NOT NULL, after_json JSON NOT NULL,
 reason TEXT NOT NULL, evidence TEXT NOT NULL,
 recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (request_id) REFERENCES credit_requests(id), FOREIGN KEY (owner_id) REFERENCES users(id),
 FOREIGN KEY (actor_id) REFERENCES users(id),
 INDEX idx_credit_event_request(request_id,id), INDEX idx_credit_event_owner(owner_id,id)
) ENGINE=InnoDB;
