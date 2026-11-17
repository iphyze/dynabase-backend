-- Additive migration for Lambert Stakeholder Survey (version 2).
-- Run once on the existing Dynabase database, after taking a backup.
-- Existing rows remain version 1; all old survey fields and constraints stay intact.
ALTER TABLE `clients_survey_form`
    ADD COLUMN `survey_version` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `submission_reference`,
    ADD COLUMN `stakeholder_category` VARCHAR(100) DEFAULT NULL AFTER `survey_version`,
    ADD COLUMN `nature_of_engagement` VARCHAR(100) DEFAULT NULL AFTER `stakeholder_category`,
    ADD COLUMN `survey_rated_count` SMALLINT UNSIGNED NOT NULL DEFAULT 8 AFTER `nature_of_engagement`,
    ADD COLUMN `survey_answers` LONGTEXT DEFAULT NULL AFTER `survey_rated_count`,
    ADD CONSTRAINT `chk_client_survey_answers_json`
        CHECK (`survey_answers` IS NULL OR JSON_VALID(`survey_answers`));
