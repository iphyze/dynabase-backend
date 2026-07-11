<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');

$actor = authenticateUser();
$data = readJsonBody();
$userId = (int) ($data['user_id'] ?? 0);

if ($userId <= 0) {
    throw new RuntimeException('Please select a valid user.', 422);
}

$stmt = $conn->prepare(
    'SELECT id, first_name, last_name, email, role, status, parent_pms_admin_id, invited_by, created_by, updated_by, last_login_at, created_at
     FROM users
     WHERE id = ?
     LIMIT 1'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$target = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$target) {
    throw new RuntimeException('User not found.', 404);
}

$target = normalizeUserRow($target);
if (!canDeactivateUser($actor, $target)) {
    throw new RuntimeException('You are not allowed to deactivate this user.', 403);
}

$updateStmt = $conn->prepare('UPDATE users SET status = "deactivated", updated_by = ? WHERE id = ?');
$actorId = (int) $actor['id'];
$updateStmt->bind_param('ii', $actorId, $userId);
$updateStmt->execute();
$updateStmt->close();

revokeAllUserSessions($conn, $userId);
writeAuditLog($conn, $actor, 'users.deactivate', 'user', $userId, [
    'target_email' => $target['email'],
    'target_role' => $target['role'],
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'User deactivated successfully.'
]);
