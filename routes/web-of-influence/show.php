<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/webOfInfluence.php';

requireMethod('GET');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);
$id = requiredIntFromRequest('id');

jsonResponse([
    'status' => 'Success',
    'message' => 'Web of Influence record retrieved successfully.',
    'data' => assertWoiRecordAccessible($conn, $authUser, $id),
]);
