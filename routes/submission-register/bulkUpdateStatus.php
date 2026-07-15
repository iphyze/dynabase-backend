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
$status = normalizeSubmissionOption(
    cleanString($payload['status'] ?? ''),
    submissionRegisterStatuses(),
    'submission status'
);
$records = submissionRegisterBulkRecords($conn, $authUser, $ids);

if ($records === []) {
    throw new RuntimeException('No selected submission records are available for status update.', 404);
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
            SET status = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
          WHERE id IN ({$placeholders}) AND record_status = 'active'",
        'ssi' . str_repeat('i', count($accessibleIds)),
        array_merge([$status, $actorEmail, $actorId], $accessibleIds)
    );
    $stmt->close();

    foreach ($records as $record) {
        $previousStatus = (string) ($record['status'] ?? '');
        $auditAction = $previousStatus !== 'Completed' && $status === 'Completed'
            ? 'submission_register.completed'
            : 'submission_register.updated';

        writeAuditLog($conn, $authUser, $auditAction, 'submission_register', (int) $record['id'], [
            'submission_reference' => $record['submission_reference'] ?? null,
            'previous_status' => $previousStatus,
            'new_status' => $status,
            'project_company_name' => $record['project_company_name'] ?? null,
            'client_name' => $record['client_name'] ?? null,
            'owner_pms_admin_id' => $record['owner_pms_admin_id'] ?? null,
            'bulk_operation' => true,
            'bulk_selected_count' => count($accessibleIds),
        ]);
    }

    writeAuditLog($conn, $authUser, 'submission_register.bulk_status_updated', 'submission_register', null, [
        'requested_ids' => $ids,
        'updated_ids' => $accessibleIds,
        'updated_count' => count($accessibleIds),
        'skipped_count' => max(0, count($ids) - count($accessibleIds)),
        'status' => $status,
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
        ? '1 submission record updated successfully.'
        : $count . ' submission records updated successfully.',
    'data' => [
        'requested_ids' => $ids,
        'updated_ids' => $accessibleIds,
        'updated_count' => $count,
        'skipped_count' => max(0, count($ids) - $count),
        'status' => $status,
    ],
]);
