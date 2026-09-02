<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/keypersons.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();

$clientId = (int) ($payload['clients_id'] ?? $payload['client_id'] ?? 0);
if ($clientId <= 0 && cleanString($payload['clients_name'] ?? '') !== '') {
    $client = dbFetchOne(
        $conn,
        "SELECT * FROM clients_table WHERE LOWER(TRIM(clients_name)) = LOWER(TRIM(?)) AND status = 'active' ORDER BY id ASC LIMIT 1",
        's',
        [cleanString($payload['clients_name'])]
    );
} else {
    $client = $clientId > 0 ? fetchClientRecordById($conn, $clientId) : null;
}

if (!$client) {
    throw new RuntimeException('Please select a valid active client.', 422);
}

$clientId = (int) $client['id'];
$keyPerson = requireStringField($payload, 'key_person', 'Keyperson name');
$keyPersonTel = optionalStringField($payload, 'key_persons_tel');
$keyPersonEmail = optionalEmailField($payload, 'key_persons_email', 'Keyperson email');
$keyPersonAddress = composeAddressWithLocation(
    optionalStringField($payload, 'key_persons_address'),
    optionalStringField($payload, 'key_persons_city'),
    optionalStringField($payload, 'key_persons_country')
);
$giftStatus = 'No';
$giftType = 'N/A';
$title = optionalStringField($payload, 'title');
$info = optionalStringField($payload, 'info', 1000);
$requestedOwnerId = isset($payload['owner_pms_admin_id']) ? (int) $payload['owner_pms_admin_id'] : null;
$assignmentPmsAdminId = resolveKeypersonAssignmentPmsAdminId($conn, $authUser, $requestedOwnerId);

$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];
$normalizedName = normalizeKeypersonName($keyPerson);
$normalizedPhone = normalizeKeypersonPhone($keyPersonTel);
$normalizedEmail = normalizeKeypersonEmail($keyPersonEmail);
$legacyOwnerPmsAdminId = $assignmentPmsAdminId;
$duplicate = null;
$keypersonId = 0;
$mutationLock = acquireKeypersonCanonicalMutationLock($conn);

try {
    // Serialise identity mutations so concurrent creates cannot bypass the
    // application-level canonical duplicate check.
    $duplicate = findStrongKeypersonDuplicate($conn, $keyPerson, $clientId, $keyPersonTel, $keyPersonEmail);

    if (!$duplicate) {
        $conn->begin_transaction();
        try {
            $stmt = dbExecute(
                $conn,
                'INSERT INTO keypersons_table
                    (clients_name, clients_id, clients_email, clients_address, clients_hq_location, clients_category,
                     key_person, normalized_name, key_persons_tel, normalized_phone, key_persons_email, normalized_email,
                     key_persons_address, gift_status, gift_type, title, info,
                     created_by, created_by_id, updated_by, updated_by_id, owner_pms_admin_id, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")',
                'sissssssssssssssssisii',
                [
                    $client['clients_name'],
                    $clientId,
                    $client['clients_email'],
                    $client['clients_address'],
                    $client['clients_hq_location'],
                    $client['clients_category'],
                    $keyPerson,
                    $normalizedName,
                    $keyPersonTel,
                    $normalizedPhone,
                    $keyPersonEmail,
                    $normalizedEmail,
                    $keyPersonAddress,
                    $giftStatus,
                    $giftType,
                    $title,
                    $info,
                    $actorEmail,
                    $actorId,
                    $actorEmail,
                    $actorId,
                    $legacyOwnerPmsAdminId,
                ]
            );
            $keypersonId = (int) $stmt->insert_id;
            $stmt->close();

            ensureKeypersonPmsAssignment($conn, $keypersonId, $assignmentPmsAdminId, $actorId);
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
} finally {
    releaseKeypersonCanonicalMutationLock($conn, $mutationLock);
}

if ($duplicate) {
    $alreadyAssigned = $assignmentPmsAdminId !== null
        ? keypersonHasPmsAssignment($conn, (int) $duplicate['id'], $assignmentPmsAdminId)
        : false;
    $assignmentToken = !$alreadyAssigned && $assignmentPmsAdminId !== null
        ? keypersonAssignmentToken($authUser, (int) $duplicate['id'], $assignmentPmsAdminId)
        : null;

    jsonResponse([
        'status' => 'Duplicate',
        'message' => sprintf('%s with matching details already exists in Dynabase.', (string) $duplicate['key_person']),
        'data' => [
            'duplicate' => keypersonDuplicatePublicPayload($duplicate),
            'already_in_contact_list' => $alreadyAssigned,
            'assignment_token' => $assignmentToken,
        ],
    ], 409);
}

writeAuditLog($conn, $authUser, 'keyperson.created', 'keyperson', $keypersonId, [
    'key_person' => $keyPerson,
    'client_id' => $clientId,
    'owner_pms_admin_id' => $legacyOwnerPmsAdminId,
]);

$keyperson = assertKeypersonAccessible($conn, $authUser, $keypersonId, true);
if (!userHasRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN])) {
    $visibleOwnerPmsAdminId = resolveOwnerPmsAdminId($authUser);
    $keyperson['owner_pms_admin_id'] = $visibleOwnerPmsAdminId !== null && $visibleOwnerPmsAdminId > 0
        ? $visibleOwnerPmsAdminId
        : null;
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Keyperson created successfully.',
    'data' => $keyperson,
], 201);
