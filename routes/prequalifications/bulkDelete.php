<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/prequalifications.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);
$payload = readJsonBody();
$ids = array_values(array_unique(array_filter(array_map('intval', is_array($payload['ids'] ?? null) ? $payload['ids'] : []), static fn (int $id): bool => $id > 0)));

if ($ids === []) {
    throw new RuntimeException('Please select at least one prequalification checklist.', 422);
}
if (count($ids) > 100) {
    throw new RuntimeException('You can delete a maximum of 100 records at once.', 422);
}

$deletedIds = [];
$conn->begin_transaction();
try {
    foreach ($ids as $id) {
        assertPrequalificationAccessible($conn, $authUser, $id);
        $stmt = dbExecute(
            $conn,
            "UPDATE prequalification_table
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

writeAuditLog($conn, $authUser, 'prequalifications.bulk_deleted', 'prequalification', implode(',', $deletedIds), [
    'ids' => $deletedIds,
    'count' => count($deletedIds),
]);

jsonResponse([
    'status' => 'Success',
    'message' => count($deletedIds) === 1
        ? '1 prequalification checklist deleted successfully.'
        : count($deletedIds) . ' prequalification checklists deleted successfully.',
    'data' => ['deleted_ids' => $deletedIds, 'deleted_count' => count($deletedIds)],
]);
