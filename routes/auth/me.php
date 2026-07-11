<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/request.php';

requireMethod('GET');

$user = authenticateUser();
unset($user['jti']);

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'data' => userPublicPayload($user)
]);
