<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/audit.php';

use Firebase\JWT\JWT;

requireMethod('POST');
$user = authenticateUser(); // Authenticated main-app cookie, CSRF and password-change checks.
$role = (string) ($user['role'] ?? '');
if (!in_array($role, [DYNABASE_ROLE_PMS_ADMIN, DYNABASE_ROLE_PMS_USER], true)
    && !($role === DYNABASE_ROLE_ADMIN && !empty($user['is_pms_admin']))) {
    throw new RuntimeException('This account does not have a PMS team assignment.', 403);
}
$ownerId = pmsPortalOwnerId($conn, $user);
if ($ownerId === null || !empty($user['pms_portal_only'])) {
    throw new RuntimeException('PMS portal access is unavailable for this account.', 403);
}
$userId = (int) $user['id'];
$now = time();
$expires = $now + pmsPortalSessionSeconds();
$jti = bin2hex(random_bytes(32));
$jwt = JWT::encode([
    'iss' => jwtIssuer(), 'aud' => 'dynabase-pms-portal', 'scope' => 'gift-list-only',
    'sub' => (string) $userId, 'jti' => $jti, 'iat' => $now, 'nbf' => $now, 'exp' => $expires,
], jwtSecret(), 'HS256');
createAuthSession($conn, $userId, $jti, $expires);
setcookie(pmsPortalCookieName(), $jwt, pmsPortalCookieOptions($expires));
$user['owner_pms_admin_id'] = $ownerId;
writeAuditLog($conn, $user, 'auth.main_app_to_pms_portal', 'user', $userId);
header('Cache-Control: no-store');
jsonResponse(['status' => 'Success', 'data' => pmsPortalPublicUser($user, $conn)]);
