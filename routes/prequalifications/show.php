<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/prequalifications.php';

requireMethod('GET');
$authUser = authenticateUser();
$id = requiredIntFromRequest('id');

jsonResponse([
    'status' => 'Success',
    'message' => 'Prequalification checklist retrieved successfully.',
    'data' => assertPrequalificationAccessible($conn, $authUser, $id),
]);
