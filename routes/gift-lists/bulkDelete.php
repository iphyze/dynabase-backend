<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/giftLists.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$ids = $payload['ids'] ?? [];
if (!is_array($ids)) {
    throw new RuntimeException('Please select at least one gift list.', 422);
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
if ($ids === []) {
    throw new RuntimeException('Please select at least one gift list.', 422);
}

$conn->begin_transaction();
try {
    foreach ($ids as $id) {
        assertGiftListAccessible($conn, $authUser, $id);
        dbExecute($conn, 'DELETE FROM gift_lists WHERE id = ?', 'i', [$id])->close();
    }
    writeAuditLog($conn, $authUser, 'gift_list.bulk_deleted', 'gift_list', implode(',', $ids), ['ids' => $ids]);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

jsonResponse([
    'status' => 'Success',
    'message' => count($ids) . ' gift list(s) deleted successfully.',
    'data' => ['deleted_count' => count($ids)],
]);
