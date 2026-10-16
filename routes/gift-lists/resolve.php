<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/giftLists.php';

requireMethod('GET');
$authUser = authenticateUser();
$giftYear = validateGiftYear($_GET['gift_year'] ?? date('Y'));
$requestedOwnerId = isset($_GET['owner_pms_admin_id']) ? (int) $_GET['owner_pms_admin_id'] : null;
$ownerId = resolveGiftOwnerPmsAdminId($conn, $authUser, $requestedOwnerId);

$list = dbFetchOne(
    $conn,
    'SELECT id FROM gift_lists WHERE gift_year = ? AND owner_pms_admin_id = ? LIMIT 1',
    'ii',
    [$giftYear, $ownerId]
);
if ($list) {
    // Never expose another PMS team's list, even if ownership rules change later.
    assertGiftListAccessible($conn, $authUser, (int) $list['id']);
}

jsonResponse([
    'status' => 'Success',
    'data' => [
        'gift_year' => $giftYear,
        'owner_pms_admin_id' => $ownerId,
        'existing_list_id' => $list ? (int) $list['id'] : null,
    ],
]);
