<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/projects.php';

requireMethod('GET');
$authUser = authenticateUser();
$code = requiredIntFromRequest('code');

$project = assertProjectAccessible($conn, $authUser, $code, true);

jsonResponse([
    'status' => 'Success',
    'message' => 'Tender retrieved successfully.',
    'data' => projectResponsePayload($conn, $authUser, $project),
]);
