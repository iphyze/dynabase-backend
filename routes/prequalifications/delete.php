<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/prequalifications.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Prequalification record ID');
$record = assertPrequalificationAccessible($conn, $authUser, $id);

$stmt = dbExecute(
    $conn,
    "UPDATE prequalification_table
     SET record_status = 'deleted', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND record_status = 'active'",
    'sii',
    [actorEmail($authUser), (int) $authUser['id'], $id]
);
$stmt->close();

writeAuditLog($conn, $authUser, 'prequalifications.deleted', 'prequalification', $id, [
    'client_id' => (int) $record['clients_id'] ?: null,
    'company_name' => $record['clients_name'],
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Prequalification checklist deleted successfully.',
]);
