-- Dynabase self-service password recovery. Run ONCE after backing up the database.
-- Additive only: no users, passwords, or existing auth sessions are changed.
CREATE TABLE `auth_password_reset_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) DEFAULT NULL,
  `email_hash` CHAR(64) NOT NULL,
  `ip_hash` CHAR(64) NOT NULL,
  `token_hash` CHAR(64) DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `consumed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_password_reset_token` (`token_hash`),
  KEY `idx_password_reset_email_recent` (`email_hash`,`created_at`),
  KEY `idx_password_reset_ip_recent` (`ip_hash`,`created_at`),
  KEY `idx_password_reset_user` (`user_id`,`expires_at`),
  CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
