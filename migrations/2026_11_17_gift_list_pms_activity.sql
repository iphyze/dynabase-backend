-- Run once before deploying the corresponding PHP changes.
-- Tracks genuine PMS-team saves independently of Admin-prepared list content.
-- Historical saves are deliberately not inferred here: Batch 3 reconciles
-- trustworthy audit records before marking older lists as started.
CREATE TABLE IF NOT EXISTS gift_list_pms_activity (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_pms_admin_id INT NOT NULL,
    gift_year SMALLINT UNSIGNED NOT NULL,
    first_started_by_user_id INT NOT NULL,
    first_saved_list_id BIGINT UNSIGNED NOT NULL,
    first_source ENUM('main_app', 'pms_portal') NOT NULL,
    first_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gift_list_pms_activity_owner_year (owner_pms_admin_id, gift_year),
    KEY idx_gift_list_pms_activity_actor (first_started_by_user_id),
    KEY idx_gift_list_pms_activity_list (first_saved_list_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
