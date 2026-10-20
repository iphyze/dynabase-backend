<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/audit.php';

use Firebase\JWT\JWT;

requireMethod('POST');
validateCsrfToken();
$data = readJsonBody();
$email = cleanEmail($data['email'] ?? '');
$code = trim((string) ($data['code'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || preg_match('/^[0-9]{6}$/D', $code) !== 1) {
    throw new RuntimeException('Invalid or expired verification code.', 401);
}

pmsPortalRateLimit($conn, 'verify', 30);
$user = pmsPortalUserByEmail($conn, $email);
if (!$user || (string) ($user['status'] ?? '') !== 'active') {
    throw new RuntimeException('Invalid or expired verification code.', 401);
}
$userId = (int) $user['id'];
$portalOnly = (int) ($user['pms_portal_only'] ?? 0) === 1;
$role = (string) $user['role'];
$autoPortal = $portalOnly || in_array($role, [DYNABASE_ROLE_PMS_ADMIN, DYNABASE_ROLE_PMS_USER], true);
$ownerId = $autoPortal ? pmsPortalOwnerId($conn, $user) : null;
if ($autoPortal && $ownerId === null) {
    throw new RuntimeException('PMS ownership is not configured for this account. Contact an administrator.', 403);
}

$now = time();
$mainJwt = null;
$mainExpires = null;
$portalJwt = null;
$portalExpires = null;
$invalidCode = false;
$conn->begin_transaction();
try {
    $challenge = dbFetchOne($conn,
        'SELECT code_hash, attempts, (expires_at > NOW()) AS unexpired, consumed_at
         FROM pms_portal_login_codes WHERE user_id = ? LIMIT 1 FOR UPDATE', 'i', [$userId]);
    $valid = $challenge && $challenge['consumed_at'] === null
        && (int) $challenge['attempts'] < 5
        && (int) $challenge['unexpired'] === 1
        && hash_equals((string) $challenge['code_hash'], pmsPortalCodeHash($userId, $code));
    if (!$valid) {
        if ($challenge && $challenge['consumed_at'] === null && (int) $challenge['attempts'] < 5) {
            dbExecute($conn, 'UPDATE pms_portal_login_codes SET attempts = attempts + 1 WHERE user_id = ?', 'i', [$userId])->close();
        }
        $invalidCode = true;
    } else {
        dbExecute($conn, 'UPDATE pms_portal_login_codes SET consumed_at = NOW() WHERE user_id = ?', 'i', [$userId])->close();
        if (!$portalOnly) {
            $mainExpires = $now + jwtTtlSeconds();
            $jti = bin2hex(random_bytes(32));
            $mainJwt = JWT::encode([
                'iss' => jwtIssuer(), 'aud' => jwtAudience(), 'sub' => (string) $userId,
                'jti' => $jti, 'iat' => $now, 'nbf' => $now, 'exp' => $mainExpires,
            ], jwtSecret(), 'HS256');
            createAuthSession($conn, $userId, $jti, $mainExpires);
        }
        if ($autoPortal && !((int) ($user['must_change_password'] ?? 0) === 1 && !$portalOnly)) {
            $portalExpires = $now + pmsPortalSessionSeconds();
            $jti = bin2hex(random_bytes(32));
            $portalJwt = JWT::encode([
                'iss' => jwtIssuer(), 'aud' => 'dynabase-pms-portal', 'scope' => 'gift-list-only',
                'sub' => (string) $userId, 'jti' => $jti, 'iat' => $now,
                'nbf' => $now, 'exp' => $portalExpires,
            ], jwtSecret(), 'HS256');
            createAuthSession($conn, $userId, $jti, $portalExpires);
        }
    }
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}
if ($invalidCode) {
    throw new RuntimeException('Invalid or expired verification code.', 401);
}

if ($mainJwt !== null) {
    issueAuthCookie($mainJwt, $mainExpires);
} else {
    clearAuthCookie();
}
if ($portalJwt !== null) {
    setcookie(pmsPortalCookieName(), $portalJwt, pmsPortalCookieOptions($portalExpires));
} else {
    // An ordinary login must not retain a different user's PMS session.
    pmsPortalClearCookie();
}
$csrfToken = issueCsrfCookie(true);
dbExecute($conn, 'UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?', 'i', [$userId])->close();
recordLoginAttempt($conn, $email, true);
header('Cache-Control: no-store');
if ($portalOnly) {
    $user['owner_pms_admin_id'] = $ownerId;
    jsonResponse([
        'status' => 'Success', 'message' => 'Signed in successfully.',
        'auth_scope' => 'portal', 'csrfToken' => $csrfToken,
        'data' => pmsPortalPublicUser($user, $conn),
    ]);
}
$fullUser = dbFetchOne($conn, currentUserSelectSql(), 'i', [$userId]);
$fullUser = normalizeUserRow($fullUser);
writeAuditLog($conn, $fullUser, 'auth.email_code_login', 'user', $userId);
jsonResponse([
    'status' => 'Success', 'message' => 'Signed in successfully.',
    'auth_scope' => 'main', 'csrfToken' => $csrfToken,
    'requiresPasswordChange' => (bool) $fullUser['must_change_password'],
    'data' => userPublicPayload($fullUser) + ['permissions' => userEffectivePermissions($conn, $fullUser)],
]);
