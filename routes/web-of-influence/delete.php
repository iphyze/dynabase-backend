<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/webOfInfluence.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Web of Influence record ID');
$record = assertWoiRecordAccessible($conn, $authUser, $id);

$stmt = dbExecute(
    $conn,
    "UPDATE web_of_influence_table
     SET record_status = 'deleted', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND record_status = 'active'",
    'sii',
    [actorEmail($authUser), (int) $authUser['id'], $id]
);
$stmt->close();

writeAuditLog($conn, $authUser, 'web_of_influence.deleted', 'web_of_influence', $id, [
    'client_id' => (int) $record['client_id'],
    'project_id' => (int) $record['project_id'],
    'stakeholder_name' => $record['stakeholder_name'],
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Web of Influence record deleted successfully.',
]);
