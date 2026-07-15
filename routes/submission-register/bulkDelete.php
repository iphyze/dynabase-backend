<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/submissionRegister.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
ensureSubmissionRegisterSchema($conn);
$payload = readJsonBody();
$ids = parseSubmissionRegisterIds($payload);
$records = submissionRegisterBulkRecords($conn, $authUser, $ids);

if ($records === []) {
    throw new RuntimeException('No selected submission records are available for deletion.', 404);
}

$accessibleIds = submissionRegisterBulkIds($records);
$placeholders = implode(',', array_fill(0, count($accessibleIds), '?'));
$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

$conn->begin_transaction();
try {
    $stmt = dbExecute(
        $conn,
        "UPDATE submission_registers
            SET record_status = 'deactivated', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
          WHERE id IN ({$placeholders}) AND record_status = 'active'",
        'si' . str_repeat('i', count($accessibleIds)),
        array_merge([$actorEmail, $actorId], $accessibleIds)
    );
    $stmt->close();

    foreach ($records as $record) {
        writeAuditLog($conn, $authUser, 'submission_register.deleted', 'submission_register', (int) $record['id'], [
            'submission_reference' => $record['submission_reference'] ?? null,
            'project_company_name' => $record['project_company_name'] ?? null,
            'client_name' => $record['client_name'] ?? null,
            'owner_pms_admin_id' => $record['owner_pms_admin_id'] ?? null,
            'bulk_operation' => true,
            'bulk_selected_count' => count($accessibleIds),
        ]);
    }

    writeAuditLog($conn, $authUser, 'submission_register.bulk_deleted', 'submission_register', null, [
        'requested_ids' => $ids,
        'deleted_ids' => $accessibleIds,
        'deleted_count' => count($accessibleIds),
        'skipped_count' => max(0, count($ids) - count($accessibleIds)),
    ]);

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

$count = count($accessibleIds);
jsonResponse([
    'status' => 'Success',
    'message' => $count === 1
        ? '1 submission record deleted successfully.'
        : $count . ' submission records deleted successfully.',
    'data' => [
        'requested_ids' => $ids,
        'deleted_ids' => $accessibleIds,
        'deleted_count' => $count,
        'skipped_count' => max(0, count($ids) - $count),
    ],
]);
