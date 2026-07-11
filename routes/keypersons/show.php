<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/validation.php';

requireMethod('GET');
$authUser = authenticateUser();
$id = requiredIntFromRequest('id');
assertKeypersonAccessible($conn, $authUser, $id, true);

[$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'k', 'i', [$id]);
$keyperson = dbFetchOne(
    $conn,
    "SELECT k.*, NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), '') AS owner_pms_admin_name,
            u.email AS owner_pms_admin_email
     FROM keypersons_table k
     LEFT JOIN users u ON u.id = k.owner_pms_admin_id
     WHERE k.id = ?{$scopeSql}
     LIMIT 1",
    $scopeTypes,
    $scopeParams
);

if (!$keyperson) {
    throw new RuntimeException('Keyperson not found or not accessible.', 404);
}

$logs = dbFetchAll(
    $conn,
    "SELECT id, clients_id, clients_name, key_person, log, created_by, created_by_id, updated_by, updated_by_id, created_at, updated_at
     FROM log_table
     WHERE clients_id = ? AND key_person = ?
     ORDER BY created_at DESC, id DESC
     LIMIT 50",
    'is',
    [(int) $keyperson['clients_id'], (string) $keyperson['key_person']]
);

$giftHistory = dbFetchAll(
    $conn,
    "SELECT gl.gift_year, gli.gift_decision, gli.gift_rate, gli.notes, gli.source, gli.is_verified,
            gli.legacy_source_updated_at, gli.created_at, gli.updated_at,
            NULLIF(TRIM(CONCAT(COALESCE(cu.first_name, ''), ' ', COALESCE(cu.last_name, ''))), '') AS updated_by_name
     FROM gift_list_items gli
     INNER JOIN gift_lists gl ON gl.id = gli.gift_list_id
     LEFT JOIN users cu ON cu.id = gli.updated_by_id
     WHERE gli.keyperson_id = ?
     ORDER BY gl.gift_year DESC, gli.updated_at DESC",
    'i',
    [$id]
);

$currentYearGift = null;
foreach ($giftHistory as $giftEntry) {
    if ((int) $giftEntry['gift_year'] === (int) date('Y')) {
        $currentYearGift = $giftEntry;
        break;
    }
}
$keyperson['gift_status'] = ($currentYearGift['gift_decision'] ?? '') === 'selected' ? 'Yes' : 'No';
$keyperson['gift_type'] = $currentYearGift['gift_rate'] ?? 'N/A';
$keyperson['annual_gift_decision'] = $currentYearGift['gift_decision'] ?? 'pending';
$keyperson['annual_gift_rate'] = $currentYearGift['gift_rate'] ?? null;
$keyperson['gift_year'] = (int) date('Y');

jsonResponse([
    'status' => 'Success',
    'message' => 'Keyperson retrieved successfully.',
    'data' => [
        'keyperson' => $keyperson,
        'logs' => $logs,
        'gift_history' => $giftHistory,
    ],
]);
