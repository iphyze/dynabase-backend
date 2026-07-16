<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/prequalifications.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Prequalification record ID');
$existing = assertPrequalificationAccessible($conn, $authUser, $id);
$record = normalizePrequalificationPayload($conn, $authUser, $payload);
assertNoDuplicatePrequalification($conn, $record, $id);

$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

$stmt = dbExecute(
    $conn,
    'UPDATE prequalification_table
     SET clients_id = ?, clients_name = ?, clients_address = ?, clients_email = ?, clients_phone = ?,
         clients_website = ?, keyperson_id = ?, key_person = ?, key_persons_tel = ?, title = ?,
         business_info = ?, prospective_project = ?, budget = ?, services = ?, remarks = ?,
         owner_pms_admin_id = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND record_status = \'active\'',
    'isssssissssssssisii',
    [
        $record['clients_id'],
        $record['clients_name'],
        $record['clients_address'],
        $record['clients_email'],
        $record['clients_phone'],
        $record['clients_website'],
        $record['keyperson_id'],
        $record['key_person'],
        $record['key_persons_tel'],
        $record['title'],
        $record['business_info'],
        $record['prospective_project'],
        $record['budget'],
        $record['services'],
        $record['remarks'],
        $record['owner_pms_admin_id'],
        $actorEmail,
        $actorId,
        $id,
    ]
);
$stmt->close();

writeAuditLog($conn, $authUser, 'prequalifications.updated', 'prequalification', $id, [
    'previous_client_id' => (int) $existing['clients_id'] ?: null,
    'client_id' => $record['clients_id'] ?: null,
    'previous_company_name' => $existing['clients_name'],
    'company_name' => $record['clients_name'],
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Prequalification checklist updated successfully.',
    'data' => assertPrequalificationAccessible($conn, $authUser, $id),
]);
