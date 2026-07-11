<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/lookup.php';

requireMethod('GET');
authenticateUser();

$country = cleanString($_GET['country'] ?? '');
$table = cityTableForCountry($country);
if ($table === null) {
    throw new RuntimeException('Please provide a valid country: Nigeria, Ghana, or Ivory Coast.', 422);
}

$q = lookupSearchTerm();
$limit = lookupLimit(50, 100);
$offset = lookupOffset();

$where = '';
$types = '';
$params = [];

if ($q !== '') {
    $where = ' WHERE city_name LIKE ?';
    $types = 's';
    $params[] = likeTerm($q);
}

$total = dbScalarInt($conn, "SELECT COUNT(*) AS total FROM {$table}{$where}", $types, $params);
$rows = dbFetchAll(
    $conn,
    "SELECT id, city_name FROM {$table}{$where} ORDER BY city_name ASC LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

$data = array_map(static fn (array $row): array => [
    'id' => (int) $row['id'],
    'name' => $row['city_name'],
    'country' => normalizeCountryName($country),
    'value' => $row['city_name'],
    'label' => $row['city_name'],
], $rows);

jsonResponse([
    'status' => 'Success',
    'message' => 'Cities retrieved successfully.',
    'data' => $data,
    'meta' => [
        'limit' => $limit,
        'offset' => $offset,
        'returned' => count($data),
        'total' => $total,
        'has_more' => ($offset + count($data)) < $total,
    ],
]);
