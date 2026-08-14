<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/agreements.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Agreement record ID');
$existing = assertAgreementAccessible($conn, $authUser, $id);
$record = normalizeAgreementPayload($conn, $authUser, $payload, $existing);
$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];
$reminderEmailsJson = json_encode($record['reminder_emails'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$reminderChanged = $record['reminder_date'] !== $existing['reminder_date']
    || $record['reminder_emails'] !== $existing['reminder_emails'];

$stmt = dbExecute(
    $conn,
    'UPDATE agreement_registers
     SET project_subject = ?, client_id = ?, client_company = ?, counterparty_keyperson_id = ?,
         counterparty_contact_person = ?, issued_by = ?, date_issued = ?, date_sent = ?, date_received = ?,
         lambert_signatory = ?, client_signatory = ?, effective_date = ?, expiry_date = ?, duration = ?,
         renewal = ?, status = ?, purpose = ?, department = ?, reminder_date = ?, reminder_emails = ?,
         remark = ?, linked_document_id = ?, owner_pms_admin_id = ?,
         reminder_sent_at = CASE WHEN ? = 1 THEN NULL ELSE reminder_sent_at END,
         reminder_last_status = CASE WHEN ? = 1 THEN \'pending\' ELSE reminder_last_status END,
         reminder_last_error = CASE WHEN ? = 1 THEN NULL ELSE reminder_last_error END,
         updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND record_status = \'active\'',
    'sisisssssssssssssssssiiiiisii',
    [
        $record['project_subject'],
        $record['client_id'],
        $record['client_company'],
        $record['counterparty_keyperson_id'],
        $record['counterparty_contact_person'],
        $record['issued_by'],
        $record['date_issued'],
        $record['date_sent'],
        $record['date_received'],
        $record['lambert_signatory'],
        $record['client_signatory'],
        $record['effective_date'],
        $record['expiry_date'],
        $record['duration'],
        $record['renewal'],
        $record['status'],
        $record['purpose'],
        $record['department'],
        $record['reminder_date'],
        $reminderEmailsJson,
        $record['remark'],
        $record['linked_document_id'],
        $record['owner_pms_admin_id'],
        $reminderChanged ? 1 : 0,
        $reminderChanged ? 1 : 0,
        $reminderChanged ? 1 : 0,
        $actorEmail,
        $actorId,
        $id,
    ]
);
$stmt->close();

writeAuditLog($conn, $authUser, 'agreement_register.updated', 'agreement_register', $id, [
    'document_ref_no' => $existing['document_ref_no'],
    'previous_status' => $existing['status'],
    'status' => $record['status'],
    'previous_client_company' => $existing['client_company'],
    'client_company' => $record['client_company'],
    'reminder_rescheduled' => $reminderChanged,
]);

syncAgreementLifecycleStatuses($conn, $authUser, $id);

jsonResponse([
    'status' => 'Success',
    'message' => 'Agreement record updated successfully.',
    'data' => assertAgreementAccessible($conn, $authUser, $id),
]);
