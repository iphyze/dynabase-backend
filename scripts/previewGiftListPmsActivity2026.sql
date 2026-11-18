-- READ-ONLY PREVIEW: run in phpMyAdmin before applying the matching backfill.
-- Evidence is a successful audited PMS-team gift-list save, never a login,
-- recipient count, or a save by a regular Admin/User.
-- We use the audited owner, NOT the gift list's current owner (lists can move).
SELECT CONCAT_WS(' ', owner.first_name, owner.last_name) AS pms_owner,
       first_save.owner_pms_admin_id AS owner_id,
       first_save.actor_user_id AS saved_by_user_id,
       first_save.actor_role AS saved_by_role,
       first_save.gift_list_id AS original_saved_list_id,
       first_save.current_list_owner_id AS current_list_owner_id,
       first_save.saved_at AS first_confirmed_save,
       CASE WHEN existing.id IS NULL THEN 'WILL MARK STARTED' ELSE 'ALREADY TRACKED' END AS action
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
JOIN users owner ON owner.id = first_save.owner_pms_admin_id
LEFT JOIN gift_list_pms_activity existing
  ON existing.owner_pms_admin_id = first_save.owner_pms_admin_id
 AND existing.gift_year = first_save.gift_year
WHERE first_save.save_rank = 1
ORDER BY first_save.saved_at, first_save.owner_pms_admin_id;
