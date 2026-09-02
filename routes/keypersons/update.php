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
$id = requiredIntFromPayload($payload, 'id', 'Keyperson ID');
$existingKeyperson = assertKeypersonAccessible($conn, $authUser, $id, true);

$clientId = (int) ($payload['clients_id'] ?? $payload['client_id'] ?? $existingKeyperson['clients_id']);
$client = fetchClientRecordById($conn, $clientId, $clientId === (int) $existingKeyperson['clients_id']);

$keyPerson = requireStringField($payload, 'key_person', 'Keyperson name');
$keyPersonTel = optionalStringField($payload, 'key_persons_tel');
$keyPersonEmail = optionalEmailField($payload, 'key_persons_email', 'Keyperson email');
$keyPersonAddress = composeAddressWithLocation(
    optionalStringField($payload, 'key_persons_address'),
    optionalStringField($payload, 'key_persons_city'),
    optionalStringField($payload, 'key_persons_country')
);
$giftStatus = (string) ($existingKeyperson['gift_status'] ?? 'No');
$giftType = (string) ($existingKeyperson['gift_type'] ?? 'N/A');
$title = optionalStringField($payload, 'title');
$info = optionalStringField($payload, 'info', 1000);
$status = optionalStringField($payload, 'status') ?: (string) ($existingKeyperson['status'] ?? 'active');

if (!in_array($status, ['active', 'inactive', 'deactivated'], true)) {
    throw new RuntimeException('Invalid keyperson status.', 422);
}

$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];
$normalizedName = normalizeKeypersonName($keyPerson);
$normalizedPhone = normalizeKeypersonPhone($keyPersonTel);
$normalizedEmail = normalizeKeypersonEmail($keyPersonEmail);
$duplicate = null;
$mutationLock = acquireKeypersonCanonicalMutationLock($conn);

try {
    $duplicate = findStrongKeypersonDuplicate($conn, $keyPerson, $clientId, $keyPersonTel, $keyPersonEmail, $id);

    if (!$duplicate) {
        [$recordScopeSql, $recordTypes, $recordParams] = appendKeypersonScopedWhere(
            $authUser,
            'keypersons_table',
            'sissssssssssssssssisi',
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
                $status,
                $id,
            ]
        );
        dbExecute(
            $conn,
            "UPDATE keypersons_table
             SET clients_name = ?, clients_id = ?, clients_email = ?, clients_address = ?, clients_hq_location = ?, clients_category = ?,
                 key_person = ?, normalized_name = ?, key_persons_tel = ?, normalized_phone = ?, key_persons_email = ?, normalized_email = ?,
                 key_persons_address = ?, gift_status = ?, gift_type = ?, title = ?, info = ?, updated_by = ?, updated_by_id = ?,
                 status = ?, updated_at = CURRENT_TIMESTAMP
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
    }
} finally {
    releaseKeypersonCanonicalMutationLock($conn, $mutationLock);
}

if ($duplicate) {
    jsonResponse([
        'status' => 'Duplicate',
        'message' => sprintf('%s with matching details already exists in Dynabase.', (string) $duplicate['key_person']),
        'data' => [
            'duplicate' => keypersonDuplicatePublicPayload($duplicate),
            'already_in_contact_list' => false,
            'assignment_token' => null,
        ],
    ], 409);
}

writeAuditLog($conn, $authUser, 'keyperson.updated', 'keyperson', $id, [
    'key_person' => $keyPerson,
    'client_id' => $clientId,
    'status' => $status,
]);

$keyperson = assertKeypersonAccessible($conn, $authUser, $id, true);
if (!userHasRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN])) {
    $visibleOwnerPmsAdminId = resolveOwnerPmsAdminId($authUser);
    $keyperson['owner_pms_admin_id'] = $visibleOwnerPmsAdminId !== null && $visibleOwnerPmsAdminId > 0
        ? $visibleOwnerPmsAdminId
        : null;
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Keyperson updated successfully.',
    'data' => $keyperson,
]);
