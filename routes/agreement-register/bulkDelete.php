<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/agreements.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$ids = $payload['ids'] ?? [];

if (!is_array($ids)) {
    throw new RuntimeException('Agreement IDs must be provided as a list.', 422);
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
if ($ids === []) {
    throw new RuntimeException('Select at least one agreement.', 422);
}
if (count($ids) > 100) {
    throw new RuntimeException('A maximum of 100 agreements can be deleted at once.', 422);
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));
$params = $ids;
[$scopeSql, $types, $params] = appendScopedWhere($authUser, 'a', $types, $params);

$accessible = dbFetchAll(
    $conn,
    "SELECT a.id, a.document_ref_no, a.client_company, a.status
     FROM agreement_registers a
     WHERE a.id IN ({$placeholders}) AND a.record_status = 'active'{$scopeSql}",
    $types,
    $params
);

if ($accessible === []) {
    throw new RuntimeException('No selected agreements are available for deletion.', 404);
}

$accessibleIds = array_map(static fn (array $row): int => (int) $row['id'], $accessible);
$accessiblePlaceholders = implode(',', array_fill(0, count($accessibleIds), '?'));
$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

$conn->begin_transaction();
try {
    $stmt = dbExecute(
        $conn,
        "UPDATE agreement_registers
         SET record_status = 'deleted', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id IN ({$accessiblePlaceholders}) AND record_status = 'active'",
        'si' . str_repeat('i', count($accessibleIds)),
        array_merge([$actorEmail, $actorId], $accessibleIds)
    );
    $stmt->close();

    writeAuditLog($conn, $authUser, 'agreement_register.bulk_deleted', 'agreement_register', null, [
        'requested_ids' => $ids,
        'deleted_ids' => $accessibleIds,
        'deleted_count' => count($accessibleIds),
        'references' => array_values(array_filter(array_column($accessible, 'document_ref_no'))),
    ]);

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

jsonResponse([
    'status' => 'Success',
    'message' => count($accessibleIds) . ' agreement(s) deleted successfully.',
    'data' => [
        'deleted_ids' => $accessibleIds,
        'deleted_count' => count($accessibleIds),
    ],
]);
