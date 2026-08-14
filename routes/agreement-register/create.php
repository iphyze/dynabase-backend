<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/agreements.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$record = normalizeAgreementPayload($conn, $authUser, $payload);

$year = agreementCurrentYear($conn);
$lockName = acquireAgreementReferenceLock($conn, $record['document_ref_type'], $year);
$createdId = 0;

try {
    $conn->begin_transaction();
    $reference = nextAgreementReference($conn, $record['document_ref_type'], $year);
    $actorEmail = actorEmail($authUser);
    $actorId = (int) $authUser['id'];
    $reminderEmailsJson = json_encode($record['reminder_emails'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $stmt = dbExecute(
        $conn,
        'INSERT INTO agreement_registers
            (document_ref_type, document_ref_no, ref_year, ref_sequence, project_subject,
             client_id, client_company, counterparty_keyperson_id, counterparty_contact_person,
             issued_by, date_issued, date_sent, date_received, lambert_signatory, client_signatory,
             effective_date, expiry_date, duration, renewal, status, purpose, department,
             reminder_date, reminder_emails, remark, linked_document_id, owner_pms_admin_id,
             created_by, created_by_id, updated_by, updated_by_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'ssiisisisssssssssssssssssiisisi',
        [
            $record['document_ref_type'],
            $reference['reference'],
            $reference['year'],
            $reference['sequence'],
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
            $actorEmail,
            $actorId,
            $actorEmail,
            $actorId,
        ]
    );
    $createdId = (int) $stmt->insert_id;
    $stmt->close();

    writeAuditLog($conn, $authUser, 'agreement_register.created', 'agreement_register', $createdId, [
        'document_ref_no' => $reference['reference'],
        'document_ref_type' => $record['document_ref_type'],
        'client_id' => $record['client_id'],
        'client_company' => $record['client_company'],
        'status' => $record['status'],
    ]);

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
} finally {
    releaseAgreementReferenceLock($conn, $lockName);
}

syncAgreementLifecycleStatuses($conn, $authUser, $createdId);

jsonResponse([
    'status' => 'Success',
    'message' => 'Agreement record created successfully.',
    'data' => assertAgreementAccessible($conn, $authUser, $createdId),
], 201);
