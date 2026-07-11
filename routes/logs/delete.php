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
$id = requiredIntFromPayload($payload, 'id', 'Influence log ID');
$log = assertLogAccessible($conn, $authUser, $id);

// Legacy log table has no status column, so this endpoint hard-deletes only logs.
dbExecute($conn, 'DELETE FROM log_table WHERE id = ?', 'i', [$id])->close();

writeAuditLog($conn, $authUser, 'influence_log.deleted', 'influence_log', $id, [
    'client_id' => $log['clients_id'] ?? null,
    'key_person' => $log['key_person'] ?? null,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Influence log deleted successfully.',
]);
