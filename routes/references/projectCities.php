<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/lookup.php';

requireMethod('GET');
authenticateUser();

$q = lookupSearchTerm();
$limit = lookupLimit(50, 100);
$offset = lookupOffset();

$where = '';
$types = '';
$params = [];

if ($q !== '') {
    $where = ' WHERE city LIKE ? OR code LIKE ?';
    $like = likeTerm($q);
    $types = 'ss';
    $params = [$like, $like];
}

$total = dbScalarInt($conn, "SELECT COUNT(*) AS total FROM project_city{$where}", $types, $params);
$rows = dbFetchAll(
    $conn,
    "SELECT id, city, code FROM project_city{$where} ORDER BY city ASC LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

$data = array_map(static fn (array $row): array => [
    'id' => (int) $row['id'],
    'city' => $row['city'],
    'code' => $row['code'],
    'value' => $row['city'],
    'label' => trim($row['city'] . ' (' . $row['code'] . ')'),
], $rows);

jsonResponse([
    'status' => 'Success',
    'message' => 'Project cities retrieved successfully.',
    'data' => $data,
    'meta' => [
        'limit' => $limit,
        'offset' => $offset,
        'returned' => count($data),
        'total' => $total,
        'has_more' => ($offset + count($data)) < $total,
    ],
]);
