<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/dbHelpers.php';
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/settings.php';

const DYNABASE_DOCUMENT_TYPES = ['Profile', 'Presentation', 'Tender'];
const DYNABASE_DOCUMENT_RELATIONSHIPS = ['general', 'tender', 'client', 'keyperson'];
const DYNABASE_DOCUMENT_REVISION_STATUSES = ['active', 'replaced', 'deleted'];

function documentMaxUploadBytes(): int
{
    $configuredMb = (int) envString('DOCUMENT_MAX_UPLOAD_MB', '25');
    return max(1, min($configuredMb, 100)) * 1024 * 1024;
}

/**
 * Uploads are intentionally limited to the business document formats requested
 * for the Documents workspace. Existing legacy files remain deliverable.
 */
function documentAllowedExtensions(): array
{
    return [
        'pdf' => ['application/pdf', 'application/octet-stream'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/cdfv2', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/cdfv2', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/cdfv2', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'zip' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed', 'multipart/x-zip', 'application/octet-stream'],
        'rar' => ['application/vnd.rar', 'application/rar', 'application/x-rar', 'application/x-rar-compressed', 'application/octet-stream'],
    ];
}

function documentPreviewableExtensions(): array
{
    // Preserve preview support for legacy files already stored in Dynabase.
    return ['pdf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'webp'];
}

function documentArchiveExtensions(): array
{
    return ['zip', 'rar'];
}

function documentFileCapabilities(string $extension): array
{
    $extension = strtolower(trim($extension));
    $previewable = in_array($extension, documentPreviewableExtensions(), true);
    $isArchive = in_array($extension, documentArchiveExtensions(), true);

    return [
        'previewable' => $previewable,
        'download_only' => !$previewable,
        'file_kind' => $isArchive ? 'archive' : 'document',
        'delivery_mode' => $previewable ? 'preview' : 'download',
    ];
}

function documentTypeLabel(mixed $value): string
{
    return trim((string) preg_replace('/\s+/u', ' ', trim((string) $value)));
}

function documentTypes(mysqli $conn): array
{
    $configured = appSettingValue($conn, 'document_types', DYNABASE_DOCUMENT_TYPES);
    $configured = is_array($configured) ? $configured : [];

    $sectionTypes = array_column(dbFetchAll(
        $conn,
        "SELECT DISTINCT section_type AS value
         FROM tender_document_sections
         WHERE TRIM(section_type) <> ''
         ORDER BY section_type ASC"
    ), 'value');

    $existingTypes = array_column(dbFetchAll(
        $conn,
        "SELECT DISTINCT document_type AS value
         FROM document_table
         WHERE TRIM(document_type) <> ''
         ORDER BY document_type ASC"
    ), 'value');

    $types = [];
    $seen = [];
    foreach (array_merge(DYNABASE_DOCUMENT_TYPES, $configured, $sectionTypes, $existingTypes) as $value) {
        $label = documentTypeLabel($value);
        if ($label === '') {
            continue;
        }

        $key = strtolower($label);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $types[] = $label;
    }

    return $types;
}

function normaliseDocumentType(mysqli $conn, mixed $value): string
{
    $candidate = documentTypeLabel($value);
    foreach (documentTypes($conn) as $documentType) {
        if (strcasecmp($documentType, $candidate) === 0) {
            return $documentType;
        }
    }

    throw new RuntimeException('Please select a valid document type.', 422);
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

function documentFileStartsWith(string $path, string $signature): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $bytes = fread($handle, strlen($signature));
    fclose($handle);
    return $bytes === $signature;
}

function documentValidateOoxmlPackage(string $path, string $extension): bool
{
    if (!documentFileStartsWith($path, "PK\x03\x04")) {
        return false;
    }

    if (!class_exists('ZipArchive')) {
        return true;
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return false;
    }

    $requiredDirectory = match ($extension) {
        'docx' => 'word/',
        'xlsx' => 'xl/',
        'pptx' => 'ppt/',
        default => '',
    };
    $hasContentTypes = $zip->locateName('[Content_Types].xml', ZipArchive::FL_NOCASE) !== false;
    $hasFormatDirectory = false;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);
        if ($requiredDirectory !== '' && str_starts_with(strtolower($name), $requiredDirectory)) {
            $hasFormatDirectory = true;
            break;
        }
    }
    $zip->close();

    return $hasContentTypes && $hasFormatDirectory;
}

