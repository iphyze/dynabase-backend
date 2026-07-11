<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');

$actor = authenticateUser();
$actorId = (int) $actor['id'];

$data = readJsonBody();
$currentPassword = (string) ($data['current_password'] ?? $data['currentPassword'] ?? '');
$newPassword = (string) ($data['new_password'] ?? $data['password'] ?? '');
$confirmPassword = (string) ($data['confirm_password'] ?? $data['confirmPassword'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    throw new RuntimeException('Current password, new password and confirmation password are required.', 422);
}

if ($newPassword !== $confirmPassword) {
    throw new RuntimeException('New password and confirmation password do not match.', 422);
}

if (strlen($newPassword) < 12) {
    throw new RuntimeException('New password must be at least 12 characters long.', 422);
}

if (hash_equals($currentPassword, $newPassword)) {
    throw new RuntimeException('New password must be different from your current password.', 422);
}

if (hash_equals($newPassword, 'Lambert@2026')) {
    throw new RuntimeException('Please choose a personal password different from the temporary password.', 422);
}

$stmt = $conn->prepare('SELECT id, password_hash FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $actorId);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$current) {
    throw new RuntimeException('User record not found.', 404);
}

$storedHash = (string) ($current['password_hash'] ?? '');
$currentPasswordValid = $storedHash !== '' && password_verify($currentPassword, $storedHash);
$legacyMd5Password = preg_match('/^[a-f0-9]{32}$/i', $storedHash) === 1
    && hash_equals(strtolower($storedHash), md5($currentPassword));
$legacySha1Password = preg_match('/^[a-f0-9]{40}$/i', $storedHash) === 1
    && hash_equals(strtolower($storedHash), sha1($currentPassword));

if (!$currentPasswordValid && !$legacyMd5Password && !$legacySha1Password) {
    throw new RuntimeException('Current password is incorrect.', 401);
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);

$updateStmt = $conn->prepare(
    'UPDATE users
     SET password_hash = ?, must_change_password = 0, password_changed_at = CURRENT_TIMESTAMP,
         temporary_password_set_at = NULL, updated_by = ?
     WHERE id = ?'
);
$updateStmt->bind_param('sii', $newHash, $actorId, $actorId);
$updateStmt->execute();
$updateStmt->close();

revokeAllUserSessions($conn, $actorId);
clearAuthCookie();
clearCsrfCookie();

writeAuditLog($conn, $actor, 'auth.change_password', 'user', $actorId);

jsonResponse([
    'status' => 'Success',
    'message' => 'Password updated successfully. Please sign in again with your new password.',
    'requiresLogin' => true,
]);
