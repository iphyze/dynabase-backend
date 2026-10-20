<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';

requireMethod('GET');
$user = pmsPortalCurrentUser($conn);
$keypersonId = (int) ($_GET['keyperson_id'] ?? 0);
if ($keypersonId <= 0) {
    throw new RuntimeException('Key Person is required.', 422);
}
// Only the logged-in PMS owner's previous year scores may be suggested.
$rate = dbFetchOne($conn,
    'SELECT gli.gift_rate FROM gift_list_items gli
     JOIN gift_lists gl ON gl.id = gli.gift_list_id
     WHERE gl.owner_pms_admin_id = ? AND gl.gift_year = ?
       AND gli.keyperson_id = ? AND gli.gift_decision = "selected" LIMIT 1',
    'iii', [(int) $user['owner_pms_admin_id'], (int) date('Y') - 1, $keypersonId]
);
header('Cache-Control: no-store');
jsonResponse(['status' => 'Success', 'data' => ['gift_rate' => $rate['gift_rate'] ?? null]]);
