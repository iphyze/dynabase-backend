<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Client ID');
$client = assertClientAccessible($conn, $authUser, $id, true);

$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

[$recordScopeSql, $recordTypes, $recordParams] = appendScopedWhere(
    $authUser,
    '',
    'sii',
    [$actorEmail, $actorId, $id]
);
dbExecute(
    $conn,
    "UPDATE clients_table
     SET status = 'active', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ?{$recordScopeSql}",
    $recordTypes,
    $recordParams
)->close();

writeAuditLog($conn, $authUser, 'client.activated', 'client', $id, [
    'clients_name' => $client['clients_name'] ?? null,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Client activated successfully.',
]);
