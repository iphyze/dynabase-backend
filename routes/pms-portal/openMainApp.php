<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/audit.php';

use Firebase\JWT\JWT;

requireMethod('POST');
$portalUser = pmsPortalCurrentUser($conn);
$ownerId = (int) ($portalUser['owner_pms_admin_id'] ?? 0);

// Only registered PMS accounts can switch. A unified email-code login can
// carry its verified authentication method into the main-app session; legacy
// portal-only sessions do not have that trusted proof.
$user = dbFetchOne($conn, currentUserSelectSql(), 'i', [(int) $portalUser['id']]);
$role = (string) ($user['role'] ?? '');
$emailCodeVerified = (string) ($portalUser['auth_method'] ?? '') === 'email_code';
$eligibleRole = in_array($role, [DYNABASE_ROLE_PMS_ADMIN, DYNABASE_ROLE_PMS_USER], true)
    || ($role === DYNABASE_ROLE_ADMIN && (int) ($user['is_pms_admin'] ?? 0) === 1);
if (!$user || $ownerId <= 0 || !$eligibleRole || (string) $user['status'] !== 'active'
    || (int) ($user['pms_portal_only'] ?? 0) === 1
    || ((int) ($user['must_change_password'] ?? 0) === 1 && !$emailCodeVerified)) {
    throw new RuntimeException('This account cannot switch directly to the main app. Please use its normal sign-in.', 403);
}

// Check that the account still has an ordinary password-based login provisioned.
$hasPassword = dbFetchOne($conn, 'SELECT id FROM users WHERE id = ? AND password_hash IS NOT NULL AND LENGTH(password_hash) > 0 LIMIT 1',
    'i', [(int) $user['id']]);
if (!$hasPassword && !$emailCodeVerified) {
    throw new RuntimeException('Please complete your main-app account setup before switching.', 403);
}

$userId = (int) $user['id'];
$now = time();
$expiresAt = $now + jwtTtlSeconds();
$jti = bin2hex(random_bytes(32));
$token = JWT::encode([
    'iss' => jwtIssuer(),
    'aud' => jwtAudience(),
    'sub' => (string) $userId,
    'jti' => $jti,
    'amr' => $emailCodeVerified ? 'email_code' : 'password',
    'iat' => $now,
    'nbf' => $now,
    'exp' => $expiresAt,
], jwtSecret(), 'HS256');

createAuthSession($conn, $userId, $jti, $expiresAt);
issueAuthCookie($token, $expiresAt);
issueCsrfCookie();
$user = normalizeUserRow($user);
$user['auth_method'] = $emailCodeVerified ? 'email_code' : 'password';
writeAuditLog($conn, $user, 'auth.pms_portal_to_main_app', 'user', $userId);
header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'message' => 'Main app session started.',
    'data' => userPublicPayload($user) + ['permissions' => userEffectivePermissions($conn, $user)],
]);
