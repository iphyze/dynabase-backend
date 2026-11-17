<?php
declare(strict_types=1);

/**
 * Historical PMS gift-list start reconciliation. CLI ONLY, dry run by default.
 *
 * php scripts/reconcileGiftListPmsActivity.php --year=2026
 * php scripts/reconcileGiftListPmsActivity.php --year=2026 --apply
 *
 * Audits must describe a completed gift-list save with an exact list, owner and
 * gift-year match. Login events, recipient counts and created_by are not proof.
 * Historical team membership is validated against the current relationship;
 * if reassignment occurred, the candidate requires manual review.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/connection.php';
require_once dirname(__DIR__) . '/includes/giftListActivityReconciliation.php';

function giftActivityReconcileCandidates(mysqli $conn, int $year): array
{
    // Exact list ID, owner/year metadata and the audit-time PMS role are all
    // mandatory; inferred ownership from the last editor alone is unsafe.
    $sql = <<<'SQL'
SELECT a.id AS audit_id, a.actor_user_id, a.actor_role, a.action,
       a.metadata, a.created_at AS saved_at,
       gl.id AS gift_list_id, gl.owner_pms_admin_id, gl.gift_year,
       u.role AS current_role,
       u.parent_pms_admin_id AS current_parent_pms_admin_id
FROM audit_logs a
JOIN gift_lists gl ON a.entity_type = 'gift_list'
    AND a.entity_id = CAST(gl.id AS CHAR)
JOIN users u ON u.id = a.actor_user_id
WHERE gl.gift_year = ?
  AND a.action IN (
      'gift_list.created', 'gift_list.updated',
      'gift_list.bulk_updated', 'gift_list.keypersons_added',
      'gift_list.portal_saved'
  )
  AND a.actor_role IN ('pms_admin', 'pms_user')
  AND a.metadata IS NOT NULL
  AND JSON_VALID(a.metadata)
ORDER BY a.created_at ASC, a.id ASC
SQL;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function giftActivityReconcileMain(mysqli $conn, array $argv): int
{
    $options = getopt('', ['year:', 'apply', 'help']);
    if (isset($options['help'])) {
        echo "Usage: php scripts/reconcileGiftListPmsActivity.php --year=2026 [--apply]\n";
        return 0;
    }
    $yearValue = $options['year'] ?? (string) date('Y');
    if (!is_string($yearValue) || !preg_match('/^20\d{2}$/', $yearValue)) {
        fwrite(STDERR, "Invalid --year value.\n");
        return 2;
    }
    $year = (int) $yearValue;
    $apply = isset($options['apply']);

    $check = $conn->query("SHOW TABLES LIKE 'gift_list_pms_activity'");
    if ($check->num_rows === 0) {
        fwrite(STDERR, "Run Batch 1 gift_list_pms_activity migration first.\n");
        return 2;
    }
    $check->free();

    $stmt = $conn->prepare('SELECT owner_pms_admin_id, gift_year FROM gift_list_pms_activity WHERE gift_year = ?');
    $stmt->bind_param('i', $year);
    $stmt->execute();
    $existing = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $existing[(int) $row['owner_pms_admin_id'] . ':' . (int) $row['gift_year']] = true;
    }
    $stmt->close();
    $plan = giftActivityReconcilePlan(giftActivityReconcileCandidates($conn, $year), $existing);
    echo ($apply ? 'APPLY' : 'DRY RUN') . " — gift year {$year}\n";
    echo 'Candidates: ' . count($plan['candidates']) . ', excluded audit rows: ' . $plan['excluded']
        . ', previously recorded audit rows: ' . $plan['already'] . "\n";
    foreach ($plan['candidates'] as $row) {
        printf("Owner %d, list %d, actor %d (%s), first save %s, audit #%d\n",
            $row['owner_pms_admin_id'], $row['gift_list_id'], $row['actor_user_id'],
            $row['actor_role'], $row['saved_at'], $row['audit_id']);
    }
    if (!$apply) {
        echo "No database changes made. Review candidates, then rerun with --apply.\n";
        return 0;
    }
    if ($plan['candidates'] === []) {
        echo "No changes required.\n";
        return 0;
    }

    // Upsert never replaces existing owner/year markers; live activity wins.
    // One transaction ensures a partial script failure cannot leave a subset.
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'INSERT INTO gift_list_pms_activity
             (owner_pms_admin_id, gift_year, first_started_by_user_id,
              first_saved_list_id, first_source, first_started_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id'
        );
        $inserted = 0;
        foreach ($plan['candidates'] as $row) {
            $owner = (int) $row['owner_pms_admin_id'];
            $actor = (int) $row['actor_user_id'];
            $listId = (int) $row['gift_list_id'];
            $source = $row['action'] === 'gift_list.portal_saved' ? 'pms_portal' : 'main_app';
            $savedAt = (string) $row['saved_at'];
            $stmt->bind_param('iiiiss', $owner, $year, $actor, $listId, $source, $savedAt);
            $stmt->execute();
            $inserted += $stmt->affected_rows;
        }
        $stmt->close();
        $conn->commit();
        echo "Inserted {$inserted} historical PMS activity marker(s).\n";
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        exit(giftActivityReconcileMain($conn, $argv));
    } catch (Throwable $e) {
        fwrite(STDERR, 'Reconciliation failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
