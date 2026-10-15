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
$rows = findKeypersonCandidates($conn, $keyPerson, $keyPersonTel, $keyPersonEmail, $excludeId, 5, $clientId);

if ($rows === []) {
    jsonResponse([
        'status' => 'Success',
        'message' => 'No matching Key Person found.',
        'data' => ['match' => null, 'candidates' => []],
    ]);
}

// Every candidate carries its own "add to my contact list" token, so the user can pick
// the right John Doe when several people share a name.
$candidates = array_map(static function (array $row) use ($conn, $authUser, $assignmentPmsAdminId): array {
    $keypersonId = (int) $row['id'];
    $alreadyAssigned = keypersonHasPmsAssignment($conn, $keypersonId, $assignmentPmsAdminId);
    return [
        'match' => keypersonDuplicatePublicPayload($row),
        'match_strength' => $row['match_strength'],
        'already_in_contact_list' => $alreadyAssigned,
        'assignment_token' => $alreadyAssigned ? null : keypersonAssignmentToken($authUser, $keypersonId, $assignmentPmsAdminId),
    ];
}, $rows);

$best = $candidates[0];
jsonResponse([
    'status' => 'Success',
    'message' => $best['match_strength'] === 'strong'
        ? 'This Key Person already exists in Dynabase.'
        : (count($candidates) > 1
            ? sprintf('%d existing Key Persons have similar details. Check the email or phone to pick the right person.', count($candidates))
            : 'A Key Person with similar details may already exist in Dynabase.'),
    'data' => [
        // first candidate kept at the top level for existing callers
        'match' => $best['match'],
        'match_strength' => $best['match_strength'],
        'already_in_contact_list' => $best['already_in_contact_list'],
        'assignment_token' => $best['assignment_token'],
        'candidates' => $candidates,
    ],
]);
