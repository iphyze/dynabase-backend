<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';
require_once __DIR__ . '/../../includes/request.php';

requireMethod('POST');
$authUser = authenticateUser();

$stmt = dbExecute(
    $conn,
    'UPDATE notifications SET deleted_at = NOW() WHERE user_id = ? AND read_at IS NOT NULL AND deleted_at IS NULL',
    'i',
    [(int) $authUser['id']]
);
$count = $stmt->affected_rows;
$stmt->close();

jsonResponse([
    'status' => 'Success',
    'message' => $count > 0 ? 'Read notifications cleared.' : 'There are no read notifications to clear.',
    'data' => ['deleted' => $count],
]);
