<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/lookup.php';

requireMethod('GET');
authenticateUser();

$q = lookupSearchTerm();
$sectionType = cleanString($_GET['section_type'] ?? $_GET['type'] ?? '');
$limit = lookupLimit(20, 50);
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
    "SELECT id, section_title, section_type
     FROM tender_document_sections{$where}
     ORDER BY section_title ASC
     LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

$data = array_map(static fn (array $row): array => optionRow((int) $row['id'], (string) $row['section_title'], [
    'id' => (int) $row['id'],
    'section_title' => $row['section_title'],
    'section_type' => $row['section_type'],
]), $rows);

lookupResponse('Tender section lookup retrieved successfully.', $data, $limit, $offset, $total);
