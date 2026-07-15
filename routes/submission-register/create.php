<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/submissionRegister.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
ensureSubmissionRegisterSchema($conn);
$payload = readJsonBody();
$record = normalizeSubmissionRegisterPayload($conn, $authUser, $payload);
$reference = nextSubmissionReference($conn);
$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

$stmt = dbExecute(
    $conn,
    'INSERT INTO submission_registers
        (submission_reference, project_id, project_code, project_tender_code, project_company_name,
         client_id, client_name, category, date_received, date_submitted, mode_of_submission,
         hard_copy_contact_name, hard_copy_contact_email, hard_copy_contact_phone, hard_copy_contact_address,
         email_recipient, status, owner_pms_admin_id, created_by, created_by_id, updated_by, updated_by_id)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    'siississsssssssssisisi',
    [
        $reference,
        $record['project_id'],
        $record['project_code'],
        $record['project_tender_code'],
        $record['project_company_name'],
        $record['client_id'],
        $record['client_name'],
        $record['category'],
        $record['date_received'],
        $record['date_submitted'],
        $record['mode_of_submission'],
        $record['hard_copy_contact_name'],
        $record['hard_copy_contact_email'],
        $record['hard_copy_contact_phone'],
        $record['hard_copy_contact_address'],
        $record['email_recipient'],
        $record['status'],
        $record['owner_pms_admin_id'],
        $actorEmail,
        $actorId,
        $actorEmail,
        $actorId,
    ]
);
$id = (int) $stmt->insert_id;
$stmt->close();

$initialMessage = cleanString($payload['initial_update'] ?? $payload['project_company_info'] ?? $payload['project_info'] ?? '');
if ($initialMessage !== '') {
    $stmt = dbExecute(
        $conn,
        'INSERT INTO submission_register_updates
            (submission_id, message, created_by, created_by_id, updated_by, updated_by_id)
         VALUES (?, ?, ?, ?, ?, ?)',
        'issisi',
        [$id, $initialMessage, $actorEmail, $actorId, $actorEmail, $actorId]
    );
    $stmt->close();
}

writeAuditLog($conn, $authUser, 'submission_register.created', 'submission_register', $id, [
    'submission_reference' => $reference,
    'project_company_name' => $record['project_company_name'],
    'client_name' => $record['client_name'],
    'category' => $record['category'],
    'status' => $record['status'],
    'owner_pms_admin_id' => $record['owner_pms_admin_id'],
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Submission record created successfully.',
    'data' => submissionRegisterDetailPayload($conn, $authUser, $id),
], 201);
