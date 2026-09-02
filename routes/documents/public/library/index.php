<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/documents.php';
require_once __DIR__ . '/../../../../includes/pagination.php';

requireMethod('GET');
assertDocumentRevisionSchema($conn);

$division = cleanString($_GET['division'] ?? '');
$title = cleanString($_GET['title'] ?? '');
$search = cleanString($_GET['search'] ?? $_GET['q'] ?? '');

if (documentStringLength($division) > 180) {
    throw new RuntimeException('Division filter is too long.', 422);
}
if (documentStringLength($title) > 255) {
    throw new RuntimeException('Document title filter is too long.', 422);
}
if (documentStringLength($search) > 180) {
    throw new RuntimeException('Search text is too long.', 422);
}

[$page, $limit, $offset] = paginationParams();
$eligibility = publicDocumentLibraryEligibilitySql('d');

$divisions = dbFetchAll(
    $conn,
    "SELECT d.document_category AS value,
            d.document_category AS label,
            COUNT(*) AS document_count,
            COUNT(DISTINCT d.document_title) AS title_count
     FROM document_table d
     WHERE {$eligibility}
       AND TRIM(d.document_category) <> ''
     GROUP BY d.document_category
     ORDER BY d.document_category ASC"
);

$titles = [];
if ($division !== '') {
    $titles = dbFetchAll(
        $conn,
        "SELECT d.document_title AS value,
                d.document_title AS label,
                COUNT(*) AS document_count,
                GROUP_CONCAT(DISTINCT d.document_type ORDER BY d.document_type SEPARATOR ', ') AS document_types,
                MAX(d.updated_at) AS updated_at
         FROM document_table d
         WHERE {$eligibility}
           AND LOWER(TRIM(d.document_category)) = LOWER(TRIM(?))
         GROUP BY d.document_title
         ORDER BY d.document_title ASC",
        's',
        [$division]
    );
}

$where = " WHERE {$eligibility}";
$types = '';
$params = [];

if ($division !== '') {
    $where .= ' AND LOWER(TRIM(d.document_category)) = LOWER(TRIM(?))';
    $types .= 's';
    $params[] = $division;
}
if ($title !== '') {
    $where .= ' AND LOWER(TRIM(d.document_title)) = LOWER(TRIM(?))';
    $types .= 's';
    $params[] = $title;
}
if ($search !== '') {
    $where .= " AND (
        d.document_title LIKE ?
        OR d.document_category LIKE ?
        OR d.document_type LIKE ?
        OR d.presentation_code LIKE ?
        OR d.description LIKE ?
        OR d.updated_content LIKE ?
        OR d.original_name LIKE ?
        OR EXISTS (
            SELECT 1
            FROM document_revisions sr
            WHERE sr.document_id = d.id
              AND sr.record_status = 'active'
              AND sr.is_current = 1
              AND (
                  sr.original_name LIKE ?
                  OR sr.revision_code LIKE ?
                  OR sr.revision_notes LIKE ?
              )
        )
    )";
    $like = '%' . $search . '%';
    $types .= 'ssssssssss';
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
}

$shouldLoadDocuments = $title !== '' || $search !== '';
$total = 0;
$documents = [];

if ($shouldLoadDocuments) {
    $total = dbScalarInt($conn, "SELECT COUNT(*) AS total FROM document_table d{$where}", $types, $params);

    $rows = dbFetchAll(
        $conn,
        "SELECT d.id,
                d.document_title,
                d.document_type,
                d.presentation_code,
                d.document_category,
                d.updated_content,
                d.description,
                d.original_name,
                d.document,
                d.storage_path,
                d.mime_type,
                d.file_extension,
                d.file_size,
                d.created_at,
                d.updated_at,
                cr.id AS current_revision_id,
                cr.revision_code AS current_revision_code,
                cr.original_name AS public_original_name,
                cr.mime_type AS public_mime_type,
                cr.file_extension AS public_file_extension,
                cr.file_size AS public_file_size,
                cr.storage_path AS public_storage_path,
                cr.stored_name AS public_stored_name
         FROM document_table d
         LEFT JOIN document_revisions cr
           ON cr.document_id = d.id
          AND cr.record_status = 'active'
          AND cr.is_current = 1
         {$where}
         ORDER BY d.document_category ASC, d.document_title ASC, d.updated_at DESC, d.id DESC
         LIMIT ? OFFSET ?",
        $types . 'ii',
        array_merge($params, [$limit, $offset])
    );

    $documents = array_map(static function (array $row): array {
        $source = publicDocumentLibraryFileSource($row);
        $row['public_file_available'] = resolveDocumentAbsolutePath($source) !== null;
        return publicDocumentLibraryPayload($row);
    }, $rows);
}

$summary = dbFetchOne(
    $conn,
    "SELECT COUNT(*) AS total_documents,
            COUNT(DISTINCT d.document_category) AS total_divisions,
            COUNT(DISTINCT CONCAT(LOWER(TRIM(d.document_category)), '\\n', LOWER(TRIM(d.document_title)))) AS total_titles
     FROM document_table d
     WHERE {$eligibility}"
) ?? [];

jsonResponse([
    'status' => 'Success',
    'message' => 'Public document library retrieved successfully.',
    'data' => [
        'summary' => [
            'total_documents' => (int) ($summary['total_documents'] ?? 0),
            'total_divisions' => (int) ($summary['total_divisions'] ?? 0),
            'total_titles' => (int) ($summary['total_titles'] ?? 0),
        ],
        'divisions' => array_map(static fn (array $row): array => [
            'value' => (string) $row['value'],
            'label' => (string) $row['label'],
            'document_count' => (int) $row['document_count'],
            'title_count' => (int) $row['title_count'],
        ], $divisions),
        'selected_division' => $division !== '' ? $division : null,
        'titles' => array_map(static fn (array $row): array => [
            'value' => (string) $row['value'],
            'label' => (string) $row['label'],
            'document_count' => (int) $row['document_count'],
            'document_types' => array_values(array_filter(array_map('trim', explode(',', (string) ($row['document_types'] ?? ''))))),
            'updated_at' => $row['updated_at'] ?? null,
        ], $titles),
        'selected_title' => $title !== '' ? $title : null,
        'search' => $search,
        'documents' => $documents,
        'pagination' => $shouldLoadDocuments
            ? paginationMeta($page, $limit, $total)
            : paginationMeta(1, $limit, 0),
    ],
]);
