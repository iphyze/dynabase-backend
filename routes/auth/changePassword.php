<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/audit.php';

use Firebase\JWT\JWT;

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

if (strlen($newPassword) < 10) {
    throw new RuntimeException('New password must be at least 10 characters long.', 422);
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
    throw new RuntimeException('Current password is incorrect. Please check it and try again.', 422);
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
$issuedAt = time();
$expiresAt = $issuedAt + jwtTtlSeconds();
$newJti = bin2hex(random_bytes(32));

// Rotate credentials and replace all existing sessions atomically. The
// password-change session remains signed in, but every old token is revoked.
$conn->begin_transaction();
try {
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
    createAuthSession($conn, $actorId, $newJti, $expiresAt);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

// The previous session (including any PMS portal token) is invalidated.
// Create a fresh HttpOnly main-app session and rotate CSRF as on login.
$newJwt = JWT::encode([
    'iss' => jwtIssuer(),
    'aud' => jwtAudience(),
    'sub' => (string) $actorId,
    'jti' => $newJti,
    'iat' => $issuedAt,
    'nbf' => $issuedAt,
    'exp' => $expiresAt,
], jwtSecret(), 'HS256');
issueAuthCookie($newJwt, $expiresAt);
pmsPortalClearCookie();
$csrfToken = issueCsrfCookie(true);

$refreshedStmt = $conn->prepare(currentUserSelectSql());
$refreshedStmt->bind_param('i', $actorId);
$refreshedStmt->execute();
$refreshedUser = $refreshedStmt->get_result()->fetch_assoc();
$refreshedStmt->close();
if (!$refreshedUser) {
    throw new RuntimeException('Password updated, but your account could not be loaded.', 500);
}
$refreshedUser = normalizeUserRow($refreshedUser);
writeAuditLog($conn, $refreshedUser, 'auth.change_password', 'user', $actorId);

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'message' => 'Password updated successfully.',
    'requiresLogin' => false,
    'requiresPasswordChange' => false,
    'csrfToken' => $csrfToken,
    'data' => userPublicPayload($refreshedUser) + ['permissions' => userEffectivePermissions($conn, $refreshedUser)],
]);
