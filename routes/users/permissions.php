<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/permissions.php';

requireMethod('GET');

$actor = authenticateUser();
if (!canViewUserList($actor)) {
    throw new RuntimeException('You are not allowed to view permission options.', 403);
}

jsonResponse([
    'status' => 'Success',
    'data' => permissionPayload($conn, $actor) + [
        'assignable_roles' => allowedManagedRoles($actor),
    ],
]);
