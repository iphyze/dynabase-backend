<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('GET');
$authUser = authenticateUser();
assertDocumentRevisionSchema($conn);

$id = (int) ($_GET['id'] ?? $_GET['document_id'] ?? 0);
$revisionId = (int) ($_GET['revision_id'] ?? 0);
$mode = strtolower(cleanString($_GET['mode'] ?? 'download'));
if ($id <= 0) {
    throw new RuntimeException('Document ID is required.', 422);
}
if (!in_array($mode, ['preview', 'download'], true)) {
    throw new RuntimeException('Invalid file delivery mode.', 422);
}

$document = assertDocumentAccessible($conn, $authUser, $id);
$revision = null;
if ($revisionId > 0) {
    $revision = assertDocumentRevisionAccessible($conn, $authUser, $id, $revisionId, true);
    $filePayload = documentRevisionResponsePayload($revision);
    $absolutePath = resolveDocumentAbsolutePath($revision);
} else {
    $filePayload = documentResponsePayload($document);
    $absolutePath = resolveDocumentAbsolutePath($document);
}

if ($absolutePath === null) {
    $legacy = str_starts_with((string) (($revision ?? $document)['storage_path'] ?? ''), 'legacy/');
    throw new RuntimeException(
        $legacy
            ? 'The legacy document file has not yet been copied into secure storage.'
            : 'The document file is unavailable.',
        404
    );
}

$previewable = (bool) $filePayload['previewable'];
if ($mode === 'preview' && !$previewable) {
    throw new RuntimeException('This file type cannot be previewed online. Download the file to access it.', 422);
}
$disposition = $mode === 'preview' && $previewable ? 'inline' : 'attachment';
$filename = cleanDocumentOriginalName((string) $filePayload['original_name']);
$mimeType = $disposition === 'inline' ? (string) $filePayload['mime_type'] : 'application/octet-stream';

writeAuditLog($conn, $authUser, $disposition === 'inline' ? 'document.previewed' : 'document.downloaded', 'document', $id, [
    'title' => $document['document_title'],
    'revision_id' => $revisionId > 0 ? $revisionId : ($document['current_revision_id'] ?? null),
    'revision_code' => $revision['revision_code'] ?? ($document['current_revision_code'] ?? null),
    'original_name' => $filename,
]);

while (ob_get_level() > 0) {
    ob_end_clean();
}
header_remove('Content-Type');
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($absolutePath));
header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($filename, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
readfile($absolutePath);
exit;
