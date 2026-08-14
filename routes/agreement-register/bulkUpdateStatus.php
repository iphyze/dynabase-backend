<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/agreements.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$ids = $payload['ids'] ?? [];
$status = cleanString($payload['status'] ?? '');

if (!is_array($ids)) {
    throw new RuntimeException('Agreement IDs must be provided as a list.', 422);
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
if ($ids === []) {
    throw new RuntimeException('Select at least one agreement.', 422);
}
if (count($ids) > 100) {
    throw new RuntimeException('A maximum of 100 agreements can be updated at once.', 422);
}
if ($status === '' || !in_array($status, DYNABASE_AGREEMENT_STATUSES, true)) {
    throw new RuntimeException('Choose a valid agreement status.', 422);
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));
$params = $ids;
[$scopeSql, $types, $params] = appendScopedWhere($authUser, 'a', $types, $params);

$accessible = dbFetchAll(
    $conn,
    "SELECT a.id, a.document_ref_no, a.status
     FROM agreement_registers a
     WHERE a.id IN ({$placeholders}) AND a.record_status = 'active'{$scopeSql}",
    $types,
    $params
);

if ($accessible === []) {
    throw new RuntimeException('No selected agreements are available for status update.', 404);
}

$accessibleIds = array_map(static fn (array $row): int => (int) $row['id'], $accessible);
$accessiblePlaceholders = implode(',', array_fill(0, count($accessibleIds), '?'));
$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];
$dateSentSql = $status === 'Sent' ? ', date_sent = COALESCE(date_sent, CURRENT_DATE())' : '';

$conn->begin_transaction();
try {
    $stmt = dbExecute(
        $conn,
        "UPDATE agreement_registers
         SET status = ?{$dateSentSql}, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id IN ({$accessiblePlaceholders}) AND record_status = 'active'",
        'ssi' . str_repeat('i', count($accessibleIds)),
        array_merge([$status, $actorEmail, $actorId], $accessibleIds)
    );
    $stmt->close();

    writeAuditLog($conn, $authUser, 'agreement_register.bulk_status_updated', 'agreement_register', null, [
        'requested_ids' => $ids,
        'updated_ids' => $accessibleIds,
        'updated_count' => count($accessibleIds),
        'status' => $status,
        'previous_statuses' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'document_ref_no' => $row['document_ref_no'] ?? null,
            'status' => $row['status'] ?? null,
        ], $accessible),
    ]);

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

foreach ($accessibleIds as $agreementId) {
    syncAgreementLifecycleStatuses($conn, $authUser, $agreementId);
}

jsonResponse([
    'status' => 'Success',
    'message' => count($accessibleIds) . ' agreement(s) updated successfully.',
    'data' => [
        'updated_ids' => $accessibleIds,
        'updated_count' => count($accessibleIds),
        'status' => $status,
    ],
]);
