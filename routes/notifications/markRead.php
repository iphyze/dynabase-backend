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
$isRead = !array_key_exists('is_read', $payload) || filter_var($payload['is_read'], FILTER_VALIDATE_BOOL);

if ($id <= 0) {
    throw new RuntimeException('A valid notification is required.', 422);
}

$stmt = dbExecute(
    $conn,
    $isRead
        ? 'UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = ? AND user_id = ? AND deleted_at IS NULL'
        : 'UPDATE notifications SET read_at = NULL WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
    'ii',
    [$id, (int) $authUser['id']]
);
$updated = $stmt->affected_rows;
$stmt->close();

jsonResponse([
    'status' => 'Success',
    'message' => $isRead ? 'Notification marked as read.' : 'Notification marked as unread.',
    'data' => ['id' => $id, 'is_read' => $isRead, 'updated' => $updated > 0],
]);
