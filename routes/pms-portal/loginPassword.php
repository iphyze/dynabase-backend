<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';
require_once __DIR__ . '/../../includes/audit.php';

use Firebase\JWT\JWT;

requireMethod('POST');
validateCsrfToken();
$data = readJsonBody();
$email = cleanEmail($data['email'] ?? '');
$password = (string) ($data['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || $password === '') {
    throw new RuntimeException('Invalid email or password.', 401);
}

// Apply the same account/IP password lockout policy as the normal app login.
assertLoginNotRateLimited($conn, $email);

$user = pmsPortalUserByEmail($conn, $email);
$ownerId = $user ? pmsPortalOwnerId($conn, $user) : null;
$allowed = $user && $ownerId !== null
    && (int) ($user['pms_portal_only'] ?? 0) !== 1
    && (int) ($user['must_change_password'] ?? 0) !== 1;

$storedHash = '';
if ($allowed) {
    $credential = dbFetchOne($conn, 'SELECT password_hash FROM users WHERE id = ? LIMIT 1', 'i', [(int) $user['id']]);
    $storedHash = (string) ($credential['password_hash'] ?? '');
}

// Respect the existing Dynabase password formats, including the legacy upgrade path.
$valid = $storedHash !== '' && password_verify($password, $storedHash);
$legacyMd5 = $storedHash !== '' && preg_match('/^[a-f0-9]{32}$/i', $storedHash) === 1
    && hash_equals(strtolower($storedHash), md5($password));
$legacySha1 = $storedHash !== '' && preg_match('/^[a-f0-9]{40}$/i', $storedHash) === 1
    && hash_equals(strtolower($storedHash), sha1($password));

if (!$allowed || (!$valid && !$legacyMd5 && !$legacySha1)) {
    recordLoginAttempt($conn, $email, false);
    throw new RuntimeException('Invalid email or password.', 401);
}

$userId = (int) $user['id'];
if ($legacyMd5 || $legacySha1 || password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
    dbExecute($conn, 'UPDATE users SET password_hash = ? WHERE id = ?', 'si', [password_hash($password, PASSWORD_DEFAULT), $userId])->close();
}

// Password login gives the same gift-list-only cookie as OTP, NOT a main-app session.
$now = time();
$expires = $now + pmsPortalSessionSeconds();
$jti = bin2hex(random_bytes(32));
$jwt = JWT::encode([
    'iss' => jwtIssuer(),
    'aud' => 'dynabase-pms-portal',
    'scope' => 'gift-list-only',
    'sub' => (string) $userId,
    'jti' => $jti,
    'iat' => $now,
    'nbf' => $now,
    'exp' => $expires,
], jwtSecret(), 'HS256');

createAuthSession($conn, $userId, $jti, $expires);
recordLoginAttempt($conn, $email, true);
setcookie(pmsPortalCookieName(), $jwt, pmsPortalCookieOptions($expires));
dbExecute($conn, 'UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?', 'i', [$userId])->close();
writeAuditLog($conn, $user, 'auth.pms_portal_password_login', 'user', $userId);

$user['owner_pms_admin_id'] = $ownerId;
header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'message' => 'Signed in successfully.',
    'data' => pmsPortalPublicUser($user, $conn),
]);
