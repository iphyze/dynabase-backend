<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/documents.php';

requireMethod('GET');
assertDocumentRevisionSchema($conn);

$documentId = (int) ($_GET['id'] ?? $_GET['document_id'] ?? 0);
$mode = strtolower(cleanString($_GET['mode'] ?? 'download'));
if (!in_array($mode, ['preview', 'download'], true)) {
    throw new RuntimeException('Invalid file delivery mode.', 422);
}

$document = assertPublicDocumentLibraryDocument($conn, $documentId);
$source = publicDocumentLibraryFileSource($document);
$absolutePath = resolveDocumentAbsolutePath($source);
if ($absolutePath === null) {
    throw new RuntimeException('The document file is unavailable.', 404);
}

$extension = strtolower(trim((string) ($source['file_extension'] ?? pathinfo((string) ($source['original_name'] ?? ''), PATHINFO_EXTENSION))));
$capabilities = documentFileCapabilities($extension);
if ($mode === 'preview' && !$capabilities['previewable']) {
    throw new RuntimeException('This file type cannot be previewed online. Download the file to access it.', 422);
}

$disposition = $mode === 'preview' && $capabilities['previewable'] ? 'inline' : 'attachment';
$filename = cleanDocumentOriginalName((string) ($source['original_name'] ?? 'document'));
$mimeType = $disposition === 'inline'
    ? (string) ($source['mime_type'] ?? 'application/octet-stream')
    : 'application/octet-stream';

while (ob_get_level() > 0) {
    ob_end_clean();
}
header_remove('Content-Type');
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($absolutePath));
header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($filename, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
readfile($absolutePath);
exit;
