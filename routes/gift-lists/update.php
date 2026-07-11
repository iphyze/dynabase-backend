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

$current = assertGiftListAccessible($conn, $authUser, $giftListId);
$giftYear = validateGiftYear($payload['gift_year'] ?? $current['gift_year']);
$requestedOwnerId = isset($payload['owner_pms_admin_id']) ? (int) $payload['owner_pms_admin_id'] : (int) $current['owner_pms_admin_id'];
$ownerPmsAdminId = resolveGiftOwnerPmsAdminId($conn, $authUser, $requestedOwnerId);
$rows = normalizeGiftListRows($conn, $ownerPmsAdminId, $payload['items'] ?? []);

$duplicate = dbFetchOne(
    $conn,
    'SELECT id FROM gift_lists WHERE gift_year = ? AND owner_pms_admin_id = ? AND id <> ? LIMIT 1',
    'iii',
    [$giftYear, $ownerPmsAdminId, $giftListId]
);
if ($duplicate) {
    throw new RuntimeException('Another gift list already exists for this PMS owner and year.', 409);
}

$actorId = (int) $authUser['id'];
$conn->begin_transaction();
try {
    dbExecute(
        $conn,
        'UPDATE gift_lists SET gift_year = ?, owner_pms_admin_id = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
        'iiii',
        [$giftYear, $ownerPmsAdminId, $actorId, $giftListId]
    )->close();
    replaceGiftListItems($conn, $giftListId, $rows, $actorId);

    writeAuditLog($conn, $authUser, 'gift_list.updated', 'gift_list', $giftListId, [
        'gift_year' => $giftYear,
        'owner_pms_admin_id' => $ownerPmsAdminId,
        'recipient_count' => count($rows),
    ]);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

$list = assertGiftListAccessible($conn, $authUser, $giftListId);
jsonResponse([
    'status' => 'Success',
    'message' => 'Gift list updated successfully.',
    'data' => giftListResponsePayload($conn, $list),
]);
