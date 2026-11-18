-- One-time, idempotent backfill for historical 2026 PMS gift-list activity.
-- Run AFTER checking previewGiftListPmsActivity2026.sql and confirming the
-- gift_list_pms_activity table already exists (2026_11_17 migration).
-- This does not modify gift_lists or gift_list_items.
-- Existing first-start markers are NEVER overwritten.
-- Match audit entity IDs numerically to avoid mixed text collations.
-- Audited ownership is authoritative for a historical save; the current
-- owner of the list may differ due to later reassignment.
START TRANSACTION;
INSERT INTO gift_list_pms_activity
  (owner_pms_admin_id, gift_year, first_started_by_user_id,
   first_saved_list_id, first_source, first_started_at)
SELECT first_save.owner_pms_admin_id,
       first_save.gift_year,
       first_save.actor_user_id,
       first_save.gift_list_id,
       CASE WHEN first_save.action = 'gift_list.portal_saved' THEN 'pms_portal' ELSE 'main_app' END,
       first_save.saved_at
FROM (

    SELECT
        a.id AS audit_id,
        a.action,
        a.actor_user_id,
        a.actor_role,
        gl.id AS gift_list_id,
        CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.owner_pms_admin_id')) AS UNSIGNED) AS owner_pms_admin_id,
        CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.gift_year')) AS UNSIGNED) AS gift_year,
        gl.owner_pms_admin_id AS current_list_owner_id,
        a.created_at AS saved_at,
        ROW_NUMBER() OVER (
            PARTITION BY
                CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.owner_pms_admin_id')) AS UNSIGNED),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.gift_year')) AS UNSIGNED)
            ORDER BY a.created_at ASC, a.id ASC
        ) AS save_rank
    FROM audit_logs a
    JOIN users actor ON actor.id = a.actor_user_id
    JOIN gift_lists gl ON a.entity_type = 'gift_list'
        AND a.entity_id REGEXP '^[0-9]+$'
        AND CAST(a.entity_id AS UNSIGNED) = gl.id
    JOIN users owner ON owner.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.owner_pms_admin_id')) AS UNSIGNED)
    WHERE a.action IN (
        'gift_list.portal_saved',
        'gift_list.created',
        'gift_list.updated',
        'gift_list.bulk_updated',
        'gift_list.keypersons_added'
    )
      AND a.actor_role IN ('pms_admin', 'pms_user')
      AND a.metadata IS NOT NULL
      AND JSON_VALID(a.metadata)
      AND gl.gift_year = 2026
      AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.gift_year')) AS UNSIGNED) = 2026
      AND owner.status = 'active'
      AND (owner.role = 'pms_admin' OR owner.is_pms_admin = 1)
      AND (
            (a.actor_role = 'pms_admin' AND actor.role = 'pms_admin' AND actor.id = owner.id)
         OR (a.actor_role = 'pms_user' AND actor.role = 'pms_user'
             AND actor.parent_pms_admin_id = owner.id)
      )
) first_save
WHERE first_save.save_rank = 1
  AND NOT EXISTS (
    SELECT 1 FROM gift_list_pms_activity existing
    WHERE existing.owner_pms_admin_id = first_save.owner_pms_admin_id
      AND existing.gift_year = first_save.gift_year
  )
ON DUPLICATE KEY UPDATE id = id;
COMMIT;

-- Verification: the genuinely started PMS teams for 2026.
SELECT CONCAT_WS(' ', owner.first_name, owner.last_name) AS pms_owner,
       activity.owner_pms_admin_id AS owner_id,
       activity.first_started_by_user_id AS first_saved_by_user_id,
       activity.first_started_at AS first_saved_at,
       activity.first_saved_list_id AS historical_list_id,
       'Started' AS expected_email_management_status
FROM gift_list_pms_activity activity
JOIN users owner ON owner.id = activity.owner_pms_admin_id
WHERE activity.gift_year = 2026
ORDER BY activity.first_started_at;
