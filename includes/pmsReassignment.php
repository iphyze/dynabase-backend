<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/dbHelpers.php';

/**
 * PMS ownership hand-over.
 *
 * When a PMS admin loses PMS capability (role change, or the PMS flag removed from an admin), every
 * record that hangs off them must move to another PMS admin in the same transaction - otherwise their
 * clients, contact list and gift lists become invisible to every PMS workspace.
 */

/** Tables whose rows are scoped to a PMS admin through `owner_pms_admin_id` (besides clients/gift lists). */
const DYNABASE_PMS_OWNED_TABLES = [
    'keypersons_table', 'submission_registers', 'prequalification_table', 'tender_document_table',
    'technical_document_table', 'clients_keypersons_table', 'project_info_table', 'log_table',
    'agreement_registers', 'web_of_influence_table', 'clients_survey_form', 'client_survey_invitations',
];

function userIsPmsCapable(string $role, int|bool $isPmsAdmin): bool
{
    return $role === DYNABASE_ROLE_PMS_ADMIN || (int) $isPmsAdmin === 1;
}

/** Counts of what a PMS admin currently owns. */
function pmsOwnedRecordCounts(mysqli $conn, int $pmsAdminId): array
{
    $counts = [
        'clients' => dbScalarInt($conn, 'SELECT COUNT(*) AS total FROM clients_table WHERE owner_pms_admin_id = ?', 'i', [$pmsAdminId]),
        'keypersons' => dbScalarInt($conn, 'SELECT COUNT(*) AS total FROM keyperson_pms_assignments WHERE pms_admin_id = ?', 'i', [$pmsAdminId]),
        'gift_lists' => dbScalarInt($conn, 'SELECT COUNT(*) AS total FROM gift_lists WHERE owner_pms_admin_id = ?', 'i', [$pmsAdminId]),
        'pms_users' => dbScalarInt($conn, "SELECT COUNT(*) AS total FROM users WHERE parent_pms_admin_id = ? AND role = 'pms_user'", 'i', [$pmsAdminId]),
        'other_records' => 0,
    ];
    foreach (DYNABASE_PMS_OWNED_TABLES as $table) {
        if ($table === 'keypersons_table') {
            continue;
        }
        $counts['other_records'] += dbScalarInt($conn, "SELECT COUNT(*) AS total FROM `{$table}` WHERE owner_pms_admin_id = ?", 'i', [$pmsAdminId]);
    }
    $counts['total'] = $counts['clients'] + $counts['keypersons'] + $counts['gift_lists'] + $counts['pms_users'] + $counts['other_records'];
    return $counts;
}

/** Active PMS-capable users a hand-over can go to (excluding the users being demoted). */
function pmsReassignmentCandidates(mysqli $conn, array $excludeUserIds): array
{
    $excludeUserIds = array_values(array_unique(array_map('intval', $excludeUserIds))) ?: [0];
    $placeholders = implode(',', array_fill(0, count($excludeUserIds), '?'));
    $rows = dbFetchAll(
        $conn,
        "SELECT id, first_name, last_name, email FROM users
         WHERE status = 'active' AND (role = 'pms_admin' OR is_pms_admin = 1) AND id NOT IN ({$placeholders})
         ORDER BY first_name, last_name, id",
        str_repeat('i', count($excludeUserIds)),
        $excludeUserIds
    );
    return array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'name' => trim($row['first_name'] . ' ' . $row['last_name']) ?: $row['email'],
        'email' => $row['email'],
    ], $rows);
}

/**
 * Stop the request with a 409 the UI turns into a "choose a PMS admin" prompt.
 * $demotions = [['id' => int, 'name' => string, 'email' => string, 'counts' => array], ...]
 */
function respondPmsReassignmentRequired(mysqli $conn, array $demotions): never
{
    jsonResponse([
        'status' => 'ReassignmentRequired',
        'message' => count($demotions) === 1
            ? sprintf('%s owns clients and key persons. Choose the PMS Admin who should take them over.', $demotions[0]['name'])
            : 'Some selected users own clients and key persons. Choose the PMS Admin who should take them over.',
        'data' => [
            'users' => array_values($demotions),
            'candidates' => pmsReassignmentCandidates($conn, array_column($demotions, 'id')),
        ],
    ], 409);
}

/** Validates the PMS admin chosen to receive the records. */
function resolvePmsReassignmentTarget(mysqli $conn, mixed $requested, array $demotedUserIds): int
{
    $targetId = (int) $requested;
    if ($targetId <= 0) {
        throw new RuntimeException('Choose the PMS Admin who should take over the records.', 422);
    }
    if (in_array($targetId, array_map('intval', $demotedUserIds), true)) {
        throw new RuntimeException('Records cannot be handed over to a user who is losing PMS access.', 422);
    }
    $row = dbFetchOne(
        $conn,
        "SELECT id FROM users WHERE id = ? AND status = 'active' AND (role = 'pms_admin' OR is_pms_admin = 1) LIMIT 1",
        'i',
        [$targetId]
    );
    if (!$row) {
        throw new RuntimeException('The selected PMS Admin is not available.', 422);
    }
    return $targetId;
}

