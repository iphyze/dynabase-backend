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

$clientId = (int) ($payload['clients_id'] ?? $payload['client_id'] ?? 0);
if ($clientId <= 0 && cleanString($payload['clients_name'] ?? '') !== '') {
    [$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'c', 's', [cleanString($payload['clients_name'])]);
    $client = dbFetchOne(
        $conn,
        "SELECT c.* FROM clients_table c WHERE c.clients_name = ? AND c.status = 'active'{$scopeSql} LIMIT 1",
        $scopeTypes,
        $scopeParams
    );
} else {
    $client = $clientId > 0 ? assertClientAccessible($conn, $authUser, $clientId) : null;
}

if (!$client) {
    throw new RuntimeException('Please select a valid accessible client.', 422);
}

$clientId = (int) $client['id'];
$ownerPmsAdminId = $client['owner_pms_admin_id'] !== null ? (int) $client['owner_pms_admin_id'] : null;
$keyPerson = requireStringField($payload, 'key_person', 'Keyperson name');
$keyPersonTel = optionalStringField($payload, 'key_persons_tel');
$keyPersonEmail = optionalEmailField($payload, 'key_persons_email', 'Keyperson email');
$keyPersonAddress = composeAddressWithLocation(
    optionalStringField($payload, 'key_persons_address'),
    optionalStringField($payload, 'key_persons_city'),
    optionalStringField($payload, 'key_persons_country')
);
$giftStatus = 'No'; // Legacy compatibility only; annual choices now live in gift_list_items.
$giftType = 'N/A';
$title = optionalStringField($payload, 'title');
$info = optionalStringField($payload, 'info', 1000);

[$dupOwnerSql, $dupOwnerTypes, $dupOwnerParams] = ownerDuplicateSql($ownerPmsAdminId, 'k');
$existing = dbFetchOne(
    $conn,
    "SELECT id FROM keypersons_table k
     WHERE k.clients_id = ? AND LOWER(TRIM(k.key_person)) = LOWER(TRIM(?)) AND k.status <> 'deactivated'{$dupOwnerSql}
     LIMIT 1",
    'is' . $dupOwnerTypes,
    array_merge([$clientId, $keyPerson], $dupOwnerParams)
);

if ($existing) {
    throw new RuntimeException('This keyperson already exists under the selected client.', 409);
}

$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

$stmt = dbExecute(
    $conn,
    'INSERT INTO keypersons_table
        (clients_name, clients_id, clients_email, clients_address, clients_hq_location, clients_category,
         key_person, key_persons_tel, key_persons_email, key_persons_address, gift_status, gift_type, title, info,
         created_by, created_by_id, updated_by, updated_by_id, owner_pms_admin_id, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")',
    'sisssssssssssssisii',
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
        $actorEmail,
        $actorId,
        $ownerPmsAdminId,
    ]
);
$keypersonId = $stmt->insert_id;
$stmt->close();

writeAuditLog($conn, $authUser, 'keyperson.created', 'keyperson', $keypersonId, [
    'key_person' => $keyPerson,
    'client_id' => $clientId,
    'owner_pms_admin_id' => $ownerPmsAdminId,
]);

$keyperson = assertKeypersonAccessible($conn, $authUser, (int) $keypersonId, true);

jsonResponse([
    'status' => 'Success',
    'message' => 'Keyperson created successfully.',
    'data' => $keyperson,
], 201);
