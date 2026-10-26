-- Gift List email sending history; required before enabling email sending endpoints.
-- Individual addresses are retained for accountability; no passwords or OTP codes are stored.
CREATE TABLE IF NOT EXISTS gift_list_email_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email_type ENUM('starter','reminder','custom') NOT NULL,
  gift_year SMALLINT UNSIGNED NOT NULL,
  subject VARCHAR(255) NOT NULL,
  message TEXT NULL,
  created_by_id INT(11) NULL,
  recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gift_email_batch_year (gift_year, created_at),
  CONSTRAINT fk_gift_email_batch_sender FOREIGN KEY (created_by_id)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gift_list_email_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id BIGINT UNSIGNED NOT NULL,
  recipient_user_id INT(11) NULL,
  recipient_email VARCHAR(190) NOT NULL,
  delivery_status ENUM('sent','failed','logged') NOT NULL,
  error_message VARCHAR(400) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gift_email_batch_recipient (batch_id, recipient_user_id),
  KEY idx_gift_email_recipient_recent (recipient_user_id, created_at),
  CONSTRAINT fk_gift_email_delivery_batch FOREIGN KEY (batch_id)
    REFERENCES gift_list_email_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_gift_email_delivery_recipient FOREIGN KEY (recipient_user_id)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admins have sending access by default, which Super Admins can revoke.
-- Existing individual overrides are never overwritten; ordinary Users remain opt-in.
INSERT IGNORE INTO user_permissions (user_id, permission_key, granted, updated_by)
SELECT u.id, 'gift_list_emails.send', 1, NULL
FROM users u WHERE u.role = 'admin';
