<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/agreementReminders.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Agreement record ID');
$record = assertAgreementAccessible($conn, $authUser, $id);
$result = sendAgreementReminder($conn, $record, 'manual', $authUser);

if ((int) $result['failed'] > 0 && (int) $result['sent'] === 0) {
    throw new RuntimeException('The reminder could not be delivered. ' . ($result['error'] ?? ''), 422);
}

$message = (int) $result['failed'] > 0
    ? 'Reminder sent to some recipients; one or more deliveries failed.'
    : 'Reminder sent successfully.';

jsonResponse([
    'status' => 'Success',
    'message' => $message,
    'data' => [
        'delivery' => $result,
        'agreement' => assertAgreementAccessible($conn, $authUser, $id),
    ],
]);
