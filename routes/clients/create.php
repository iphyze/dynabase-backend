<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();

$clientsName = requireStringField($payload, 'clients_name', 'Client name');
$clientsEmail = optionalEmailField($payload, 'clients_email', 'Client email');
$clientsWebsite = optionalStringField($payload, 'clients_website');
$clientsCity = optionalStringField($payload, 'clients_city');
$clientsCountry = optionalStringField($payload, 'clients_country');
$clientsAddress = composeAddressWithLocation(
    optionalStringField($payload, 'clients_address'),
    $clientsCity,
    $clientsCountry
);
$clientsHqLocation = optionalStringField($payload, 'clients_hq_location') ?: $clientsCity;
$clientsCategory = requireStringField($payload, 'clients_category', 'Client category');
$requestedOwnerPmsAdminId = isset($payload['owner_pms_admin_id']) ? (int) $payload['owner_pms_admin_id'] : null;
$ownerPmsAdminId = resolveClientOwnerPmsAdminId($conn, $authUser, $requestedOwnerPmsAdminId);

// One shared client directory across every PMS Admin.
assertClientNameAvailable($conn, $clientsName);

$actorEmail = actorEmail($authUser);
$createdById = (int) $authUser['id'];

try {
$stmt = dbExecute(
    $conn,
    'INSERT INTO clients_table
        (clients_name, clients_email, clients_website, clients_address, clients_hq_location, clients_category,
         created_by, created_by_id, updated_by, updated_by_id, owner_pms_admin_id, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")',
    'sssssssisii',
    [
        $clientsName,
        $clientsEmail,
        $clientsWebsite,
        $clientsAddress,
        $clientsHqLocation,
        $clientsCategory,
        $actorEmail,
        $createdById,
        $actorEmail,
        $createdById,
        $ownerPmsAdminId,
    ]
);
$clientId = (int) $stmt->insert_id;
$stmt->close();
} catch (mysqli_sql_exception $exception) {
    if (isDuplicateKeyError($exception)) {
        assertClientNameAvailable($conn, $clientsName);   // responds 409 with the record that won the race
    }
    throw $exception;
}

writeAuditLog($conn, $authUser, 'client.created', 'client', $clientId, [
    'clients_name' => $clientsName,
    'owner_pms_admin_id' => $ownerPmsAdminId,
    'created_by_id' => $createdById,
]);

$client = fetchClientRecordById($conn, $clientId, true);

jsonResponse([
    'status' => 'Success',
    'message' => 'Client created successfully.',
    'data' => $client,
], 201);
