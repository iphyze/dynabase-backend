<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/prequalifications.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);
$payload = readJsonBody();
$record = normalizePrequalificationPayload($conn, $authUser, $payload);
assertNoDuplicatePrequalification($conn, $record);

$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

$stmt = dbExecute(
    $conn,
    'INSERT INTO prequalification_table
        (clients_id, clients_name, clients_address, clients_email, clients_phone, clients_website,
         keyperson_id, key_person, key_persons_tel, title, business_info, prospective_project,
         budget, services, remarks, owner_pms_admin_id, record_status,
         created_by, created_by_id, updated_by, updated_by_id, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?, ?, ?, CURRENT_TIMESTAMP)',
    'isssssissssssssisisi',
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
        $actorEmail,
        $actorId,
    ]
);
$id = (int) $stmt->insert_id;
$stmt->close();

writeAuditLog($conn, $authUser, 'prequalifications.created', 'prequalification', $id, [
    'client_id' => $record['clients_id'] ?: null,
    'company_name' => $record['clients_name'],
    'keyperson_id' => $record['keyperson_id'],
    'prospective_project' => $record['prospective_project'],
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Prequalification checklist created successfully.',
    'data' => assertPrequalificationAccessible($conn, $authUser, $id),
], 201);
