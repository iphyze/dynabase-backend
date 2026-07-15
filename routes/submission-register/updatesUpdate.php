<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/submissionRegister.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
ensureSubmissionRegisterSchema($conn);
$payload = readJsonBody();
$submissionId = requiredIntFromPayload($payload, 'submission_id', 'Submission record');
$updateId = requiredIntFromPayload($payload, 'id', 'Progress update');
$record = assertSubmissionRegisterAccessible($conn, $authUser, $submissionId);
assertSubmissionRegisterUpdateAccessible($conn, $authUser, $submissionId, $updateId);
$message = requireStringField($payload, 'message', 'Progress update', 5000);
$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

$stmt = dbExecute(
    $conn,
    'UPDATE submission_register_updates
        SET message = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
      WHERE id = ? AND submission_id = ? AND deleted_at IS NULL',
    'ssiii',
    [$message, $actorEmail, $actorId, $updateId, $submissionId]
);
$stmt->close();

writeAuditLog($conn, $authUser, 'submission_register.update_edited', 'submission_register', $submissionId, [
    'submission_reference' => $record['submission_reference'],
    'project_company_name' => $record['project_company_name'],
    'update_id' => $updateId,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Submission progress update edited successfully.',
    'data' => assertSubmissionRegisterUpdateAccessible($conn, $authUser, $submissionId, $updateId),
]);
