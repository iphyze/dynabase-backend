<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/keypersons.php';
require_once __DIR__ . '/../../includes/validation.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();

$clientId = (int) ($payload['clients_id'] ?? $payload['client_id'] ?? 0);
$keyPerson = cleanString($payload['key_person'] ?? '');
$keyPersonTel = optionalStringField($payload, 'key_persons_tel');
$keyPersonEmail = optionalStringField($payload, 'key_persons_email');
$excludeId = isset($payload['exclude_id']) ? (int) $payload['exclude_id'] : null;
$requestedOwnerId = isset($payload['owner_pms_admin_id']) ? (int) $payload['owner_pms_admin_id'] : null;

$assignmentPmsAdminId = resolveKeypersonAssignmentPmsAdminId($conn, $authUser, $requestedOwnerId);
$match = findStrongKeypersonDuplicate($conn, $keyPerson, $clientId, $keyPersonTel, $keyPersonEmail, $excludeId);
$matchStrength = $match ? 'strong' : null;

if (!$match && $keyPerson !== '') {
    $match = findExactKeypersonNameMatch($conn, $keyPerson, $excludeId);
    if ($match) {
        $matchStrength = 'possible';
    }
}

if (!$match) {
    jsonResponse([
        'status' => 'Success',
        'message' => 'No matching Key Person found.',
        'data' => ['match' => null],
    ]);
}

$keypersonId = (int) $match['id'];
$alreadyAssigned = keypersonHasPmsAssignment($conn, $keypersonId, $assignmentPmsAdminId);
$assignmentToken = !$alreadyAssigned
    ? keypersonAssignmentToken($authUser, $keypersonId, $assignmentPmsAdminId)
    : null;

jsonResponse([
    'status' => 'Success',
    'message' => $matchStrength === 'strong'
        ? 'This Key Person already exists in Dynabase.'
        : 'A Key Person with the same name may already exist in Dynabase.',
    'data' => [
        'match' => keypersonDuplicatePublicPayload($match),
        'match_strength' => $matchStrength,
        'already_in_contact_list' => $alreadyAssigned,
        'assignment_token' => $assignmentToken,
    ],
]);
