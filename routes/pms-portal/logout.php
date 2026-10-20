<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';

requireMethod('POST');
$user = pmsPortalCurrentUser($conn);
revokeAuthSession($conn, (int) $user['id'], (string) $user['session_jti']);
pmsPortalClearCookie();
header('Cache-Control: no-store');
jsonResponse(['status' => 'Success', 'message' => 'Logged out successfully.']);
