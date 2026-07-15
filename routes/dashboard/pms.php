<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/giftLists.php';

requireMethod('GET');
$authUser = authenticateUser();
if (!isPmsWorkspaceUser($authUser)) {
    throw new RuntimeException('This dashboard is only available to PMS teams.', 403);
}

$giftYear = validateGiftYear($_GET['gift_year'] ?? date('Y'));
$ownerPmsAdminId = resolveGiftOwnerPmsAdminId($conn, $authUser, null);

$clientSummary = dbFetchOne(
    $conn,
    "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active
     FROM clients_table WHERE owner_pms_admin_id = ?",
    'i',
    [$ownerPmsAdminId]
) ?? [];
$keypersonSummary = dbFetchOne(
    $conn,
    "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active
     FROM keypersons_table WHERE owner_pms_admin_id = ?",
    'i',
    [$ownerPmsAdminId]
) ?? [];
$giftSummary = dbFetchOne(
    $conn,
    "SELECT COUNT(items.id) AS recipients,
            COUNT(DISTINCT items.client_id) AS gift_clients,
            MAX(items.updated_at) AS last_updated_at,
            MAX(gl.id) AS gift_list_id
     FROM gift_lists gl
     LEFT JOIN gift_list_items items ON items.gift_list_id = gl.id
     WHERE gl.gift_year = ? AND gl.owner_pms_admin_id = ?",
    'ii',
    [$giftYear, $ownerPmsAdminId]
) ?? [];
$rateRows = dbFetchAll(
    $conn,
    "SELECT items.gift_rate, COUNT(*) AS total
     FROM gift_lists gl
     INNER JOIN gift_list_items items ON items.gift_list_id = gl.id
     WHERE gl.gift_year = ? AND gl.owner_pms_admin_id = ?
     GROUP BY items.gift_rate",
    'ii',
    [$giftYear, $ownerPmsAdminId]
);
$rateBreakdown = array_fill_keys(DYNABASE_GIFT_RATES, 0);
foreach ($rateRows as $row) {
    $rate = (string) ($row['gift_rate'] ?? '');
    if (array_key_exists($rate, $rateBreakdown)) $rateBreakdown[$rate] = (int) $row['total'];
}

$recentClients = dbFetchAll(
    $conn,
    "SELECT id, clients_name, clients_category, clients_hq_location, status, updated_at, created_at
     FROM clients_table WHERE owner_pms_admin_id = ? AND status = 'active'
     ORDER BY COALESCE(updated_at, created_at) DESC, id DESC LIMIT 5",
    'i',
    [$ownerPmsAdminId]
);
$recentKeypersons = dbFetchAll(
    $conn,
    "SELECT k.id, k.key_person, k.title, k.clients_name, k.clients_category,
            k.key_persons_email, k.key_persons_tel, k.updated_at, k.created_at,
            items.gift_rate
     FROM keypersons_table k
     LEFT JOIN gift_lists gl ON gl.owner_pms_admin_id = k.owner_pms_admin_id AND gl.gift_year = ?
     LEFT JOIN gift_list_items items ON items.gift_list_id = gl.id AND items.keyperson_id = k.id
     WHERE k.owner_pms_admin_id = ? AND k.status = 'active'
     ORDER BY COALESCE(k.updated_at, k.created_at) DESC, k.id DESC LIMIT 6",
    'ii',
    [$giftYear, $ownerPmsAdminId]
);
$owner = dbFetchOne(
    $conn,
    'SELECT id, first_name, last_name, email, role, is_pms_admin FROM users WHERE id = ? LIMIT 1',
    'i',
    [$ownerPmsAdminId]
);
$teamSummary = dbFetchOne(
    $conn,
    "SELECT SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_members,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_members
     FROM users
     WHERE parent_pms_admin_id = ? AND role = 'pms_user'",
    'i',
    [$ownerPmsAdminId]
) ?? [];
$recentGiftItems = dbFetchAll(
    $conn,
    "SELECT items.id, items.gift_rate, items.notes, items.updated_at,
            c.clients_name, k.key_person
     FROM gift_lists gl
     INNER JOIN gift_list_items items ON items.gift_list_id = gl.id
     LEFT JOIN clients_table c ON c.id = items.client_id
     LEFT JOIN keypersons_table k ON k.id = items.keyperson_id
     WHERE gl.gift_year = ? AND gl.owner_pms_admin_id = ?
     ORDER BY items.updated_at DESC, items.id DESC
     LIMIT 5",
    'ii',
    [$giftYear, $ownerPmsAdminId]
);
$recipients = (int) ($giftSummary['recipients'] ?? 0);
$activeKeypersons = (int) ($keypersonSummary['active'] ?? 0);
$activeClients = (int) ($clientSummary['active'] ?? 0);
$recipientCoverage = $activeKeypersons > 0 ? round(($recipients / $activeKeypersons) * 100, 1) : 0.0;
$clientCoverage = $activeClients > 0 ? round((((int) ($giftSummary['gift_clients'] ?? 0)) / $activeClients) * 100, 1) : 0.0;

jsonResponse([
    'status' => 'Success',
    'message' => 'PMS dashboard retrieved successfully.',
    'data' => [
        'gift_year' => $giftYear,
        'owner' => $owner ? [
            'id' => (int) $owner['id'],
            'name' => trim((string) $owner['first_name'] . ' ' . (string) $owner['last_name']),
            'email' => $owner['email'],
            'role' => $owner['role'],
            'is_pms_admin' => (int) $owner['is_pms_admin'] === 1,
        ] : null,
        'summary' => [
            'clients' => $activeClients,
            'all_clients' => (int) ($clientSummary['total'] ?? 0),
            'keypersons' => $activeKeypersons,
            'all_keypersons' => (int) ($keypersonSummary['total'] ?? 0),
            'recipients' => $recipients,
            'unlisted_keypersons' => max(0, $activeKeypersons - $recipients),
            'gift_clients' => (int) ($giftSummary['gift_clients'] ?? 0),
            'gift_list_id' => isset($giftSummary['gift_list_id']) && $giftSummary['gift_list_id'] !== null ? (int) $giftSummary['gift_list_id'] : null,
            'last_gift_update_at' => $giftSummary['last_updated_at'] ?? null,
            'rate_breakdown' => $rateBreakdown,
            'recipient_coverage_percent' => $recipientCoverage,
            'client_coverage_percent' => $clientCoverage,
            'team_members' => (int) ($teamSummary['active_members'] ?? 0),
            'pending_team_members' => (int) ($teamSummary['pending_members'] ?? 0),
        ],
        'recent_clients' => $recentClients,
        'recent_keypersons' => $recentKeypersons,
        'recent_gift_items' => $recentGiftItems,
    ],
]);
