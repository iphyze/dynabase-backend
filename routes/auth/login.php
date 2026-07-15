<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/audit.php';

use Firebase\JWT\JWT;
use Respect\Validation\Validator as v;

requireMethod('POST');

enforceTrustedOrigin();

$data = readJsonBody();
$email = cleanEmail($data['email'] ?? '');
$password = (string) ($data['password'] ?? '');

if (!v::email()->validate($email) || $password === '') {
    throw new RuntimeException('Invalid email or password.', 401);
}

assertLoginNotRateLimited($conn, $email);

$stmt = $conn->prepare(
    'SELECT id, first_name, last_name, email, password_hash, role, is_pms_admin, status, parent_pms_admin_id, invited_by, created_by, updated_by, must_change_password, password_changed_at, temporary_password_set_at, last_login_at, created_at
     FROM users
     WHERE email = ?
     LIMIT 1'
);
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$passwordValid = false;
$legacyPassword = false;
$legacySha1Password = false;

if ($user) {
    $storedHash = (string) ($user['password_hash'] ?? '');
    $passwordValid = $storedHash !== '' && password_verify($password, $storedHash);

    $legacyPassword = preg_match('/^[a-f0-9]{32}$/i', $storedHash) === 1
        && hash_equals(strtolower($storedHash), md5($password));

    $legacySha1Password = preg_match('/^[a-f0-9]{40}$/i', $storedHash) === 1
        && hash_equals(strtolower($storedHash), sha1($password));

    $passwordValid = $passwordValid || $legacyPassword || $legacySha1Password;
}

if (!$user || !$passwordValid) {
    recordLoginAttempt($conn, $email, false);
    throw new RuntimeException('Invalid email or password.', 401);
}

if ((string) $user['status'] !== 'active') {
    recordLoginAttempt($conn, $email, false);
    throw new RuntimeException('Your account is not active. Please contact an administrator.', 403);
}

$userId = (int) $user['id'];

if ($legacyPassword || $legacySha1Password || password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
    $replacementHash = password_hash($password, PASSWORD_DEFAULT);
    $rehashStmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $rehashStmt->bind_param('si', $replacementHash, $userId);
    $rehashStmt->execute();
    $rehashStmt->close();
}

$issuedAt = time();
$expiresAt = $issuedAt + jwtTtlSeconds();
$jti = bin2hex(random_bytes(32));

$payload = [
    'iss' => jwtIssuer(),
    'aud' => jwtAudience(),
    'sub' => (string) $userId,
    'jti' => $jti,
    'iat' => $issuedAt,
    'nbf' => $issuedAt,
    'exp' => $expiresAt,
];

$jwt = JWT::encode($payload, jwtSecret(), 'HS256');

createAuthSession($conn, $userId, $jti, $expiresAt);
recordLoginAttempt($conn, $email, true);
issueAuthCookie($jwt, $expiresAt);
$csrfToken = issueCsrfCookie(true);

$updateStmt = $conn->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
$updateStmt->bind_param('i', $userId);
$updateStmt->execute();
$updateStmt->close();

$user = normalizeUserRow($user);
writeAuditLog($conn, $user, 'auth.login', 'user', $userId);

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'message' => $user['must_change_password'] ? 'Login successful. Please create a new password to continue.' : 'Login successful.',
    'requiresPasswordChange' => (bool) $user['must_change_password'],
    'csrfToken' => $csrfToken,
    'data' => userPublicPayload($user) + ['permissions' => userEffectivePermissions($conn, $user)]
]);
