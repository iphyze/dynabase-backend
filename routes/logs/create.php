<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();

$keypersonId = (int) ($payload['keyperson_id'] ?? $payload['key_person_id'] ?? 0);
$clientId = (int) ($payload['clients_id'] ?? $payload['client_id'] ?? 0);
$keyPerson = optionalStringField($payload, 'key_person');
$log = requireStringField($payload, 'log', 'Influence log', 1200);

if ($keypersonId > 0) {
    $selectedKeyperson = assertKeypersonAccessible($conn, $authUser, $keypersonId);
    $clientId = (int) $selectedKeyperson['clients_id'];
    $keyPerson = (string) $selectedKeyperson['key_person'];
}

if ($clientId <= 0) {
    throw new RuntimeException('Please select a client for this influence log.', 422);
}

$client = assertClientAccessible($conn, $authUser, $clientId);
if ($keyPerson === '') {
    throw new RuntimeException('Please select or enter the key person for this influence log.', 422);
}

if ($keypersonId <= 0) {
    $matchedKeyperson = dbFetchOne(
        $conn,
        "SELECT id FROM keypersons_table WHERE clients_id = ? AND key_person = ? LIMIT 1",
        'is',
        [$clientId, $keyPerson]
    );
    if ($matchedKeyperson) {
        assertKeypersonAccessible($conn, $authUser, (int) $matchedKeyperson['id']);
    }
}

$ownerPmsAdminId = $client['owner_pms_admin_id'] !== null ? (int) $client['owner_pms_admin_id'] : null;
$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

$duplicate = dbFetchOne(
    $conn,
    "SELECT id FROM log_table
     WHERE clients_id = ? AND key_person = ? AND log = ? AND ((owner_pms_admin_id = ?) OR (owner_pms_admin_id IS NULL AND ? IS NULL))
     LIMIT 1",
    'issii',
    [$clientId, $keyPerson, $log, $ownerPmsAdminId, $ownerPmsAdminId]
);

if ($duplicate) {
    throw new RuntimeException('This influence log already exists for the selected client/key person.', 409);
}

$stmt = dbExecute(
    $conn,
    'INSERT INTO log_table
        (clients_id, clients_name, key_person, clients_hq_location, clients_category, log,
         created_by, created_by_id, updated_by, updated_by_id, owner_pms_admin_id)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    'issssssisii',
    [
        $clientId,
        $client['clients_name'],
        $keyPerson,
        $client['clients_hq_location'],
        $client['clients_category'],
        $log,
        $actorEmail,
        $actorId,
        $actorEmail,
        $actorId,
        $ownerPmsAdminId,
    ]
);
$logId = $stmt->insert_id;
$stmt->close();

writeAuditLog($conn, $authUser, 'influence_log.created', 'influence_log', $logId, [
    'client_id' => $clientId,
    'keyperson_id' => $keypersonId > 0 ? $keypersonId : null,
    'key_person' => $keyPerson,
]);

$createdLog = assertLogAccessible($conn, $authUser, (int) $logId);

jsonResponse([
    'status' => 'Success',
    'message' => 'Influence log created successfully.',
    'data' => $createdLog,
], 201);
