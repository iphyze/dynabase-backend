<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/permanentDeletion.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Client ID');
permanentlyDeleteDirectoryRecords($conn, $authUser, 'client', [$id]);
jsonResponse(['status' => 'Success', 'message' => 'Client permanently deleted.']);
