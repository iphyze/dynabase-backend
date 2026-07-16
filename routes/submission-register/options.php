<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/submissionRegister.php';

requireMethod('GET');
authenticateUser();

jsonResponse([
    'status' => 'Success',
    'message' => 'Submission register options retrieved successfully.',
    'data' => [
        'categories' => submissionRegisterCategories(),
        'modes' => submissionRegisterModes(),
        'statuses' => submissionRegisterStatuses(),
    ],
]);
