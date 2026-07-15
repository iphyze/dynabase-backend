<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/submissionRegister.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
ensureSubmissionRegisterSchema($conn);
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Submission record');
$record = assertSubmissionRegisterAccessible($conn, $authUser, $id);
$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

$stmt = dbExecute(
    $conn,
    "UPDATE submission_registers
        SET record_status = 'deactivated', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
      WHERE id = ?",
    'sii',
    [$actorEmail, $actorId, $id]
);
$stmt->close();

writeAuditLog($conn, $authUser, 'submission_register.deleted', 'submission_register', $id, [
    'submission_reference' => $record['submission_reference'],
    'project_company_name' => $record['project_company_name'],
    'client_name' => $record['client_name'],
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Submission record removed successfully.',
]);
