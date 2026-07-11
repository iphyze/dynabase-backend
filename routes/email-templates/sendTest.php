<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);
$payload = readJsonBody();

$key = cleanString($payload['key'] ?? '');
$email = cleanEmail($payload['email'] ?? '');
if ($key === '') {
    throw new RuntimeException('Email template key is required.', 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('Enter a valid test recipient email address.', 422);
}

$sampleData = dynabaseEmailTemplateSampleData($key);
if ($sampleData === []) {
    throw new RuntimeException('Email template not found.', 404);
}

$result = sendDynabaseTemplateEmail(
    $key,
    $sampleData,
    [['email' => $email, 'name' => 'Dynabase Test Recipient']]
);

if ($result !== true) {
    throw new RuntimeException('The test email could not be sent. ' . $result, 422);
}

writeAuditLog($conn, $authUser, 'email_templates.test_sent', 'email_template', null, [
    'template_key' => $key,
    'recipient_email' => $email,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Test email sent successfully.',
]);
