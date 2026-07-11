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
$id = requiredIntFromPayload($payload, 'id', 'Client ID');
$existingClient = assertClientAccessible($conn, $authUser, $id, true);

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
$status = optionalStringField($payload, 'status') ?: (string) ($existingClient['status'] ?? 'active');

if (!in_array($status, ['active', 'inactive', 'deactivated'], true)) {
    throw new RuntimeException('Invalid client status.', 422);
}

$ownerPmsAdminId = isset($existingClient['owner_pms_admin_id']) && $existingClient['owner_pms_admin_id'] !== null
    ? (int) $existingClient['owner_pms_admin_id']
    : null;

if (isGlobalDataUser($authUser) && array_key_exists('owner_pms_admin_id', $payload)) {
    $requestedOwner = $payload['owner_pms_admin_id'] === '' || $payload['owner_pms_admin_id'] === null
        ? null
        : (int) $payload['owner_pms_admin_id'];
    $ownerPmsAdminId = resolveAssignableOwnerPmsAdminId($conn, $authUser, $requestedOwner);
}

[$dupOwnerSql, $dupOwnerTypes, $dupOwnerParams] = ownerDuplicateSql($ownerPmsAdminId, 'c');
$duplicate = dbFetchOne(
    $conn,
    "SELECT id FROM clients_table c WHERE c.id <> ? AND LOWER(TRIM(c.clients_name)) = LOWER(TRIM(?)) AND c.status <> 'deactivated'{$dupOwnerSql} LIMIT 1",
    'is' . $dupOwnerTypes,
    array_merge([$id, $clientsName], $dupOwnerParams)
);

if ($duplicate) {
    throw new RuntimeException('Another client with this name already exists within this PMS ownership scope.', 409);
}

$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

$conn->begin_transaction();
try {
    dbExecute(
        $conn,
        'UPDATE clients_table
         SET clients_name = ?, clients_email = ?, clients_website = ?, clients_address = ?, clients_hq_location = ?,
             clients_category = ?, updated_by = ?, updated_by_id = ?, owner_pms_admin_id = ?, status = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?',
        'sssssssiisi',
        [
            $clientsName,
            $clientsEmail,
            $clientsWebsite,
            $clientsAddress,
            $clientsHqLocation,
            $clientsCategory,
            $actorEmail,
            $actorId,
            $ownerPmsAdminId,
            $status,
            $id,
        ]
    )->close();

    // Keep legacy denormalised PMS/keyperson/log fields in sync with the client owner and identity.
    dbExecute(
        $conn,
        'UPDATE keypersons_table
         SET clients_name = ?, clients_email = ?, clients_address = ?, clients_hq_location = ?, clients_category = ?,
             owner_pms_admin_id = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
         WHERE clients_id = ?',
        'sssssisii',
        [
            $clientsName,
            $clientsEmail,
            $clientsAddress,
            $clientsHqLocation,
            $clientsCategory,
            $ownerPmsAdminId,
            $actorEmail,
            $actorId,
            $id,
        ]
    )->close();

    dbExecute(
        $conn,
        'UPDATE log_table
         SET clients_name = ?, clients_hq_location = ?, clients_category = ?, owner_pms_admin_id = ?,
             updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
         WHERE clients_id = ?',
        'sssisii',
        [$clientsName, $clientsHqLocation, $clientsCategory, $ownerPmsAdminId, $actorEmail, $actorId, $id]
    )->close();

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

writeAuditLog($conn, $authUser, 'client.updated', 'client', $id, [
    'clients_name' => $clientsName,
    'status' => $status,
    'owner_pms_admin_id' => $ownerPmsAdminId,
]);

$client = assertClientAccessible($conn, $authUser, $id, true);

jsonResponse([
    'status' => 'Success',
    'message' => 'Client updated successfully.',
    'data' => $client,
]);
