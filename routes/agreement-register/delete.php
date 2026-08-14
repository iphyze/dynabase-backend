<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/agreements.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Agreement record ID');
$record = assertAgreementAccessible($conn, $authUser, $id);

$stmt = dbExecute(
    $conn,
    "UPDATE agreement_registers
     SET record_status = 'deleted', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND record_status = 'active'",
    'sii',
    [actorEmail($authUser), (int) $authUser['id'], $id]
);
$stmt->close();

writeAuditLog($conn, $authUser, 'agreement_register.deleted', 'agreement_register', $id, [
    'document_ref_no' => $record['document_ref_no'],
    'client_company' => $record['client_company'],
    'status' => $record['status'],
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Agreement record deleted successfully.',
]);
