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
$existing = assertSubmissionRegisterAccessible($conn, $authUser, $id);
$record = normalizeSubmissionRegisterPayload($conn, $authUser, $payload);
$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

$stmt = dbExecute(
    $conn,
    'UPDATE submission_registers
        SET project_id = ?, project_code = ?, project_tender_code = ?, project_company_name = ?,
            client_id = ?, client_name = ?, category = ?, date_received = ?, date_submitted = ?,
            mode_of_submission = ?, hard_copy_contact_name = ?, hard_copy_contact_email = ?,
            hard_copy_contact_phone = ?, hard_copy_contact_address = ?, email_recipient = ?,
            status = ?, owner_pms_admin_id = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
      WHERE id = ? AND record_status = \'active\'',
    'iississsssssssssisii',
    [
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
        $id,
    ]
);
$stmt->close();

$auditAction = $existing['status'] !== 'Completed' && $record['status'] === 'Completed'
    ? 'submission_register.completed'
    : 'submission_register.updated';

writeAuditLog($conn, $authUser, $auditAction, 'submission_register', $id, [
    'submission_reference' => $existing['submission_reference'],
    'previous_status' => $existing['status'],
    'new_status' => $record['status'],
    'project_company_name' => $record['project_company_name'],
    'client_name' => $record['client_name'],
    'category' => $record['category'],
    'owner_pms_admin_id' => $record['owner_pms_admin_id'],
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Submission record updated successfully.',
    'data' => submissionRegisterDetailPayload($conn, $authUser, $id),
]);
