<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

requireMethod('POST');

$user = authenticateUser();
revokeAuthSession($conn, (int) $user['id'], (string) $user['jti']);
writeAuditLog($conn, $user, 'auth.logout', 'user', (int) $user['id']);
clearAuthCookie();
// Log out the parallel gift-portal session for the same user as well.
$portalToken = (string) ($_COOKIE[pmsPortalCookieName()] ?? '');
if ($portalToken !== '') {
    try {
        $claims = (array) JWT::decode($portalToken, new Key(jwtSecret(), 'HS256'));
        $portalJti = (string) ($claims['jti'] ?? '');
        if ((int) ($claims['sub'] ?? 0) === (int) $user['id']
            && hash_equals('dynabase-pms-portal', (string) ($claims['aud'] ?? ''))
            && hash_equals('gift-list-only', (string) ($claims['scope'] ?? ''))
            && strlen($portalJti) === 64) {
            revokeAuthSession($conn, (int) $user['id'], $portalJti);
        }
    } catch (Throwable $exception) {
        // The main session must still be able to sign out if the portal token expired.
    }
}
pmsPortalClearCookie();
clearCsrfCookie();

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'message' => 'Logged out successfully.'
]);
