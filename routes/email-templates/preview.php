<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/emailTemplates.php';

requireMethod('GET');
$authUser = authenticateUser();

$key = cleanString($_GET['key'] ?? '');
if ($key === '') {
    throw new RuntimeException('Email template key is required.', 422);
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Email template preview generated successfully.',
    'data' => dynabaseRenderEmailTemplate($key),
]);
