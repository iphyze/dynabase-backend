-- Dynabase Batch 2: canonical Key Persons with many-to-many PMS assignments.
-- Apply once before deploying the corresponding backend code.

ALTER TABLE `keypersons_table`
  ADD COLUMN `normalized_name` varchar(255) DEFAULT NULL AFTER `key_person`,
  ADD COLUMN `normalized_phone` varchar(32) DEFAULT NULL AFTER `key_persons_tel`,
  ADD COLUMN `normalized_email` varchar(190) DEFAULT NULL AFTER `key_persons_email`,
  ADD KEY `idx_keypersons_normalized_name_client` (`normalized_name`, `clients_id`),
  ADD KEY `idx_keypersons_normalized_phone` (`normalized_phone`),
  ADD KEY `idx_keypersons_normalized_email` (`normalized_email`);

UPDATE `keypersons_table`
SET
  `normalized_name` = NULLIF(LOWER(TRIM(REGEXP_REPLACE(`key_person`, '[[:space:]]+', ' '))), ''),
  `normalized_phone` = NULLIF(REGEXP_REPLACE(`key_persons_tel`, '[^0-9]', ''), ''),
  `normalized_email` = NULLIF(LOWER(TRIM(`key_persons_email`)), '');

CREATE TABLE `keyperson_pms_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `keyperson_id` int(11) NOT NULL,
  `pms_admin_id` int(11) NOT NULL,
  `assigned_by_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_keyperson_pms_assignment` (`keyperson_id`, `pms_admin_id`),
  KEY `idx_keyperson_pms_assignments_pms` (`pms_admin_id`, `keyperson_id`),
  KEY `idx_keyperson_pms_assignments_assigned_by` (`assigned_by_id`),
  CONSTRAINT `fk_keyperson_pms_assignment_keyperson`
    FOREIGN KEY (`keyperson_id`) REFERENCES `keypersons_table` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_keyperson_pms_assignment_pms_admin`
    FOREIGN KEY (`pms_admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_keyperson_pms_assignment_assigned_by`
    FOREIGN KEY (`assigned_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backwards-compatible seed: every existing legacy owner keeps their current directory relationship.
INSERT IGNORE INTO `keyperson_pms_assignments` (`keyperson_id`, `pms_admin_id`, `assigned_by_id`)
SELECT k.`id`, k.`owner_pms_admin_id`, k.`created_by_id`
FROM `keypersons_table` k
INNER JOIN `users` owner
  ON owner.`id` = k.`owner_pms_admin_id`
 AND owner.`status` = 'active'
 AND (owner.`role` = 'pms_admin' OR owner.`is_pms_admin` = 1)
WHERE k.`owner_pms_admin_id` IS NOT NULL AND k.`owner_pms_admin_id` > 0;
