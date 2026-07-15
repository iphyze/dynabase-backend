<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/submissionRegister.php';

requireMethod('GET');
$authUser = authenticateUser();
ensureSubmissionRegisterSchema($conn);
$id = requiredIntFromRequest('id');

jsonResponse([
    'status' => 'Success',
    'message' => 'Submission record retrieved successfully.',
    'data' => submissionRegisterDetailPayload($conn, $authUser, $id),
]);
