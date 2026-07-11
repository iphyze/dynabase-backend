<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/giftLists.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$giftListId = (int) ($payload['id'] ?? 0);
if ($giftListId <= 0) {
    throw new RuntimeException('Gift-list ID is required.', 422);
}
$list = assertGiftListAccessible($conn, $authUser, $giftListId);

$conn->begin_transaction();
try {
    dbExecute($conn, 'DELETE FROM gift_lists WHERE id = ?', 'i', [$giftListId])->close();
    writeAuditLog($conn, $authUser, 'gift_list.deleted', 'gift_list', $giftListId, [
        'gift_year' => (int) $list['gift_year'],
        'owner_pms_admin_id' => (int) $list['owner_pms_admin_id'],
    ]);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Gift list deleted successfully.',
]);
