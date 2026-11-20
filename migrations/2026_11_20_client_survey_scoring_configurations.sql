-- Dynabase: versioned Client Survey weighting foundation (Batch 1).
-- Prerequisite: 2026_11_17_stakeholder_survey_v2.sql.
-- Apply ONCE to the current MariaDB database after a full backup.
-- Additive: no legacy survey rows, invitations or sessions are modified or deleted.
-- 1 basis point = 0.01%; exactly 10,000 basis points = 100.00%.
-- The v2 survey's historical equal-average scores intentionally remain unversioned.

CREATE TABLE `client_survey_scoring_configurations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `version_number` INT UNSIGNED NOT NULL,
    `title` VARCHAR(160) NOT NULL,
    `status` ENUM('draft', 'active', 'retired') NOT NULL DEFAULT 'draft',
    `created_by` INT DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `activated_by` INT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `activated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_client_survey_scoring_version` (`version_number`),
    KEY `idx_client_survey_scoring_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `client_survey_scoring_sections` (
    `configuration_id` BIGINT UNSIGNED NOT NULL,
    `section_code` VARCHAR(24) NOT NULL,
    `allocation_bps` SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (`configuration_id`, `section_code`),
    CONSTRAINT `fk_survey_scoring_section_config` FOREIGN KEY (`configuration_id`)
        REFERENCES `client_survey_scoring_configurations` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_survey_scoring_section_positive` CHECK (`allocation_bps` BETWEEN 1 AND 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `client_survey_scoring_questions` (
    `configuration_id` BIGINT UNSIGNED NOT NULL,
    `section_code` VARCHAR(24) NOT NULL,
    `stakeholder_category` VARCHAR(100) NOT NULL DEFAULT '',
    `question_key` VARCHAR(100) NOT NULL,
    `weight_bps` SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (`configuration_id`, `section_code`, `stakeholder_category`, `question_key`),
    CONSTRAINT `fk_survey_scoring_question_config` FOREIGN KEY (`configuration_id`)
        REFERENCES `client_survey_scoring_configurations` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_survey_scoring_question_positive` CHECK (`weight_bps` BETWEEN 1 AND 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A single locked state row allocates version numbers and switches the active version atomically.
CREATE TABLE `client_survey_scoring_state` (
    `id` TINYINT UNSIGNED NOT NULL,
    `active_configuration_id` BIGINT UNSIGNED NOT NULL,
    `next_version_number` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `chk_client_survey_scoring_singleton` CHECK (`id` = 1),
    CONSTRAINT `fk_survey_scoring_state_active` FOREIGN KEY (`active_configuration_id`)
        REFERENCES `client_survey_scoring_configurations` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Null = historical/non-weighted survey or session created before Batch 2 binding.
-- Never backfill these from today's active configuration; that would rewrite history.
ALTER TABLE `client_survey_form_sessions`
    ADD COLUMN `scoring_configuration_id` BIGINT UNSIGNED DEFAULT NULL AFTER `survey_response_id`,
    ADD KEY `idx_survey_session_scoring_config` (`scoring_configuration_id`),
    ADD CONSTRAINT `fk_survey_session_scoring_config` FOREIGN KEY (`scoring_configuration_id`)
        REFERENCES `client_survey_scoring_configurations` (`id`) ON DELETE RESTRICT;

ALTER TABLE `clients_survey_form`
    ADD COLUMN `scoring_configuration_id` BIGINT UNSIGNED DEFAULT NULL AFTER `survey_version`,
    ADD COLUMN `survey_scoring_breakdown` LONGTEXT DEFAULT NULL AFTER `survey_answers`,
    ADD KEY `idx_client_survey_scoring_config` (`scoring_configuration_id`),
    ADD CONSTRAINT `fk_client_survey_scoring_config` FOREIGN KEY (`scoring_configuration_id`)
        REFERENCES `client_survey_scoring_configurations` (`id`) ON DELETE RESTRICT,
    ADD CONSTRAINT `chk_client_survey_scoring_breakdown_json`
        CHECK (`survey_scoring_breakdown` IS NULL OR JSON_VALID(`survey_scoring_breakdown`));

-- Baseline weights are ACTIVE only in the configuration registry.
-- Existing public survey scoring will continue unchanged until Batch 2 wires session binding.
INSERT INTO `client_survey_scoring_configurations`
    (`version_number`, `title`, `status`, `activated_at`)
VALUES (1, 'Initial weighted assessment (60 / 20 / 20)', 'active', CURRENT_TIMESTAMP);

INSERT INTO `client_survey_scoring_sections`
    (`configuration_id`, `section_code`, `allocation_bps`)
SELECT c.id, d.section_code, d.allocation_bps
FROM `client_survey_scoring_configurations` c
CROSS JOIN (
    SELECT 'performance' AS section_code, 6000 AS allocation_bps
    UNION ALL SELECT 'stakeholder', 2000
    UNION ALL SELECT 'service', 2000
) d WHERE c.version_number = 1;

INSERT INTO `client_survey_scoring_questions`
    (`configuration_id`, `section_code`, `stakeholder_category`, `question_key`, `weight_bps`)
SELECT c.id, d.section_code, d.stakeholder_category, d.question_key, d.weight_bps
FROM `client_survey_scoring_configurations` c
CROSS JOIN (
    SELECT 'performance' AS section_code, '' AS stakeholder_category, 'communication' AS question_key, 500 AS weight_bps
    UNION ALL SELECT 'performance' AS section_code, '' AS stakeholder_category, 'responsiveness' AS question_key, 500 AS weight_bps
    UNION ALL SELECT 'performance' AS section_code, '' AS stakeholder_category, 'technical_competence' AS question_key, 500 AS weight_bps
    UNION ALL SELECT 'performance' AS section_code, '' AS stakeholder_category, 'quality_of_work' AS question_key, 500 AS weight_bps
    UNION ALL SELECT 'performance' AS section_code, '' AS stakeholder_category, 'documentation' AS question_key, 500 AS weight_bps
    UNION ALL SELECT 'performance' AS section_code, '' AS stakeholder_category, 'professionalism' AS question_key, 500 AS weight_bps
    UNION ALL SELECT 'performance' AS section_code, '' AS stakeholder_category, 'project_delivery' AS question_key, 500 AS weight_bps
    UNION ALL SELECT 'performance' AS section_code, '' AS stakeholder_category, 'coordination' AS question_key, 500 AS weight_bps
    UNION ALL SELECT 'performance' AS section_code, '' AS stakeholder_category, 'problem_resolution' AS question_key, 500 AS weight_bps
    UNION ALL SELECT 'performance' AS section_code, '' AS stakeholder_category, 'complaints' AS question_key, 500 AS weight_bps
    UNION ALL SELECT 'performance' AS section_code, '' AS stakeholder_category, 'health_safety' AS question_key, 500 AS weight_bps
    UNION ALL SELECT 'performance' AS section_code, '' AS stakeholder_category, 'working_relationship' AS question_key, 500 AS weight_bps
    UNION ALL SELECT 'stakeholder' AS section_code, 'Client / Project Owner' AS stakeholder_category, 'understanding_requirements' AS question_key, 1000 AS weight_bps
    UNION ALL SELECT 'stakeholder' AS section_code, 'Client / Project Owner' AS stakeholder_category, 'delivery_expectations' AS question_key, 1000 AS weight_bps
    UNION ALL SELECT 'stakeholder' AS section_code, 'Consultant (Architect / Engineer / Quantity Surveyor)' AS stakeholder_category, 'technical_compliance' AS question_key, 1000 AS weight_bps
    UNION ALL SELECT 'stakeholder' AS section_code, 'Consultant (Architect / Engineer / Quantity Surveyor)' AS stakeholder_category, 'technical_collaboration' AS question_key, 1000 AS weight_bps
    UNION ALL SELECT 'stakeholder' AS section_code, 'Project Manager / Project Management Consultant' AS stakeholder_category, 'programme_coordination' AS question_key, 1000 AS weight_bps
    UNION ALL SELECT 'stakeholder' AS section_code, 'Project Manager / Project Management Consultant' AS stakeholder_category, 'progress_reporting' AS question_key, 1000 AS weight_bps
    UNION ALL SELECT 'stakeholder' AS section_code, 'Main Contractor / Construction Partner' AS stakeholder_category, 'site_coordination' AS question_key, 1000 AS weight_bps
    UNION ALL SELECT 'stakeholder' AS section_code, 'Main Contractor / Construction Partner' AS stakeholder_category, 'cooperation_flexibility' AS question_key, 1000 AS weight_bps
    UNION ALL SELECT 'stakeholder' AS section_code, 'Facility Manager / Building Operator' AS stakeholder_category, 'operational_reliability' AS question_key, 1000 AS weight_bps
    UNION ALL SELECT 'stakeholder' AS section_code, 'Facility Manager / Building Operator' AS stakeholder_category, 'maintenance_support' AS question_key, 1000 AS weight_bps
    UNION ALL SELECT 'stakeholder' AS section_code, 'Others' AS stakeholder_category, 'collaboration' AS question_key, 1000 AS weight_bps
    UNION ALL SELECT 'stakeholder' AS section_code, 'Others' AS stakeholder_category, 'commitments' AS question_key, 1000 AS weight_bps
    UNION ALL SELECT 'service' AS section_code, '' AS stakeholder_category, 'service_quality' AS question_key, 1000 AS weight_bps
    UNION ALL SELECT 'service' AS section_code, '' AS stakeholder_category, 'service_reliability' AS question_key, 1000 AS weight_bps
 ) d WHERE c.version_number = 1;

INSERT INTO `client_survey_scoring_state`
    (`id`, `active_configuration_id`, `next_version_number`)
SELECT 1, c.id, 2
FROM `client_survey_scoring_configurations` c
WHERE c.version_number = 1;

-- Verification, run after the migration:
-- SELECT version_number, status FROM client_survey_scoring_configurations;
-- SELECT section_code, allocation_bps FROM client_survey_scoring_sections;
-- SELECT section_code, stakeholder_category, COUNT(*) AS questions, SUM(weight_bps) AS total_bps
-- FROM client_survey_scoring_questions GROUP BY section_code, stakeholder_category;
