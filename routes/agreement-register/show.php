<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/agreements.php';

requireMethod('GET');
$authUser = authenticateUser();
$id = requiredIntFromRequest('id');
$record = assertAgreementAccessible($conn, $authUser, $id);
$record['renewal_history'] = agreementRenewalHistory($conn, $authUser, $record);

jsonResponse([
    'status' => 'Success',
    'message' => 'Agreement record retrieved successfully.',
    'data' => $record,
]);
