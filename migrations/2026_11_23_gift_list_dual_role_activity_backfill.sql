-- Dynabase | Gift List Email Management: reconcile verified 2026 PMS saves.
-- Run ONCE after deploying includes/giftLists.php. Idempotent if repeated.
-- Prerequisite: existing gift_list_pms_activity table (migration 2026_11_17).
-- No gift lists, gift recipients, permissions, email history or existing
-- first-start records are modified by this migration.
--
-- Mark a PMS team as started only after a successful audited save:
--   * PMS Admin saving OWN list
--   * Admin with is_pms_admin = 1 saving OWN list
--   * PMS User saving THEIR assigned PMS owner's list
-- An Admin working on someone else's list is never counted.
-- For item_updated audits, link the audited keyperson to the exact list;
-- unlike other save actions these audit rows have entity_type='keyperson'.
-- If there is no verified audit evidence, do not invent a start timestamp.

START TRANSACTION;

INSERT INTO gift_list_pms_activity
    (owner_pms_admin_id, gift_year, first_started_by_user_id,
     first_saved_list_id, first_source, first_started_at)
SELECT proven.owner_pms_admin_id,
       proven.gift_year,
       proven.actor_user_id,
       proven.gift_list_id,
       CASE WHEN proven.action = 'gift_list.portal_saved' THEN 'pms_portal'
            ELSE 'main_app' END,
       proven.saved_at
FROM (
    SELECT
        a.actor_user_id,
        a.action,
        a.created_at AS saved_at,
        gl.id AS gift_list_id,
        CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.owner_pms_admin_id')) AS UNSIGNED) AS owner_pms_admin_id,
        CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.gift_year')) AS UNSIGNED) AS gift_year,
        ROW_NUMBER() OVER (
            PARTITION BY
              CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.owner_pms_admin_id')) AS UNSIGNED),
              CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.gift_year')) AS UNSIGNED)
            ORDER BY a.created_at ASC, a.id ASC
        ) AS save_rank
    FROM audit_logs a
    JOIN users actor ON actor.id = a.actor_user_id
    JOIN users owner ON owner.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.owner_pms_admin_id')) AS UNSIGNED)
    JOIN gift_lists gl ON gl.gift_year = 2026 AND (
        (a.entity_type = 'gift_list'
          AND a.entity_id REGEXP '^[0-9]+$'
          AND CAST(a.entity_id AS UNSIGNED) = gl.id)
        OR (a.action = 'gift_list.item_updated'
          AND a.entity_type = 'keyperson'
          AND a.entity_id REGEXP '^[0-9]+$'
          AND gl.owner_pms_admin_id = owner.id
          AND EXISTS (
              SELECT 1 FROM gift_list_items gi
              WHERE gi.gift_list_id = gl.id
                AND gi.keyperson_id = CAST(a.entity_id AS UNSIGNED)
          ))
    )
    WHERE a.action IN (
        'gift_list.created', 'gift_list.updated', 'gift_list.bulk_updated',
        'gift_list.keypersons_added', 'gift_list.portal_saved',
        'gift_list.item_updated'
    )
      AND a.actor_role IN ('admin', 'pms_admin', 'pms_user')
      AND a.metadata IS NOT NULL AND JSON_VALID(a.metadata)
      AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.gift_year')) AS UNSIGNED) = 2026
      AND owner.status = 'active'
      AND (owner.role = 'pms_admin' OR (owner.role = 'admin' AND owner.is_pms_admin = 1))
      AND (a.action <> 'gift_list.keypersons_added'
           OR CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.added_count')) AS UNSIGNED) > 0)
      AND (
          (a.actor_role = 'pms_admin'
              AND actor.role = 'pms_admin' AND actor.id = owner.id)
          OR (a.actor_role = 'admin'
              AND actor.role = 'admin' AND actor.is_pms_admin = 1 AND actor.id = owner.id)
          OR (a.actor_role = 'pms_user'
              AND actor.role = 'pms_user' AND actor.parent_pms_admin_id = owner.id)
      )
) proven
WHERE proven.save_rank = 1
  AND NOT EXISTS (
      SELECT 1 FROM gift_list_pms_activity existing
      WHERE existing.owner_pms_admin_id = proven.owner_pms_admin_id
        AND existing.gift_year = proven.gift_year
  )
ON DUPLICATE KEY UPDATE id = id;

COMMIT;

-- Read-only verification for all active PMS owners (including dual-role Admins).
-- Compare the selected Gift Year in Email Management with this gift_year.
SELECT u.id AS pms_owner_user_id,
       CONCAT_WS(' ', u.first_name, u.last_name) AS pms_owner,
       u.role,
       u.is_pms_admin,
       gl.id AS gift_list_id,
       pa.first_saved_list_id,
       pa.first_started_by_user_id,
       pa.first_started_at,
       CASE WHEN pa.id IS NULL THEN 'Not Started' ELSE 'Started' END AS email_management_status
FROM users u
LEFT JOIN gift_lists gl ON gl.owner_pms_admin_id = u.id AND gl.gift_year = 2026
LEFT JOIN gift_list_pms_activity pa
  ON pa.owner_pms_admin_id = u.id AND pa.gift_year = 2026
WHERE u.status = 'active'
  AND (u.role = 'pms_admin' OR (u.role = 'admin' AND u.is_pms_admin = 1))
ORDER BY u.first_name, u.last_name, u.id;
