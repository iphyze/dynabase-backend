-- Run once before deploying the PMS portal backend endpoints.
-- Portal-only users have no Dynabase password and cannot access the main app.
ALTER TABLE `users`
  ADD COLUMN `pms_portal_only` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_pms_admin`;

CREATE TABLE `pms_portal_login_codes` (
  `user_id` INT(11) NOT NULL,
  `code_hash` CHAR(64) NOT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `consumed_at` DATETIME DEFAULT NULL,
  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_pms_portal_login_codes_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `pms_portal_login_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_hash` CHAR(64) NOT NULL,
  `request_kind` ENUM('code','verify') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pms_portal_requests_ip_kind_time` (`ip_hash`, `request_kind`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
