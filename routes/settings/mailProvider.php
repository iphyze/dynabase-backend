<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/mailProviderSettings.php';

requireMethod('GET');
$actor = authenticateUser();
requireRole($actor, [DYNABASE_ROLE_SUPER_ADMIN]);

$preferences = dynabaseMailProviderSettings($conn);
$readiness = dynabaseMailProviderReadiness();

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'data' => [
        'primary' => $preferences['primary'],
        'backup' => $preferences['backup'],
        'failover_enabled' => $preferences['failover_enabled'],
        'source' => $preferences['source'],
        'mail_enabled' => envBool('MAIL_ENABLED', false),
        'configured' => $readiness,
    ],
]);
