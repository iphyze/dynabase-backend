<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/giftLists.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$giftYear = validateGiftYear($payload['gift_year'] ?? date('Y'));
$requestedOwnerId = isset($payload['owner_pms_admin_id']) ? (int) $payload['owner_pms_admin_id'] : null;
$ownerPmsAdminId = resolveGiftOwnerPmsAdminId($conn, $authUser, $requestedOwnerId);
$rows = normalizeGiftListRows($conn, $ownerPmsAdminId, $payload['items'] ?? []);

$existing = dbFetchOne(
    $conn,
    'SELECT id FROM gift_lists WHERE gift_year = ? AND owner_pms_admin_id = ? LIMIT 1',
    'ii',
    [$giftYear, $ownerPmsAdminId]
);
if ($existing) {
    throw new RuntimeException('A gift list already exists for this PMS owner and year. Open it to make changes.', 409);
}

$actorId = (int) $authUser['id'];
$conn->begin_transaction();
try {
    $stmt = dbExecute(
        $conn,
        'INSERT INTO gift_lists (gift_year, owner_pms_admin_id, created_by_id, updated_by_id) VALUES (?, ?, ?, ?)',
        'iiii',
        [$giftYear, $ownerPmsAdminId, $actorId, $actorId]
    );
    $giftListId = (int) $stmt->insert_id;
    $stmt->close();
    replaceGiftListItems($conn, $giftListId, $rows, $actorId);

    writeAuditLog($conn, $authUser, 'gift_list.created', 'gift_list', $giftListId, [
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
    'message' => 'Gift list created successfully.',
    'data' => giftListResponsePayload($conn, $list),
], 201);
