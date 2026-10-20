<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';
require_once __DIR__ . '/../../includes/giftLists.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$user = pmsPortalCurrentUser($conn);
$payload = readJsonBody();
$ownerId = (int) $user['owner_pms_admin_id'];
$year = (int) date('Y');
$actorId = (int) $user['id'];

// Never accept an owner, year or list ID from the browser.
$rows = normalizeGiftListRows($conn, $ownerId, $payload['items'] ?? []);

$conn->begin_transaction();
try {
    // Unique(owner_pms_admin_id, gift_year) and Unique(gift_list_id, keyperson_id)
    // also protect against concurrent duplicate register/recipient creation.
    $listId = ensureGiftList($conn, $year, $ownerId, $actorId);
    replaceGiftListItems($conn, $listId, $rows, $actorId);
    dbExecute($conn,
        'UPDATE gift_lists SET updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
        'ii', [$actorId, $listId]
    )->close();
    writeAuditLog($conn, $user, 'gift_list.portal_saved', 'gift_list', $listId, [
        'gift_year' => $year,
        'owner_pms_admin_id' => $ownerId,
        'recipient_count' => count($rows),
    ]);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'message' => 'Gift list saved successfully.',
    'data' => ['id' => $listId, 'gift_year' => $year, 'owner_pms_admin_id' => $ownerId],
]);