function documentValidateZipArchive(string $path): bool
{
    $hasZipSignature = documentFileStartsWith($path, "PK\x03\x04")
        || documentFileStartsWith($path, "PK\x05\x06");
    if (!$hasZipSignature) {
        return false;
    }

    $fileSize = @filesize($path);
    if (!is_int($fileSize) || $fileSize < 22) {
        return false;
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $tailLength = min($fileSize, 65557);
    if (fseek($handle, -$tailLength, SEEK_END) !== 0) {
        fclose($handle);
        return false;
    }
    $tail = fread($handle, $tailLength);
    fclose($handle);
    if (!is_string($tail) || strrpos($tail, "PK\x05\x06") === false) {
        return false;
    }

    if (!class_exists('ZipArchive')) {
        return true;
    }

    $zip = new ZipArchive();
    $opened = $zip->open($path, ZipArchive::CHECKCONS);
    if ($opened !== true) {
        return false;
    }
    $zip->close();
    return true;
}

function documentValidateRarArchive(string $path): bool
{
    $fileSize = @filesize($path);
    if (!is_int($fileSize) || $fileSize < 20) {
        return false;
    }

    return documentFileStartsWith($path, "Rar!\x1A\x07\x00")
        || documentFileStartsWith($path, "Rar!\x1A\x07\x01\x00");
}

function documentValidateUploadSignature(string $path, string $extension): bool
{
    if ($extension === 'pdf') {
        return documentFileStartsWith($path, '%PDF-');
    }
    if (in_array($extension, ['doc', 'xls', 'ppt'], true)) {
        return documentFileStartsWith($path, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
    }
    if (in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
        return documentValidateOoxmlPackage($path, $extension);
    }
    if ($extension === 'zip') {
        return documentValidateZipArchive($path);
    }
    if ($extension === 'rar') {
        return documentValidateRarArchive($path);
    }
    return false;
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
        throw new RuntimeException('Unsupported file type. Upload a PDF, Word, PowerPoint, Excel, ZIP or RAR file.', 422);
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
    if (!documentValidateUploadSignature($tmpName, $extension)) {
        throw new RuntimeException('The uploaded document is not a valid ' . strtoupper($extension) . ' file.', 422);
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

function documentRelationshipSelection(mysqli $conn, array $authUser, string $relationshipType, array $payload): array
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
        try {
            $keyperson = assertKeypersonAccessible($conn, $authUser, $keypersonId);
        } catch (RuntimeException $exception) {
            if ((int) $exception->getCode() === 404) {
                throw new RuntimeException('The selected key person is not available.', 422);
            }
            throw $exception;
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

function documentFormPayload(mysqli $conn, array $authUser, array $payload): array
{
    $title = trim((string) ($payload['document_title'] ?? $payload['title'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('Document title is required.', 422);
    }
    if (documentStringLength($title) > 255) {
        throw new RuntimeException('Document title must not exceed 255 characters.', 422);
    }

    $documentType = normaliseDocumentType($conn, $payload['document_type'] ?? '');
    $category = trim((string) ($payload['document_category'] ?? $payload['category'] ?? ''));
    if ($category === '') {
        throw new RuntimeException('Document category is required.', 422);
    }
    if (documentStringLength($category) > 180) {
        throw new RuntimeException('Document category must not exceed 180 characters.', 422);
    }
    assertDocumentCategoryAvailable($conn, $category, $documentType);

    $relationshipType = normaliseDocumentRelationship($payload['relationship_type'] ?? 'general');
    $relationship = documentRelationshipSelection($conn, $authUser, $relationshipType, $payload);

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
                k.key_person AS linked_keyperson_name, k.clients_name AS linked_keyperson_client,
                (SELECT COUNT(*) FROM document_revisions rc WHERE rc.document_id = d.id AND rc.record_status = 'active') AS revision_count,
                (SELECT cr.id FROM document_revisions cr WHERE cr.document_id = d.id AND cr.record_status = 'active' AND cr.is_current = 1 ORDER BY cr.id DESC LIMIT 1) AS current_revision_id,
                (SELECT cr.revision_code FROM document_revisions cr WHERE cr.document_id = d.id AND cr.record_status = 'active' AND cr.is_current = 1 ORDER BY cr.id DESC LIMIT 1) AS current_revision_code
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
    $capabilities = documentFileCapabilities($extension);
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
        'revision_count' => max(0, (int) ($row['revision_count'] ?? 0)),
        'current_revision_id' => isset($row['current_revision_id']) && $row['current_revision_id'] !== null ? (int) $row['current_revision_id'] : null,
        'current_revision_code' => trim((string) ($row['current_revision_code'] ?? '')) ?: null,
        'previewable' => $capabilities['previewable'],
        'download_only' => $capabilities['download_only'],
        'file_kind' => $capabilities['file_kind'],
        'delivery_mode' => $capabilities['delivery_mode'],
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

function documentRevisionTableExists(mysqli $conn): bool
{
    $row = dbFetchOne(
        $conn,
        "SELECT 1 AS available
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'document_revisions'
         LIMIT 1"
    );
    return $row !== null;
}

function assertDocumentRevisionSchema(mysqli $conn): void
{
    if (!documentRevisionTableExists($conn)) {
        throw new RuntimeException('Document revision storage is not installed. Apply the Documents revision migration and try again.', 409);
    }

    $requiredColumns = [
        'id', 'document_id', 'revision_code', 'revision_no', 'revision_notes', 'original_name', 'stored_name',
        'storage_path', 'mime_type', 'file_extension', 'file_size', 'checksum_sha256', 'preview_file_path',
        'is_current', 'record_status', 'replaces_revision_id', 'uploaded_by_id', 'uploaded_by', 'uploaded_at',
        'replaced_at',
    ];
    $rows = dbFetchAll(
        $conn,
        "SELECT COLUMN_NAME AS column_name
         FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'document_revisions'"
    );
    $available = array_map(static fn (array $row): string => strtolower((string) $row['column_name']), $rows);
    $missing = array_values(array_diff($requiredColumns, $available));
    if ($missing !== []) {
        error_log('[Dynabase Documents] Missing document_revisions columns: ' . implode(', ', $missing));
        throw new RuntimeException('Document revision storage is incomplete. Reapply the Documents revision migration and try again.', 409);
    }
}

function documentFormBoolean(mixed $value, bool $default = false): bool
{
    if ($value === null || $value === '') {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function normaliseDocumentRevisionCode(mixed $value, ?int $fallbackNo = null): string
{
    $code = trim((string) $value);
    if ($code === '' && $fallbackNo !== null) {
        $code = 'Rev' . str_pad((string) max(1, $fallbackNo), 3, '0', STR_PAD_LEFT);
    }
    if ($code === '') {
        throw new RuntimeException('Revision code is required.', 422);
    }
    if (documentStringLength($code) > 50 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,49}$/', $code)) {
        throw new RuntimeException('Revision code may contain letters, numbers, dots, dashes, underscores or slashes.', 422);
    }
    return $code;
}

function normaliseDocumentRevisionNotes(mixed $value): string
{
    $notes = trim((string) $value);
    if (documentStringLength($notes) > 1000) {
        throw new RuntimeException('Revision notes must not exceed 1,000 characters.', 422);
    }
    return $notes;
}

function lockDocumentForRevision(mysqli $conn, int $documentId): void
{
    $row = dbFetchOne(
        $conn,
        "SELECT id FROM document_table WHERE id = ? AND status = 'active' LIMIT 1 FOR UPDATE",
        'i',
        [$documentId]
    );
    if (!$row) {
        throw new RuntimeException('Document not found.', 404);
    }
}

function nextDocumentRevisionNo(mysqli $conn, int $documentId): int
{
    return dbScalarInt(
        $conn,
        "SELECT COALESCE(MAX(revision_no), 0) + 1 AS total FROM document_revisions WHERE document_id = ?",
        'i',
        [$documentId]
    );
}

function assertDocumentRevisionCodeAvailable(mysqli $conn, int $documentId, string $revisionCode): void
{
    $row = dbFetchOne(
        $conn,
        "SELECT id FROM document_revisions
         WHERE document_id = ? AND LOWER(TRIM(revision_code)) = LOWER(TRIM(?)) AND record_status = 'active'
         LIMIT 1",
        'is',
        [$documentId, $revisionCode]
    );
    if ($row) {
        throw new RuntimeException('An active revision with this code already exists for the document.', 409);
    }
}

function insertDocumentRevisionRecord(
    mysqli $conn,
    int $documentId,
    string $revisionCode,
    string $revisionNotes,
    array $file,
    array $authUser,
    bool $makeCurrent,
    ?int $replacesRevisionId = null
): array {
    $revisionNo = nextDocumentRevisionNo($conn, $documentId);
    $actorId = (int) $authUser['id'];
    $actorEmail = actorEmail($authUser);

    if ($makeCurrent) {
        dbExecute(
            $conn,
            "UPDATE document_revisions SET is_current = 0 WHERE document_id = ? AND record_status = 'active'",
            'i',
            [$documentId]
        )->close();
    }

    $stmt = dbExecute(
        $conn,
        'INSERT INTO document_revisions
            (document_id, revision_code, revision_no, revision_notes, original_name, stored_name, storage_path,
             mime_type, file_extension, file_size, checksum_sha256, preview_file_path, is_current, record_status,
             replaces_revision_id, uploaded_by_id, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, "active", ?, ?, ?)',
        'isissssssisiiis',
        [
            $documentId,
            $revisionCode,
            $revisionNo,
            $revisionNotes,
            $file['original_name'],
            $file['stored_name'],
            $file['storage_path'],
            $file['mime_type'],
            $file['file_extension'],
            $file['file_size'],
            $file['checksum_sha256'],
            $makeCurrent ? 1 : 0,
            $replacesRevisionId,
            $actorId,
            $actorEmail,
        ]
    );
    $revisionId = (int) $stmt->insert_id;
    $stmt->close();

    if ($makeCurrent) {
        syncDocumentCurrentRevision($conn, $documentId, $revisionId, $authUser);
    } else {
        dbExecute(
            $conn,
            "UPDATE document_table SET updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            'sii',
            [$actorEmail, $actorId, $documentId]
        )->close();
    }

    $revision = fetchDocumentRevision($conn, $revisionId, true);
    if (!$revision) {
        throw new RuntimeException('The document revision could not be loaded after upload.', 500);
    }
    return $revision;
}

function syncDocumentCurrentRevision(mysqli $conn, int $documentId, int $revisionId, array $authUser): void
{
    $revision = dbFetchOne(
        $conn,
        "SELECT * FROM document_revisions WHERE id = ? AND document_id = ? AND record_status = 'active' LIMIT 1",
        'ii',
        [$revisionId, $documentId]
    );
    if (!$revision) {
        throw new RuntimeException('The selected revision is unavailable.', 404);
    }
    if (resolveDocumentAbsolutePath($revision) === null) {
        throw new RuntimeException('The selected revision file is unavailable and cannot be made current.', 409);
    }

    assertDocumentCurrentRevisionShareDelivery($conn, $documentId, (string) ($revision['file_extension'] ?? ''));

    dbExecute(
        $conn,
        'UPDATE document_table
         SET document = ?, original_name = ?, storage_path = ?, mime_type = ?, file_extension = ?, file_size = ?,
             checksum_sha256 = ?, version_no = ?, updated_content = ?, updated_by = ?, updated_by_id = ?,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = "active"',
        'sssssisissii',
        [
            $revision['stored_name'],
            $revision['original_name'],
            $revision['storage_path'],
            $revision['mime_type'],
            $revision['file_extension'],
            (int) $revision['file_size'],
            $revision['checksum_sha256'],
            (int) $revision['revision_no'],
            $revision['revision_notes'],
            actorEmail($authUser),
            (int) $authUser['id'],
            $documentId,
        ]
    )->close();
}

function documentShareDeliveryPolicyAvailable(mysqli $conn): bool
{
    $requiredColumns = ['document_id', 'revision_id', 'allow_download', 'status', 'expires_at'];
    $placeholders = implode(',', array_fill(0, count($requiredColumns), '?'));
    $rows = dbFetchAll(
        $conn,
        "SELECT COLUMN_NAME AS column_name
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'document_share_links'
           AND column_name IN ({$placeholders})",
        str_repeat('s', count($requiredColumns)),
        $requiredColumns
    );
    $available = array_map(static fn (array $row): string => strtolower((string) $row['column_name']), $rows);
    return count(array_unique($available)) === count($requiredColumns);
}

function assertDocumentCurrentRevisionShareDelivery(mysqli $conn, int $documentId, string $extension): void
{
    if (documentFileCapabilities($extension)['previewable'] || !documentShareDeliveryPolicyAvailable($conn)) {
        return;
    }

    $blockedLinks = dbScalarInt(
        $conn,
        "SELECT COUNT(*) AS total
         FROM document_share_links
         WHERE document_id = ?
           AND revision_id IS NULL
           AND allow_download = 0
           AND status = 'active'
           AND expires_at > NOW()",
        'i',
        [$documentId]
    );
    if ($blockedLinks <= 0) {
        return;
    }

    $label = $blockedLinks === 1 ? 'link has' : 'links have';
    throw new RuntimeException(
        "This file type cannot be previewed online, and {$blockedLinks} active current-revision share {$label} downloads disabled. Enable downloads on those links before making this revision current.",
        409
    );
}

function fetchDocumentRevision(mysqli $conn, int $revisionId, bool $includeReplaced = true): ?array
{
    $statusSql = $includeReplaced ? "r.record_status IN ('active', 'replaced')" : "r.record_status = 'active'";
    return dbFetchOne(
        $conn,
        "SELECT r.*,
                CONCAT_WS(' ', uploader.first_name, uploader.last_name) AS uploader_name,
                uploader.email AS uploader_email,
                replacement.id AS replacement_revision_id
         FROM document_revisions r
         LEFT JOIN users uploader ON uploader.id = r.uploaded_by_id
         LEFT JOIN document_revisions replacement ON replacement.replaces_revision_id = r.id AND replacement.record_status <> 'deleted'
         WHERE r.id = ? AND {$statusSql}
         LIMIT 1",
        'i',
        [$revisionId]
    );
}

function assertDocumentRevisionAccessible(
    mysqli $conn,
    array $authUser,
    int $documentId,
    int $revisionId,
    bool $includeReplaced = true
): array {
    assertDocumentAccessible($conn, $authUser, $documentId);
    $statusSql = $includeReplaced ? "r.record_status IN ('active', 'replaced')" : "r.record_status = 'active'";
    $revision = dbFetchOne(
        $conn,
        "SELECT r.*,
                CONCAT_WS(' ', uploader.first_name, uploader.last_name) AS uploader_name,
                uploader.email AS uploader_email,
                replacement.id AS replacement_revision_id
         FROM document_revisions r
         LEFT JOIN users uploader ON uploader.id = r.uploaded_by_id
         LEFT JOIN document_revisions replacement ON replacement.replaces_revision_id = r.id AND replacement.record_status <> 'deleted'
         WHERE r.id = ? AND r.document_id = ? AND {$statusSql}
         LIMIT 1",
        'ii',
        [$revisionId, $documentId]
    );
    if (!$revision) {
        throw new RuntimeException('Document revision not found.', 404);
    }
    return $revision;
}

function documentRevisionResponsePayload(array $row): array
{
    $extension = strtolower((string) ($row['file_extension'] ?? ''));
    $capabilities = documentFileCapabilities($extension);
    $absolutePath = resolveDocumentAbsolutePath($row);
    return [
        'id' => (int) $row['id'],
        'document_id' => (int) $row['document_id'],
        'revision_code' => $row['revision_code'],
        'revision_no' => (int) $row['revision_no'],
        'revision_notes' => $row['revision_notes'] ?? '',
        'original_name' => $row['original_name'],
        'stored_name' => $row['stored_name'],
        'mime_type' => $row['mime_type'],
        'file_extension' => $extension,
        'file_size' => (int) $row['file_size'],
        'checksum_sha256' => $row['checksum_sha256'],
        'preview_file_path' => $row['preview_file_path'] ?? null,
        'previewable' => $capabilities['previewable'],
        'download_only' => $capabilities['download_only'],
        'file_kind' => $capabilities['file_kind'],
        'delivery_mode' => $capabilities['delivery_mode'],
        'file_available' => $absolutePath !== null,
        'is_current' => (bool) $row['is_current'],
        'record_status' => $row['record_status'],
        'replaces_revision_id' => $row['replaces_revision_id'] !== null ? (int) $row['replaces_revision_id'] : null,
        'replacement_revision_id' => isset($row['replacement_revision_id']) && $row['replacement_revision_id'] !== null
            ? (int) $row['replacement_revision_id']
            : null,
        'uploaded_by_id' => $row['uploaded_by_id'] !== null ? (int) $row['uploaded_by_id'] : null,
        'uploaded_by_name' => trim((string) ($row['uploader_name'] ?? '')) ?: ($row['uploader_email'] ?? $row['uploaded_by'] ?? ''),
        'uploaded_at' => $row['uploaded_at'],
        'replaced_at' => $row['replaced_at'] ?? null,
    ];
}

function documentRevisionRows(mysqli $conn, int $documentId): array
{
    return dbFetchAll(
        $conn,
        "SELECT r.*,
                CONCAT_WS(' ', uploader.first_name, uploader.last_name) AS uploader_name,
                uploader.email AS uploader_email,
                replacement.id AS replacement_revision_id
         FROM document_revisions r
         LEFT JOIN users uploader ON uploader.id = r.uploaded_by_id
         LEFT JOIN document_revisions replacement ON replacement.replaces_revision_id = r.id AND replacement.record_status <> 'deleted'
         WHERE r.document_id = ? AND r.record_status IN ('active', 'replaced')
         ORDER BY r.is_current DESC, r.revision_no DESC, r.id DESC",
        'i',
        [$documentId]
    );
}

function documentDetailResponsePayload(mysqli $conn, array $row): array
{
    $base = documentResponsePayload($row);
    $revisions = array_map('documentRevisionResponsePayload', documentRevisionRows($conn, (int) $row['id']));
    $base['revisions'] = $revisions;
    $base['revision_count'] = count(array_filter(
        $revisions,
        static fn (array $revision): bool => $revision['record_status'] === 'active'
    ));
    $base['current_revision'] = null;
    foreach ($revisions as $revision) {
        if ($revision['record_status'] === 'active' && $revision['is_current']) {
            $base['current_revision'] = $revision;
            $base['current_revision_id'] = $revision['id'];
            $base['current_revision_code'] = $revision['revision_code'];
            break;
        }
    }
    return $base;
}
