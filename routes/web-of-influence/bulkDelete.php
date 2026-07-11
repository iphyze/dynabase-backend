<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/webOfInfluence.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);
$payload = readJsonBody();
$ids = array_values(array_unique(array_filter(array_map('intval', is_array($payload['ids'] ?? null) ? $payload['ids'] : []), static fn (int $id): bool => $id > 0)));

if ($ids === []) {
    throw new RuntimeException('Please select at least one Web of Influence record.', 422);
}
if (count($ids) > 100) {
    throw new RuntimeException('You can delete a maximum of 100 records at once.', 422);
}

$deletedIds = [];
$conn->begin_transaction();
try {
    foreach ($ids as $id) {
        assertWoiRecordAccessible($conn, $authUser, $id);
        $stmt = dbExecute(
            $conn,
            "UPDATE web_of_influence_table
             SET record_status = 'deleted', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND record_status = 'active'",
            'sii',
            [actorEmail($authUser), (int) $authUser['id'], $id]
        );
        if ($stmt->affected_rows > 0) {
            $deletedIds[] = $id;
        }
        $stmt->close();
    }
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

writeAuditLog($conn, $authUser, 'web_of_influence.bulk_deleted', 'web_of_influence', implode(',', $deletedIds), [
    'ids' => $deletedIds,
    'count' => count($deletedIds),
]);

jsonResponse([
    'status' => 'Success',
    'message' => count($deletedIds) === 1
        ? '1 Web of Influence record deleted successfully.'
        : count($deletedIds) . ' Web of Influence records deleted successfully.',
    'data' => ['deleted_ids' => $deletedIds, 'deleted_count' => count($deletedIds)],
]);
