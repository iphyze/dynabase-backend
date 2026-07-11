<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/lookup.php';

requireMethod('GET');
authenticateUser();

$q = lookupSearchTerm();
$sectionType = cleanString($_GET['section_type'] ?? $_GET['type'] ?? '');
$limit = lookupLimit(50, 100);
$offset = lookupOffset();

$where = ' WHERE 1 = 1';
$types = '';
$params = [];

if ($q !== '') {
    $where .= ' AND (section_title LIKE ? OR section_type LIKE ?)';
    $like = likeTerm($q);
    $types .= 'ss';
    array_push($params, $like, $like);
}

if ($sectionType !== '') {
    $where .= ' AND section_type = ?';
    $types .= 's';
    $params[] = $sectionType;
}

$total = dbScalarInt($conn, "SELECT COUNT(*) AS total FROM tender_document_sections{$where}", $types, $params);
$rows = dbFetchAll(
    $conn,
    "SELECT id, section_title, section_type, created_at, updated_at
     FROM tender_document_sections{$where}
     ORDER BY section_type ASC, section_title ASC
     LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

$data = array_map(static fn (array $row): array => [
    'id' => (int) $row['id'],
    'section_title' => $row['section_title'],
    'section_type' => $row['section_type'],
    'value' => $row['section_title'],
    'label' => $row['section_title'],
    'created_at' => $row['created_at'],
    'updated_at' => $row['updated_at'],
], $rows);

jsonResponse([
    'status' => 'Success',
    'message' => 'Tender sections retrieved successfully.',
    'data' => $data,
    'meta' => [
        'limit' => $limit,
        'offset' => $offset,
        'returned' => count($data),
        'total' => $total,
        'has_more' => ($offset + count($data)) < $total,
    ],
]);
