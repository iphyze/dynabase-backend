<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');

$user = authenticateUser();
revokeAuthSession($conn, (int) $user['id'], (string) $user['jti']);
writeAuditLog($conn, $user, 'auth.logout', 'user', (int) $user['id']);
clearAuthCookie();
clearCsrfCookie();

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'message' => 'Logged out successfully.'
]);
