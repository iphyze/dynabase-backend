<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Keyperson ID');
$keyperson = assertKeypersonAccessible($conn, $authUser, $id, true);

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
    "UPDATE keypersons_table
     SET status = 'deactivated', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ?{$recordScopeSql}",
    $recordTypes,
    $recordParams
)->close();

writeAuditLog($conn, $authUser, 'keyperson.deactivated', 'keyperson', $id, [
    'key_person' => $keyperson['key_person'] ?? null,
    'client_id' => $keyperson['clients_id'] ?? null,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Keyperson deactivated successfully.',
]);
