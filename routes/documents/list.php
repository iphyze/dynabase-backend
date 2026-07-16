<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/pagination.php';

requireMethod('GET');
$authUser = authenticateUser();
assertDocumentRevisionSchema($conn);

[$page, $limit, $offset] = paginationParams();
$search = cleanString($_GET['search'] ?? $_GET['q'] ?? '');
$documentType = cleanString($_GET['document_type'] ?? '');
$category = cleanString($_GET['category'] ?? '');
$relationshipType = cleanString($_GET['relationship_type'] ?? '');
$fileExtension = strtolower(cleanString($_GET['file_extension'] ?? ''));
$sort = cleanString($_GET['sort'] ?? 'updated_at');
$order = strtolower(cleanString($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

$where = " WHERE d.status = 'active'";
$types = '';
$params = [];

if ($search !== '') {
    $where .= ' AND (d.document_title LIKE ? OR d.presentation_code LIKE ? OR d.document_category LIKE ? OR d.updated_content LIKE ? OR d.original_name LIKE ? OR EXISTS (SELECT 1 FROM document_revisions rs WHERE rs.document_id = d.id AND rs.record_status <> \'deleted\' AND (rs.revision_code LIKE ? OR rs.original_name LIKE ? OR rs.revision_notes LIKE ?)))';
    $like = '%' . $search . '%';
    $types .= 'ssssssss';
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
}
if ($documentType !== '' && $documentType !== 'all') {
    $documentType = normaliseDocumentType($documentType);
    $where .= ' AND d.document_type = ?';
    $types .= 's';
    $params[] = $documentType;
}
if ($category !== '' && $category !== 'all') {
    $where .= ' AND d.document_category = ?';
    $types .= 's';
    $params[] = $category;
}
if ($relationshipType !== '' && $relationshipType !== 'all') {
    $relationshipType = normaliseDocumentRelationship($relationshipType);
    $where .= ' AND d.relationship_type = ?';
    $types .= 's';
    $params[] = $relationshipType;
}
if ($fileExtension !== '' && $fileExtension !== 'all') {
    if (!preg_match('/^[a-z0-9]{1,20}$/', $fileExtension)) {
        throw new RuntimeException('Invalid file-type filter.', 422);
    }
    $where .= ' AND d.file_extension = ?';
    $types .= 's';
    $params[] = $fileExtension;
}

$sortMap = [
    'title' => 'd.document_title',
    'document_type' => 'd.document_type',
    'category' => 'd.document_category',
    'relationship' => 'd.relationship_type',
    'file_size' => 'd.file_size',
    'created_at' => 'd.created_at',
    'updated_at' => 'd.updated_at',
];
$orderBy = $sortMap[$sort] ?? $sortMap['updated_at'];

$from = " FROM document_table d
          LEFT JOIN users creator ON creator.id = d.created_by_id
          LEFT JOIN users updater ON updater.id = d.updated_by_id
          LEFT JOIN project_info_table p ON p.code = d.project_code
          LEFT JOIN clients_table c ON c.id = d.client_id
          LEFT JOIN keypersons_table k ON k.id = d.keyperson_id";

$total = dbScalarInt($conn, "SELECT COUNT(*) AS total{$from}{$where}", $types, $params);
$rows = dbFetchAll(
    $conn,
    "SELECT d.*,
            CONCAT_WS(' ', creator.first_name, creator.last_name) AS creator_name,
            creator.email AS creator_email,
            CONCAT_WS(' ', updater.first_name, updater.last_name) AS updater_name,
            updater.email AS updater_email,
            p.project_title AS linked_project_title, p.tender_code AS linked_tender_code,
            c.clients_name AS linked_client_name,
            k.key_person AS linked_keyperson_name, k.clients_name AS linked_keyperson_client,
            (SELECT COUNT(*) FROM document_revisions rc WHERE rc.document_id = d.id AND rc.record_status = 'active') AS revision_count,
            (SELECT cr.id FROM document_revisions cr WHERE cr.document_id = d.id AND cr.record_status = 'active' AND cr.is_current = 1 ORDER BY cr.id DESC LIMIT 1) AS current_revision_id,
            (SELECT cr.revision_code FROM document_revisions cr WHERE cr.document_id = d.id AND cr.record_status = 'active' AND cr.is_current = 1 ORDER BY cr.id DESC LIMIT 1) AS current_revision_code
     {$from}{$where}
     ORDER BY {$orderBy} {$order}, d.id DESC
     LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

$summary = dbFetchOne(
    $conn,
    "SELECT COUNT(*) AS total_documents,
            COUNT(DISTINCT document_category) AS categories,
            COUNT(DISTINCT document_type) AS types,
            COALESCE(SUM(file_size), 0) AS total_bytes,
            SUM(CASE WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recently_updated
     FROM document_table
     WHERE status = 'active'"
) ?? [];

$categories = dbFetchAll(
    $conn,
    "SELECT document_category AS value, COUNT(*) AS document_count
     FROM document_table
     WHERE status = 'active' AND document_category <> ''
     GROUP BY document_category
     ORDER BY document_category ASC"
);
$extensions = dbFetchAll(
    $conn,
    "SELECT file_extension AS value, COUNT(*) AS document_count
     FROM document_table
     WHERE status = 'active' AND file_extension <> ''
     GROUP BY file_extension
     ORDER BY file_extension ASC"
);

jsonResponse([
    'status' => 'Success',
    'message' => 'Documents retrieved successfully.',
    'data' => [
        'items' => array_map('documentResponsePayload', $rows),
        'pagination' => paginationMeta($page, $limit, $total),
        'summary' => [
            'total_documents' => (int) ($summary['total_documents'] ?? 0),
            'categories' => (int) ($summary['categories'] ?? 0),
            'types' => (int) ($summary['types'] ?? 0),
            'total_bytes' => (int) ($summary['total_bytes'] ?? 0),
            'recently_updated' => (int) ($summary['recently_updated'] ?? 0),
        ],
        'available_categories' => array_map(static fn (array $row): array => [
            'value' => $row['value'],
            'label' => $row['value'],
            'document_count' => (int) $row['document_count'],
        ], $categories),
        'available_extensions' => array_map(static fn (array $row): array => [
            'value' => strtolower((string) $row['value']),
            'label' => strtoupper((string) $row['value']),
            'document_count' => (int) $row['document_count'],
        ], $extensions),
        'sorting' => ['sort' => array_key_exists($sort, $sortMap) ? $sort : 'updated_at', 'order' => strtolower($order)],
    ],
]);
