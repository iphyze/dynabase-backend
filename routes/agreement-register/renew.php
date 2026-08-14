<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/agreements.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$sourceId = requiredIntFromPayload($payload, 'source_id', 'Source agreement ID');
$source = assertAgreementAccessible($conn, $authUser, $sourceId);

if ($source['renewal'] !== 'Yes') {
    throw new RuntimeException('This agreement is not marked for renewal. Update Renewal to Yes first.', 422);
}
if (in_array($source['status'], ['Terminated', 'Archived'], true)) {
    throw new RuntimeException('Terminated or archived agreements cannot be renewed.', 422);
}
if ($source['renewed_to_id'] !== null) {
    throw new RuntimeException('This agreement has already been renewed.', 409);
}

$payload['document_ref_type'] = $source['document_ref_type'];
$payload['owner_pms_admin_id'] = $source['owner_pms_admin_id'];
$record = normalizeAgreementPayload($conn, $authUser, $payload);
$record['linked_document_id'] = $record['linked_document_id'] ?? null;

$year = agreementCurrentYear($conn);
$lockName = acquireAgreementReferenceLock($conn, $record['document_ref_type'], $year);
$createdId = 0;

try {
    $conn->begin_transaction();
    $source = assertAgreementAccessible($conn, $authUser, $sourceId);
    if ($source['renewal'] !== 'Yes') {
        throw new RuntimeException('This agreement is no longer marked for renewal.', 422);
    }
    if (in_array($source['status'], ['Terminated', 'Archived'], true)) {
        throw new RuntimeException('Terminated or archived agreements cannot be renewed.', 422);
    }
    if ($source['renewed_to_id'] !== null) {
        throw new RuntimeException('This agreement has already been renewed.', 409);
    }

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
             reminder_date, reminder_emails, remark, linked_document_id, renewed_from_id,
             owner_pms_admin_id, created_by, created_by_id, updated_by, updated_by_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'ssiisisisssssssssssssssssiiisisi',
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
            $sourceId,
            $record['owner_pms_admin_id'],
            $actorEmail,
            $actorId,
            $actorEmail,
            $actorId,
        ]
    );
    $createdId = (int) $stmt->insert_id;
    $stmt->close();

    $stmt = dbExecute(
        $conn,
        "UPDATE agreement_registers
         SET status = 'Renewed', renewed_to_id = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND record_status = 'active' AND renewed_to_id IS NULL",
        'isii',
        [$createdId, $actorEmail, $actorId, $sourceId]
    );
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('This agreement was renewed by another request. Refresh and try again.', 409);
    }
    $stmt->close();

    writeAuditLog($conn, $authUser, 'agreement_register.renewed', 'agreement_register', $sourceId, [
        'document_ref_no' => $source['document_ref_no'],
        'previous_status' => $source['status'],
        'status' => 'Renewed',
        'renewed_to_id' => $createdId,
        'renewed_to_ref_no' => $reference['reference'],
    ]);
    writeAuditLog($conn, $authUser, 'agreement_register.created_from_renewal', 'agreement_register', $createdId, [
        'document_ref_no' => $reference['reference'],
        'renewed_from_id' => $sourceId,
        'renewed_from_ref_no' => $source['document_ref_no'],
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
    'message' => 'Agreement renewed successfully.',
    'data' => assertAgreementAccessible($conn, $authUser, $createdId),
], 201);
