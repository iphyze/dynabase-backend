<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/agreementReminders.php';

requireMethod('GET');
$authUser = authenticateUser();
$id = requiredIntFromRequest('id');
$limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));

jsonResponse([
    'status' => 'Success',
    'message' => 'Reminder delivery history retrieved successfully.',
    'data' => [
        'items' => agreementReminderDeliveryHistory($conn, $authUser, $id, $limit),
    ],
]);
