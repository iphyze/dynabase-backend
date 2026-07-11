<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/giftLists.php';

requireMethod('GET');
$authUser = authenticateUser();
$giftListId = (int) ($_GET['id'] ?? 0);
if ($giftListId <= 0) {
    throw new RuntimeException('Gift-list ID is required.', 422);
}

$list = assertGiftListAccessible($conn, $authUser, $giftListId);
jsonResponse([
    'status' => 'Success',
    'message' => 'Gift list retrieved successfully.',
    'data' => giftListResponsePayload($conn, $list),
]);
