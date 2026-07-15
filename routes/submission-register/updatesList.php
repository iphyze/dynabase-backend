<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/submissionRegister.php';

requireMethod('GET');
$authUser = authenticateUser();
ensureSubmissionRegisterSchema($conn);
$submissionId = requiredIntFromRequest('submission_id');

jsonResponse([
    'status' => 'Success',
    'message' => 'Submission progress updates retrieved successfully.',
    'data' => submissionRegisterUpdates($conn, $authUser, $submissionId),
]);
