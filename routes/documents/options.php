<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';

requireMethod('GET');
$authUser = authenticateUser();
assertDocumentRevisionSchema($conn);

$categories = dbFetchAll(
    $conn,
    "SELECT section_title AS value, section_title AS label, section_type AS document_type
     FROM tender_document_sections
     ORDER BY section_title ASC"
);
if ($categories === []) {
    $categories = dbFetchAll(
        $conn,
        "SELECT DISTINCT document_category AS value, document_category AS label, document_type
         FROM document_table
         WHERE status = 'active' AND document_category <> ''
         ORDER BY document_category ASC"
    );
}

$projects = dbFetchAll(
    $conn,
    "SELECT code AS value,
            CASE WHEN COALESCE(NULLIF(TRIM(tender_code), ''), '') <> ''
                 THEN CONCAT(tender_code, ' — ', COALESCE(project_title, 'Untitled tender'))
                 ELSE COALESCE(project_title, 'Untitled tender') END AS label,
            project_title, tender_code
     FROM project_info_table
     WHERE record_status = 'active' AND code IS NOT NULL
     ORDER BY created_at DESC, id DESC
     LIMIT 500"
);
$clients = dbFetchAll(
    $conn,
    "SELECT id AS value, clients_name AS label, clients_name
     FROM clients_table
     WHERE status = 'active'
     ORDER BY clients_name ASC
     LIMIT 500"
);
$keypersons = dbFetchAll(
    $conn,
    "SELECT id AS value,
            CASE WHEN COALESCE(NULLIF(TRIM(clients_name), ''), '') <> ''
                 THEN CONCAT(key_person, ' — ', clients_name)
                 ELSE key_person END AS label,
            key_person, clients_name
     FROM keypersons_table
     WHERE status = 'active'
     ORDER BY key_person ASC
     LIMIT 500"
);

jsonResponse([
    'status' => 'Success',
    'message' => 'Document options retrieved successfully.',
    'data' => [
        'document_types' => array_map(static fn (string $value): array => ['value' => $value, 'label' => $value], documentTypes($conn)),
        'relationship_types' => [
            ['value' => 'general', 'label' => 'General library document'],
            ['value' => 'tender', 'label' => 'Tender / project'],
            ['value' => 'client', 'label' => 'Client'],
            ['value' => 'keyperson', 'label' => 'Key person'],
        ],
        'categories' => $categories,
        'projects' => array_map(static fn (array $row): array => [
            'value' => (int) $row['value'],
            'label' => $row['label'],
            'project_title' => $row['project_title'],
            'tender_code' => $row['tender_code'],
        ], $projects),
        'clients' => array_map(static fn (array $row): array => [
            'value' => (int) $row['value'],
            'label' => $row['label'],
            'clients_name' => $row['clients_name'],
        ], $clients),
        'keypersons' => array_map(static fn (array $row): array => [
            'value' => (int) $row['value'],
            'label' => $row['label'],
            'key_person' => $row['key_person'],
            'clients_name' => $row['clients_name'],
        ], $keypersons),
        'allowed_extensions' => array_keys(documentAllowedExtensions()),
        'previewable_extensions' => documentPreviewableExtensions(),
        'archive_extensions' => documentArchiveExtensions(),
        'extension_capabilities' => array_map(
            static fn (string $extension): array => ['extension' => $extension] + documentFileCapabilities($extension),
            array_keys(documentAllowedExtensions())
        ),
        'max_upload_bytes' => documentMaxUploadBytes(),
        'revision_code_example' => 'Rev101',
        'revision_code_max_length' => 50,
    ],
]);
