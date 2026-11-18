<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/request.php';
require_once __DIR__ . '/../../../includes/surveys.php';
require_once __DIR__ . '/../../../includes/lookup.php';

requireMethod('POST');
enforceTrustedOrigin();
$payload = readJsonBody();
$invitation = assertSurveyInvitationAvailable($conn, cleanString($payload['token'] ?? ''));
assertSurveyFormSession($conn, (int) $invitation['id'], cleanString($payload['session_token'] ?? ''));

// Reuse the same city tables as Tender Create without exposing the authenticated lookup.
$country = cleanString($payload['country'] ?? '');
$table = match ($country) {
    'Nigeria' => 'ngn_cities',
    'Ghana' => 'gha_cities',
    'Cote D’Iviore' => 'civ_cities',
    default => null,
};
if ($table === null) throw new RuntimeException('Invalid survey project country.', 422);

$q = cleanString($payload['q'] ?? '');
if ((function_exists('mb_strlen') ? mb_strlen($q) : strlen($q)) > 100) {
    throw new RuntimeException('City search is too long.', 422);
}
$limit = min(max((int) ($payload['limit'] ?? 100), 1), 100);
$where = $q !== '' ? ' WHERE city_name LIKE ?' : '';
$rows = dbFetchAll(
    $conn,
    "SELECT city_name FROM {$table}{$where} ORDER BY city_name ASC LIMIT ?",
    $q !== '' ? 'si' : 'i',
    $q !== '' ? [likeTerm($q), $limit] : [$limit]
);

jsonResponse([
    'status' => 'Success',
    'message' => 'Project cities retrieved.',
    'data' => array_map(static fn(array $row): array => [
        'value' => $row['city_name'], 'label' => $row['city_name'],
    ], $rows),
]);
