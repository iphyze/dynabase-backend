<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';
require_once __DIR__ . '/../../includes/giftLists.php';

requireMethod('GET');
$user = pmsPortalCurrentUser($conn);
$year = (int) date('Y');
$ownerId = (int) $user['owner_pms_admin_id'];
$existing = dbFetchOne(
    $conn,
    'SELECT id FROM gift_lists WHERE gift_year = ? AND owner_pms_admin_id = ? LIMIT 1',
    'ii', [$year, $ownerId]
);

if ($existing) {
    // This annual list is the source of truth after the first save. Never import last year's
    // recipients over a PMS team's previous additions, removals or edited scores.
    $list = dbFetchOne($conn,
        'SELECT gl.*, u.first_name AS owner_first_name, u.last_name AS owner_last_name,
                u.email AS owner_email, u.role AS owner_role, u.is_pms_admin AS owner_is_pms_admin
         FROM gift_lists gl JOIN users u ON u.id = gl.owner_pms_admin_id
         WHERE gl.id = ? AND gl.owner_pms_admin_id = ? LIMIT 1',
        'ii', [(int) $existing['id'], $ownerId]
    );
    $items = giftListResponsePayload($conn, $list)['items'];
} else {
    // Prefill only when the current year does not exist. The rows are still unsaved.
    // Ignore inactive/missing persons or clients, which the normal gift-list save also rejects.
    $items = dbFetchAll(
        $conn,
        'SELECT gli.keyperson_id, k.clients_id AS client_id, gli.gift_rate, gli.notes,
                k.key_person AS keyperson_name_snapshot, c.clients_name AS client_name_snapshot
         FROM gift_list_items gli
         JOIN gift_lists gl ON gl.id = gli.gift_list_id
         JOIN keypersons_table k ON k.id = gli.keyperson_id AND k.status = "active"
         JOIN clients_table c ON c.id = k.clients_id AND c.status = "active"
         WHERE gl.owner_pms_admin_id = ? AND gl.gift_year = ?
           AND gli.gift_decision = "selected" AND gli.gift_rate IN ("A+", "A", "B+", "B", "C+", "C", "D")
         ORDER BY c.clients_name, k.key_person, k.id
         LIMIT 500',
        'ii', [$ownerId, $year - 1]
    );
}

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'data' => [
        'gift_year' => $year,
        'owner_pms_admin_id' => $ownerId,
        'existing_list_id' => $existing ? (int) $existing['id'] : null,
        'source' => $existing ? 'saved' : 'previous_year',
        'items' => $items,
    ],
]);
