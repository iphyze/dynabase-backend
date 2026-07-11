<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('GET');
$authUser = authenticateUser();
$id = (int) ($_GET['id'] ?? 0);
$mode = strtolower(cleanString($_GET['mode'] ?? 'download'));
if ($id <= 0) {
    throw new RuntimeException('Document ID is required.', 422);
}
if (!in_array($mode, ['preview', 'download'], true)) {
    throw new RuntimeException('Invalid file delivery mode.', 422);
}

$document = assertDocumentAccessible($conn, $authUser, $id);
$absolutePath = resolveDocumentAbsolutePath($document);
if ($absolutePath === null) {
    throw new RuntimeException(
        str_starts_with((string) ($document['storage_path'] ?? ''), 'legacy/')
            ? 'The legacy document file has not yet been copied into secure storage.'
            : 'The document file is unavailable.',
        404
    );
}

$payload = documentResponsePayload($document);
$previewable = (bool) $payload['previewable'];
$disposition = $mode === 'preview' && $previewable ? 'inline' : 'attachment';
$filename = cleanDocumentOriginalName((string) $payload['original_name']);
$mimeType = $disposition === 'inline' ? (string) $payload['mime_type'] : 'application/octet-stream';

writeAuditLog($conn, $authUser, $disposition === 'inline' ? 'document.previewed' : 'document.downloaded', 'document', $id, [
    'title' => $document['document_title'],
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
