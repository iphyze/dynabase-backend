<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/dbHelpers.php';
require_once __DIR__ . '/ownership.php';

const DYNABASE_DOCUMENT_TYPES = ['Profile', 'Presentation', 'Tender'];
const DYNABASE_DOCUMENT_RELATIONSHIPS = ['general', 'tender', 'client', 'keyperson'];

function documentMaxUploadBytes(): int
{
    $configuredMb = (int) envString('DOCUMENT_MAX_UPLOAD_MB', '25');
    return max(1, min($configuredMb, 100)) * 1024 * 1024;
}

function documentAllowedExtensions(): array
{
    return [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'txt' => ['text/plain', 'application/octet-stream'],
        'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/octet-stream'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'rar' => ['application/vnd.rar', 'application/x-rar-compressed', 'application/octet-stream'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];
}

function documentPreviewableExtensions(): array
{
    return ['pdf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'webp'];
}

function normaliseDocumentType(mixed $value): string
{
    $candidate = ucfirst(strtolower(trim((string) $value)));
    if (!in_array($candidate, DYNABASE_DOCUMENT_TYPES, true)) {
        throw new RuntimeException('Please select a valid document type.', 422);
    }
    return $candidate;
}

function normaliseDocumentRelationship(mixed $value): string
{
    $candidate = strtolower(trim((string) $value));
    $candidate = $candidate === '' ? 'general' : $candidate;
    if (!in_array($candidate, DYNABASE_DOCUMENT_RELATIONSHIPS, true)) {
        throw new RuntimeException('Please select a valid document relationship.', 422);
    }
    return $candidate;
}

function documentStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function documentStringSlice(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function assertDocumentCategoryAvailable(mysqli $conn, string $category, string $documentType): void
{
    $reference = dbFetchOne(
        $conn,
        "SELECT id FROM tender_document_sections
         WHERE LOWER(TRIM(section_title)) = LOWER(TRIM(?))
           AND LOWER(TRIM(section_type)) = LOWER(TRIM(?))
         LIMIT 1",
        'ss',
        [$category, $documentType]
    );
    if ($reference) {
        return;
    }

    $legacy = dbFetchOne(
        $conn,
        "SELECT id FROM document_table
         WHERE LOWER(TRIM(document_category)) = LOWER(TRIM(?))
           AND LOWER(TRIM(document_type)) = LOWER(TRIM(?))
         LIMIT 1",
        'ss',
        [$category, $documentType]
    );
    if (!$legacy) {
        throw new RuntimeException('Please select a category that belongs to the chosen document type.', 422);
    }
}

function documentStorageRoot(): string
{
    return dirname(__DIR__) . '/storage';
}

function ensureDocumentStorageDirectory(string $relativeDirectory): string
{
    $root = documentStorageRoot();
    $absolute = $root . '/' . trim($relativeDirectory, '/');
    if (!is_dir($absolute) && !mkdir($absolute, 0750, true) && !is_dir($absolute)) {
        throw new RuntimeException('Unable to prepare secure document storage.', 500);
    }
    return $absolute;
}

function cleanDocumentOriginalName(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?: 'document';
    $clean = trim($name);
    return function_exists('mb_substr') ? mb_substr($clean, 0, 255) : substr($clean, 0, 255);
}

function documentUploadWasProvided(string $field = 'document'): bool
{
    return isset($_FILES[$field])
        && is_array($_FILES[$field])
        && (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

function storeDocumentUpload(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The selected document is larger than the server upload limit.',
            UPLOAD_ERR_PARTIAL => 'The document upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please select a document to upload.',
            default => 'The document could not be uploaded.',
        };
        throw new RuntimeException($message, 422);
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('The selected document is empty.', 422);
    }
    if ($size > documentMaxUploadBytes()) {
        throw new RuntimeException('The selected document exceeds the ' . (int) (documentMaxUploadBytes() / 1024 / 1024) . ' MB upload limit.', 422);
    }

    $originalName = cleanDocumentOriginalName((string) ($file['name'] ?? 'document'));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = documentAllowedExtensions();
    if ($extension === '' || !array_key_exists($extension, $allowed)) {
        throw new RuntimeException('Unsupported file type. Use PDF, Office, text, CSV, ZIP, RAR, JPG, PNG or WEBP files.', 422);
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_file($tmpName)) {
        throw new RuntimeException('The uploaded document is unavailable.', 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = strtolower((string) ($finfo->file($tmpName) ?: 'application/octet-stream'));
    if (!in_array($mimeType, $allowed[$extension], true)) {
        throw new RuntimeException('The uploaded file content does not match its extension.', 422);
    }

    $year = date('Y');
    $month = date('m');
    $relativeDirectory = "documents/{$year}/{$month}";
    $absoluteDirectory = ensureDocumentStorageDirectory($relativeDirectory);
    $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
    $absolutePath = $absoluteDirectory . '/' . $storedName;

    if (!move_uploaded_file($tmpName, $absolutePath)) {
        throw new RuntimeException('Unable to move the document into secure storage.', 500);
    }
    @chmod($absolutePath, 0640);

    return [
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'storage_path' => $relativeDirectory . '/' . $storedName,
        'mime_type' => $mimeType,
        'file_extension' => $extension,
        'file_size' => $size,
        'checksum_sha256' => hash_file('sha256', $absolutePath),
        'absolute_path' => $absolutePath,
    ];
}

function resolveDocumentAbsolutePath(array $document): ?string
{
    $storagePath = trim((string) ($document['storage_path'] ?? ''));
    $storedName = basename((string) ($document['document'] ?? $document['stored_name'] ?? ''));

    if ($storagePath !== '' && !str_contains($storagePath, '..')) {
        if (str_starts_with($storagePath, 'documents/')) {
            $path = documentStorageRoot() . '/' . ltrim($storagePath, '/');
            return is_file($path) ? $path : null;
        }

        if (str_starts_with($storagePath, 'legacy/')) {
            $legacyLocal = documentStorageRoot() . '/documents/legacy/' . $storedName;
            if (is_file($legacyLocal)) {
                return $legacyLocal;
            }
        }
    }

    if ($storedName !== '') {
        $legacyLocal = documentStorageRoot() . '/documents/legacy/' . $storedName;
        if (is_file($legacyLocal)) {
            return $legacyLocal;
        }

        $legacyRoot = rtrim(envString('LEGACY_DOCUMENT_ROOT'), '/\\');
        if ($legacyRoot !== '') {
            $legacyPath = $legacyRoot . DIRECTORY_SEPARATOR . $storedName;
            if (is_file($legacyPath)) {
                return $legacyPath;
            }
        }
    }

    return null;
}

function removeManagedDocumentFile(?string $path): void
{
    if ($path === null || !is_file($path)) {
        return;
    }

    $managedRoot = realpath(documentStorageRoot() . '/documents');
    $realPath = realpath($path);
    if ($managedRoot !== false && $realPath !== false && str_starts_with($realPath, $managedRoot . DIRECTORY_SEPARATOR)) {
        @unlink($realPath);
    }
}

function documentRelationshipSelection(mysqli $conn, string $relationshipType, array $payload): array
{
    $projectCode = null;
    $clientId = null;
    $keypersonId = null;
    $relationshipLabel = null;

    if ($relationshipType === 'tender') {
        $projectCode = (int) ($payload['project_code'] ?? 0);
        if ($projectCode <= 0) {
            throw new RuntimeException('Please select the related tender.', 422);
        }
        $project = dbFetchOne(
            $conn,
            "SELECT code, project_title, tender_code FROM project_info_table WHERE code = ? AND record_status = 'active' LIMIT 1",
            'i',
            [$projectCode]
        );
        if (!$project) {
            throw new RuntimeException('The selected tender is not available.', 422);
        }
        $relationshipLabel = trim((string) ($project['tender_code'] ?? '')) !== ''
            ? trim((string) $project['tender_code']) . ' — ' . (string) $project['project_title']
            : (string) $project['project_title'];
    } elseif ($relationshipType === 'client') {
        $clientId = (int) ($payload['client_id'] ?? 0);
        if ($clientId <= 0) {
            throw new RuntimeException('Please select the related client.', 422);
        }
        $client = dbFetchOne($conn, "SELECT id, clients_name FROM clients_table WHERE id = ? AND status = 'active' LIMIT 1", 'i', [$clientId]);
        if (!$client) {
            throw new RuntimeException('The selected client is not available.', 422);
        }
        $relationshipLabel = (string) $client['clients_name'];
    } elseif ($relationshipType === 'keyperson') {
        $keypersonId = (int) ($payload['keyperson_id'] ?? 0);
        if ($keypersonId <= 0) {
            throw new RuntimeException('Please select the related key person.', 422);
        }
        $keyperson = dbFetchOne(
            $conn,
            "SELECT id, key_person, clients_name FROM keypersons_table WHERE id = ? AND status = 'active' LIMIT 1",
            'i',
            [$keypersonId]
        );
        if (!$keyperson) {
            throw new RuntimeException('The selected key person is not available.', 422);
        }
        $relationshipLabel = trim((string) $keyperson['clients_name']) !== ''
            ? (string) $keyperson['key_person'] . ' — ' . (string) $keyperson['clients_name']
            : (string) $keyperson['key_person'];
    }

    return [
        'project_code' => $projectCode,
        'client_id' => $clientId,
        'keyperson_id' => $keypersonId,
        'relationship_label' => $relationshipLabel,
    ];
}

function documentFormPayload(mysqli $conn, array $payload): array
{
    $title = trim((string) ($payload['document_title'] ?? $payload['title'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('Document title is required.', 422);
    }
    if (documentStringLength($title) > 255) {
        throw new RuntimeException('Document title must not exceed 255 characters.', 422);
    }

    $documentType = normaliseDocumentType($payload['document_type'] ?? '');
    $category = trim((string) ($payload['document_category'] ?? $payload['category'] ?? ''));
    if ($category === '') {
        throw new RuntimeException('Document category is required.', 422);
    }
    if (documentStringLength($category) > 180) {
        throw new RuntimeException('Document category must not exceed 180 characters.', 422);
    }
    assertDocumentCategoryAvailable($conn, $category, $documentType);

    $relationshipType = normaliseDocumentRelationship($payload['relationship_type'] ?? 'general');
    $relationship = documentRelationshipSelection($conn, $relationshipType, $payload);

    $referenceCode = trim((string) ($payload['presentation_code'] ?? $payload['reference_code'] ?? ''));
    $revisionNotes = trim((string) ($payload['updated_content'] ?? $payload['revision_notes'] ?? ''));
    $description = trim((string) ($payload['description'] ?? ''));

    return array_merge([
        'document_title' => $title,
        'document_type' => $documentType,
        'presentation_code' => documentStringSlice($referenceCode, 120),
        'document_category' => $category,
        'updated_content' => documentStringSlice($revisionNotes, 500),
        'description' => documentStringSlice($description, 5000),
        'relationship_type' => $relationshipType,
    ], $relationship);
}

function assertDocumentTitleAvailable(mysqli $conn, string $title, string $type, ?int $excludeId = null): void
{
    $sql = "SELECT id FROM document_table WHERE LOWER(TRIM(document_title)) = LOWER(TRIM(?)) AND document_type = ? AND status = 'active'";
    $types = 'ss';
    $params = [$title, $type];
    if ($excludeId !== null) {
        $sql .= ' AND id <> ?';
        $types .= 'i';
        $params[] = $excludeId;
    }
    $sql .= ' LIMIT 1';

    if (dbFetchOne($conn, $sql, $types, $params)) {
        throw new RuntimeException('An active document with this title and type already exists.', 409);
    }
}

function assertDocumentAccessible(mysqli $conn, array $authUser, int $documentId, bool $includeDeleted = false): array
{
    requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN], 'Only Super Admins and Admins can access Documents.');
    $statusSql = $includeDeleted ? '' : " AND d.status = 'active'";
    $document = dbFetchOne(
        $conn,
        "SELECT d.*,
                CONCAT_WS(' ', creator.first_name, creator.last_name) AS creator_name,
                creator.email AS creator_email,
                CONCAT_WS(' ', updater.first_name, updater.last_name) AS updater_name,
                updater.email AS updater_email,
                p.project_title AS linked_project_title, p.tender_code AS linked_tender_code,
                c.clients_name AS linked_client_name,
                k.key_person AS linked_keyperson_name, k.clients_name AS linked_keyperson_client
         FROM document_table d
         LEFT JOIN users creator ON creator.id = d.created_by_id
         LEFT JOIN users updater ON updater.id = d.updated_by_id
         LEFT JOIN project_info_table p ON p.code = d.project_code
         LEFT JOIN clients_table c ON c.id = d.client_id
         LEFT JOIN keypersons_table k ON k.id = d.keyperson_id
         WHERE d.id = ?{$statusSql}
         LIMIT 1",
        'i',
        [$documentId]
    );

    if (!$document) {
        throw new RuntimeException('Document not found.', 404);
    }
    return $document;
}

function documentLinkedLabel(array $row): ?string
{
    $type = (string) ($row['relationship_type'] ?? 'general');
    if ($type === 'tender') {
        $code = trim((string) ($row['linked_tender_code'] ?? ''));
        $title = trim((string) ($row['linked_project_title'] ?? ''));
        return $code !== '' ? $code . ($title !== '' ? ' — ' . $title : '') : ($title !== '' ? $title : null);
    }
    if ($type === 'client') {
        return trim((string) ($row['linked_client_name'] ?? '')) ?: null;
    }
    if ($type === 'keyperson') {
        $name = trim((string) ($row['linked_keyperson_name'] ?? ''));
        $client = trim((string) ($row['linked_keyperson_client'] ?? ''));
        return $name !== '' ? $name . ($client !== '' ? ' — ' . $client : '') : null;
    }
    return null;
}

function documentResponsePayload(array $row): array
{
    $extension = strtolower((string) ($row['file_extension'] ?? pathinfo((string) ($row['document'] ?? ''), PATHINFO_EXTENSION)));
    $absolutePath = resolveDocumentAbsolutePath($row);
    $originalName = trim((string) ($row['original_name'] ?? ''));
    if ($originalName === '') {
        $legacyName = basename((string) ($row['document'] ?? ''));
        $originalName = preg_replace('/^[a-z0-9]{6}_/i', '', $legacyName) ?: $legacyName;
    }

    return [
        'id' => (int) $row['id'],
        'document_title' => $row['document_title'],
        'document_type' => $row['document_type'],
        'presentation_code' => $row['presentation_code'] !== 'N/A' ? $row['presentation_code'] : '',
        'document_category' => $row['document_category'],
        'updated_content' => $row['updated_content'] !== 'N/A' ? $row['updated_content'] : '',
        'description' => $row['description'] ?? '',
        'relationship_type' => $row['relationship_type'] ?? 'general',
        'project_code' => $row['project_code'] !== null ? (int) $row['project_code'] : null,
        'client_id' => $row['client_id'] !== null ? (int) $row['client_id'] : null,
        'keyperson_id' => $row['keyperson_id'] !== null ? (int) $row['keyperson_id'] : null,
        'relationship_label' => documentLinkedLabel($row),
        'original_name' => $originalName,
        'stored_name' => $row['document'],
        'mime_type' => $row['mime_type'] ?? 'application/octet-stream',
        'file_extension' => $extension,
        'file_size' => (int) ($row['file_size'] ?? 0),
        'checksum_sha256' => $row['checksum_sha256'] ?? '',
        'version_no' => max(1, (int) ($row['version_no'] ?? 1)),
        'previewable' => in_array($extension, documentPreviewableExtensions(), true),
        'file_available' => $absolutePath !== null,
        'is_legacy' => str_starts_with((string) ($row['storage_path'] ?? ''), 'legacy/'),
        'status' => $row['status'] ?? 'active',
        'created_by_id' => $row['created_by_id'] !== null ? (int) $row['created_by_id'] : null,
        'created_by_name' => trim((string) ($row['creator_name'] ?? '')) ?: ($row['creator_email'] ?? $row['created_by'] ?? ''),
        'updated_by_id' => $row['updated_by_id'] !== null ? (int) $row['updated_by_id'] : null,
        'updated_by_name' => trim((string) ($row['updater_name'] ?? '')) ?: ($row['updater_email'] ?? $row['updated_by'] ?? ''),
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'replaced_at' => $row['replaced_at'] ?? null,
    ];
}

function parseDocumentIds(array $payload): array
{
    $raw = $payload['ids'] ?? [];
    if (!is_array($raw)) {
        throw new RuntimeException('Please select one or more documents.', 422);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        throw new RuntimeException('Please select one or more documents.', 422);
    }
    if (count($ids) > 100) {
        throw new RuntimeException('You can update at most 100 documents at once.', 422);
    }
    return $ids;
}
