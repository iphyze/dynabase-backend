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
$id = requiredIntFromPayload($payload, 'id', 'Influence log ID');
$existingLog = assertLogAccessible($conn, $authUser, $id);

$keypersonId = (int) ($payload['keyperson_id'] ?? $payload['key_person_id'] ?? 0);
$clientId = (int) ($payload['clients_id'] ?? $payload['client_id'] ?? $existingLog['clients_id']);
$keyPerson = optionalStringField($payload, 'key_person') ?: (string) ($existingLog['key_person'] ?? '');
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
     WHERE id <> ? AND clients_id = ? AND key_person = ? AND log = ?
       AND ((owner_pms_admin_id = ?) OR (owner_pms_admin_id IS NULL AND ? IS NULL))
     LIMIT 1",
    'iissii',
    [$id, $clientId, $keyPerson, $log, $ownerPmsAdminId, $ownerPmsAdminId]
);

if ($duplicate) {
    throw new RuntimeException('Another matching influence log already exists for this client/key person.', 409);
}

dbExecute(
    $conn,
    'UPDATE log_table
     SET clients_id = ?, clients_name = ?, key_person = ?, clients_hq_location = ?, clients_category = ?, log = ?,
         updated_by = ?, updated_by_id = ?, owner_pms_admin_id = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ?',
    'issssssiii',
    [
        $clientId,
        $client['clients_name'],
        $keyPerson,
        $client['clients_hq_location'],
        $client['clients_category'],
        $log,
        $actorEmail,
        $actorId,
        $ownerPmsAdminId,
        $id,
    ]
)->close();

writeAuditLog($conn, $authUser, 'influence_log.updated', 'influence_log', $id, [
    'client_id' => $clientId,
    'keyperson_id' => $keypersonId > 0 ? $keypersonId : null,
    'key_person' => $keyPerson,
]);

$updatedLog = assertLogAccessible($conn, $authUser, $id);

jsonResponse([
    'status' => 'Success',
    'message' => 'Influence log updated successfully.',
    'data' => $updatedLog,
]);
