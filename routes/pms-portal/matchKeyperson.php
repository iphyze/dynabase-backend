<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';
require_once __DIR__ . '/../../includes/keypersons.php';
require_once __DIR__ . '/../../includes/validation.php';

requireMethod('POST');
$user = pmsPortalCurrentUser($conn);
$data = readJsonBody();
$clientId = (int) ($data['client_id'] ?? 0);
$name = cleanString($data['key_person'] ?? '');
$email = optionalStringField($data, 'key_persons_email');
$phone = optionalStringField($data, 'key_persons_tel');

if ($clientId <= 0 || ($name === '' && $email === '' && $phone === '')) {
    throw new RuntimeException('Select a client and provide Key Person details.', 422);
}
$client = dbFetchOne($conn, "SELECT id FROM clients_table WHERE id = ? AND status = 'active' LIMIT 1", 'i', [$clientId]);
if (!$client) {
    throw new RuntimeException('Client is not available.', 422);
}

// Only disclose matching people belonging to the client selected in the portal.
// The normal Dynabase matcher searches across PMS owners; the public portal must not.
$rows = array_values(array_filter(
    findKeypersonCandidates($conn, $name, $phone, $email, null, 20, $clientId),
    static fn(array $row): bool => (int) $row['clients_id'] === $clientId && (string) $row['status'] === 'active'
));
$rows = array_slice($rows, 0, 5);
$candidates = array_map(static function (array $row) use ($conn, $user): array {
    return [
        'match' => keypersonDuplicatePublicPayload($row),
        'match_strength' => (string) $row['match_strength'],
        'already_in_contact_list' => keypersonHasPmsAssignment($conn, (int) $row['id'], (int) $user['owner_pms_admin_id']),
    ];
}, $rows);
$best = $candidates[0] ?? null;
header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'data' => [
        'match' => $best['match'] ?? null,
        'match_strength' => $best['match_strength'] ?? '',
        'already_in_contact_list' => $best['already_in_contact_list'] ?? false,
        'candidates' => $candidates,
    ],
]);
