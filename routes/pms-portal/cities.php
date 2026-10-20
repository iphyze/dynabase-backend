<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';
require_once __DIR__ . '/../../includes/lookup.php';

requireMethod('GET');
pmsPortalCurrentUser($conn);
$country = cleanString($_GET['country'] ?? '');
$table = cityTableForCountry($country);
if ($table === null) {
    throw new RuntimeException('Select Nigeria, Ghana, or Ivory Coast.', 422);
}
$q = mb_substr(cleanString($_GET['q'] ?? ''), 0, 100);
$search = '%' . addcslashes($q, '%_\\') . '%';
$rows = dbFetchAll($conn,
    "SELECT city_name FROM {$table} WHERE city_name LIKE ? ORDER BY city_name LIMIT 100",
    's', [$search]
);
header('Cache-Control: no-store');
jsonResponse(['status' => 'Success', 'data' => array_map(
    static fn (array $row): array => ['value' => $row['city_name'], 'label' => $row['city_name']],
    $rows
)]);
