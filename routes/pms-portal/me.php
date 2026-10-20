<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';

requireMethod('GET');
$user = pmsPortalCurrentUser($conn);
header('Cache-Control: no-store');
jsonResponse(['status' => 'Success', 'data' => pmsPortalPublicUser($user, $conn)]);
