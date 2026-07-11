<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';
require_once __DIR__ . '/../../includes/request.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$id = (int) ($payload['id'] ?? 0);

if ($id <= 0) {
    throw new RuntimeException('A valid notification is required.', 422);
}

$stmt = dbExecute(
    $conn,
    'UPDATE notifications SET deleted_at = NOW() WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
    'ii',
    [$id, (int) $authUser['id']]
);
$deleted = $stmt->affected_rows;
$stmt->close();

jsonResponse([
    'status' => 'Success',
    'message' => $deleted > 0 ? 'Notification removed.' : 'Notification was already removed.',
    'data' => ['id' => $id, 'deleted' => $deleted > 0],
]);
