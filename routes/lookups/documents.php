<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/lookup.php';
require_once __DIR__ . '/../../includes/documents.php';

requireMethod('GET');
$authUser = authenticateUser();

$q = lookupSearchTerm();
$documentType = cleanString($_GET['document_type'] ?? $_GET['type'] ?? '');
$category = cleanString($_GET['category'] ?? '');
$limit = lookupLimit(20, 50);
$offset = lookupOffset();

$where = " WHERE status = 'active'";
$types = '';
$params = [];

if ($q !== '') {
    $where .= ' AND (document_title LIKE ? OR presentation_code LIKE ? OR document_category LIKE ? OR document_type LIKE ? OR original_name LIKE ?)';
    $like = likeTerm($q);
    $types .= 'sssss';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($documentType !== '') {
    $documentType = normaliseDocumentType($conn, $documentType);
    $where .= ' AND document_type = ?';
    $types .= 's';
    $params[] = $documentType;
}
if ($category !== '') {
    $where .= ' AND document_category = ?';
    $types .= 's';
    $params[] = $category;
}

$total = dbScalarInt($conn, "SELECT COUNT(*) AS total FROM document_table{$where}", $types, $params);
$rows = dbFetchAll(
    $conn,
    "SELECT id, document_title, document_type, presentation_code, document_category, original_name, file_extension
     FROM document_table{$where}
     ORDER BY document_title ASC
     LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

$data = array_map(static function (array $row): array {
    $code = trim((string) $row['presentation_code']);
    $label = $code !== '' && $code !== 'N/A' ? $row['document_title'] . ' — ' . $code : (string) $row['document_title'];
    return optionRow((int) $row['id'], $label, [
        'id' => (int) $row['id'],
        'document_title' => $row['document_title'],
        'document_type' => $row['document_type'],
        'presentation_code' => $code !== 'N/A' ? $code : '',
        'document_category' => $row['document_category'],
        'original_name' => $row['original_name'],
        'file_extension' => $row['file_extension'],
    ]);
}, $rows);

lookupResponse('Document lookup retrieved successfully.', $data, $limit, $offset, $total);
