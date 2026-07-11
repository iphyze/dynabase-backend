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
$id = requiredIntFromPayload($payload, 'id', 'Client ID');
$client = assertClientAccessible($conn, $authUser, $id, true);

$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

dbExecute(
    $conn,
    "UPDATE clients_table
     SET status = 'deactivated', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ?",
    'sii',
    [$actorEmail, $actorId, $id]
)->close();

// Soft-hide linked keypersons too, but leave logs for audit/history.
dbExecute(
    $conn,
    "UPDATE keypersons_table
     SET status = 'deactivated', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
     WHERE clients_id = ?",
    'sii',
    [$actorEmail, $actorId, $id]
)->close();

writeAuditLog($conn, $authUser, 'client.deactivated', 'client', $id, [
    'clients_name' => $client['clients_name'] ?? null,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Client deactivated successfully.',
]);
