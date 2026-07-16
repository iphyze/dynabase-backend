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
$id = requiredIntFromPayload($payload, 'id', 'Keyperson ID');
$existingKeyperson = assertKeypersonAccessible($conn, $authUser, $id, true);

$clientId = (int) ($payload['clients_id'] ?? $payload['client_id'] ?? $existingKeyperson['clients_id']);
$client = assertClientAccessible($conn, $authUser, $clientId, true);
$ownerPmsAdminId = $client['owner_pms_admin_id'] !== null ? (int) $client['owner_pms_admin_id'] : null;

$keyPerson = requireStringField($payload, 'key_person', 'Keyperson name');
$keyPersonTel = optionalStringField($payload, 'key_persons_tel');
$keyPersonEmail = optionalEmailField($payload, 'key_persons_email', 'Keyperson email');
$keyPersonAddress = composeAddressWithLocation(
    optionalStringField($payload, 'key_persons_address'),
    optionalStringField($payload, 'key_persons_city'),
    optionalStringField($payload, 'key_persons_country')
);
$giftStatus = (string) ($existingKeyperson['gift_status'] ?? 'No'); // Kept only for legacy compatibility.
$giftType = (string) ($existingKeyperson['gift_type'] ?? 'N/A');
$title = optionalStringField($payload, 'title');
$info = optionalStringField($payload, 'info', 1000);
$status = optionalStringField($payload, 'status') ?: (string) ($existingKeyperson['status'] ?? 'active');

if (!in_array($status, ['active', 'inactive', 'deactivated'], true)) {
    throw new RuntimeException('Invalid keyperson status.', 422);
}

[$dupOwnerSql, $dupOwnerTypes, $dupOwnerParams] = ownerDuplicateSql($ownerPmsAdminId, 'k');
$duplicate = dbFetchOne(
    $conn,
    "SELECT id FROM keypersons_table k
     WHERE k.id <> ? AND k.clients_id = ? AND LOWER(TRIM(k.key_person)) = LOWER(TRIM(?)) AND k.status <> 'deactivated'{$dupOwnerSql}
     LIMIT 1",
    'iis' . $dupOwnerTypes,
    array_merge([$id, $clientId, $keyPerson], $dupOwnerParams)
);

if ($duplicate) {
    throw new RuntimeException('Another keyperson with this name already exists under the selected client.', 409);
}

$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

[$recordScopeSql, $recordTypes, $recordParams] = appendScopedWhere(
    $authUser,
    '',
    'sisssssssssssssiisi',
    [
        $client['clients_name'],
        $clientId,
        $client['clients_email'],
        $client['clients_address'],
        $client['clients_hq_location'],
        $client['clients_category'],
        $keyPerson,
        $keyPersonTel,
        $keyPersonEmail,
        $keyPersonAddress,
        $giftStatus,
        $giftType,
        $title,
        $info,
        $actorEmail,
        $actorId,
        $ownerPmsAdminId,
        $status,
        $id,
    ]
);
dbExecute(
    $conn,
    "UPDATE keypersons_table
     SET clients_name = ?, clients_id = ?, clients_email = ?, clients_address = ?, clients_hq_location = ?, clients_category = ?,
         key_person = ?, key_persons_tel = ?, key_persons_email = ?, key_persons_address = ?, gift_status = ?, gift_type = ?,
         title = ?, info = ?, updated_by = ?, updated_by_id = ?, owner_pms_admin_id = ?, status = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ?{$recordScopeSql}",
    $recordTypes,
    $recordParams
)->close();

if ((string) $existingKeyperson['key_person'] !== $keyPerson) {
    [$logScopeSql, $logTypes, $logParams] = appendScopedWhere(
        $authUser,
        '',
        'ssiis',
        [$keyPerson, $actorEmail, $actorId, $clientId, (string) $existingKeyperson['key_person']]
    );
    dbExecute(
        $conn,
        "UPDATE log_table
         SET key_person = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
         WHERE clients_id = ? AND key_person = ?{$logScopeSql}",
        $logTypes,
        $logParams
    )->close();
}

writeAuditLog($conn, $authUser, 'keyperson.updated', 'keyperson', $id, [
    'key_person' => $keyPerson,
    'client_id' => $clientId,
    'status' => $status,
]);

$keyperson = assertKeypersonAccessible($conn, $authUser, $id, true);

jsonResponse([
    'status' => 'Success',
    'message' => 'Keyperson updated successfully.',
    'data' => $keyperson,
]);
