<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/prequalifications.php';

requireMethod('GET');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);
$id = requiredIntFromRequest('id');

jsonResponse([
    'status' => 'Success',
    'message' => 'Prequalification checklist retrieved successfully.',
    'data' => assertPrequalificationAccessible($conn, $authUser, $id),
]);
