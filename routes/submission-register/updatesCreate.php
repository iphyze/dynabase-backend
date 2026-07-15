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
$record = assertSubmissionRegisterAccessible($conn, $authUser, $submissionId);
$message = requireStringField($payload, 'message', 'Progress update', 5000);
$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

$stmt = dbExecute(
    $conn,
    'INSERT INTO submission_register_updates
        (submission_id, message, created_by, created_by_id, updated_by, updated_by_id)
     VALUES (?, ?, ?, ?, ?, ?)',
    'issisi',
    [$submissionId, $message, $actorEmail, $actorId, $actorEmail, $actorId]
);
$updateId = (int) $stmt->insert_id;
$stmt->close();

writeAuditLog($conn, $authUser, 'submission_register.update_added', 'submission_register', $submissionId, [
    'submission_reference' => $record['submission_reference'],
    'project_company_name' => $record['project_company_name'],
    'update_id' => $updateId,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Submission progress update added successfully.',
    'data' => assertSubmissionRegisterUpdateAccessible($conn, $authUser, $submissionId, $updateId),
], 201);