/**
 * Moves everything owned by $fromId to $toId. Must run inside the caller's transaction.
 * Gift lists merge by year (one list per owner per year); a recipient on both lists keeps the better rate.
 */
function reassignPmsOwnership(mysqli $conn, int $fromId, int $toId, int $actorId): array
{
    if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
        throw new RuntimeException('Invalid PMS ownership hand-over.', 422);
    }

    $moved = ['clients' => 0, 'keypersons' => 0, 'gift_lists' => 0, 'gift_items' => 0, 'pms_users' => 0, 'other_records' => 0];
    $affected = static function (mysqli_stmt $stmt): int {
        $rows = (int) $stmt->affected_rows;
        $stmt->close();
        return max(0, $rows);
    };

    $moved['clients'] = $affected(dbExecute($conn,
        'UPDATE clients_table SET owner_pms_admin_id = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE owner_pms_admin_id = ?',
        'iii', [$toId, $actorId, $fromId]));

    // Contact list: keep every contact, add to the new owner (no duplicates), then drop the old links.
    $moved['keypersons'] = $affected(dbExecute($conn,
        'INSERT IGNORE INTO keyperson_pms_assignments (keyperson_id, pms_admin_id, assigned_by_id)
         SELECT keyperson_id, ?, ? FROM keyperson_pms_assignments WHERE pms_admin_id = ?',
        'iii', [$toId, $actorId, $fromId]));
    dbExecute($conn, 'DELETE FROM keyperson_pms_assignments WHERE pms_admin_id = ?', 'i', [$fromId])->close();

    foreach (DYNABASE_PMS_OWNED_TABLES as $table) {
        $moved['other_records'] += $affected(dbExecute($conn,
            "UPDATE `{$table}` SET owner_pms_admin_id = ? WHERE owner_pms_admin_id = ?", 'ii', [$toId, $fromId]));
    }

    // Gift lists: same year -> merge items into the new owner's list; otherwise move the whole list.
    $rank = "IFNULL(NULLIF(FIELD(%s.gift_rate,'A+','A','B+','B','C+','C','D'),0),99)";
    foreach (dbFetchAll($conn, 'SELECT id, gift_year FROM gift_lists WHERE owner_pms_admin_id = ?', 'i', [$fromId]) as $list) {
        $fromListId = (int) $list['id'];
        $target = dbFetchOne($conn, 'SELECT id FROM gift_lists WHERE owner_pms_admin_id = ? AND gift_year = ? LIMIT 1', 'ii', [$toId, (int) $list['gift_year']]);
        if (!$target) {
            dbExecute($conn, 'UPDATE gift_lists SET owner_pms_admin_id = ?, updated_by_id = ? WHERE id = ?', 'iii', [$toId, $actorId, $fromListId])->close();
            $moved['gift_lists']++;
            continue;
        }
        $toListId = (int) $target['id'];
        // recipient on both lists: keep whichever has the better rate
        dbExecute($conn,
            'DELETE f FROM gift_list_items f JOIN gift_list_items t ON t.gift_list_id = ? AND t.keyperson_id = f.keyperson_id
             WHERE f.gift_list_id = ? AND ' . sprintf($rank, 't') . ' <= ' . sprintf($rank, 'f'),
            'ii', [$toListId, $fromListId])->close();
        dbExecute($conn,
            'DELETE t FROM gift_list_items t JOIN gift_list_items f ON f.gift_list_id = ? AND f.keyperson_id = t.keyperson_id
             WHERE t.gift_list_id = ?',
            'ii', [$fromListId, $toListId])->close();
        $moved['gift_items'] += $affected(dbExecute($conn,
            'UPDATE gift_list_items SET gift_list_id = ?, updated_by_id = ? WHERE gift_list_id = ?', 'iii', [$toListId, $actorId, $fromListId]));
        dbExecute($conn, 'DELETE FROM gift_lists WHERE id = ?', 'i', [$fromListId])->close();
        dbExecute($conn, 'UPDATE gift_lists SET updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', 'ii', [$actorId, $toListId])->close();
        $moved['gift_lists']++;
    }

    // Gift recipients must be on their list owner's contact list.
    dbExecute($conn,
        'INSERT IGNORE INTO keyperson_pms_assignments (keyperson_id, pms_admin_id, assigned_by_id)
         SELECT DISTINCT gli.keyperson_id, gl.owner_pms_admin_id, ? FROM gift_list_items gli
         JOIN gift_lists gl ON gl.id = gli.gift_list_id WHERE gl.owner_pms_admin_id = ?',
        'ii', [$actorId, $toId])->close();

    $moved['pms_users'] = $affected(dbExecute($conn,
        "UPDATE users SET parent_pms_admin_id = ?, updated_by = ? WHERE parent_pms_admin_id = ? AND role = 'pms_user'",
        'iii', [$toId, $actorId, $fromId]));

    return $moved;
}
