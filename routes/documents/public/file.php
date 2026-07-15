<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/request.php';
require_once __DIR__ . '/../../../includes/documentShares.php';
require_once __DIR__ . '/../../../includes/audit.php';

requireMethod('GET');
assertDocumentRevisionSchema($conn);
assertDocumentShareSchema($conn);

$rawToken = cleanString($_GET['token'] ?? '');
$sessionToken = cleanString($_GET['session_token'] ?? '');
$mode = strtolower(cleanString($_GET['mode'] ?? 'preview'));
if (!in_array($mode, ['preview', 'download'], true)) {
    throw new RuntimeException('Invalid shared-file delivery mode.', 422);
}

$share = assertPublicDocumentShareAvailable($conn, $rawToken);
assertDocumentShareAccess($conn, $share, $sessionToken);
$revision = resolveDocumentShareTargetRevision($conn, $share);
$filePayload = documentRevisionResponsePayload($revision);

if ($mode === 'download' && !(bool) $share['allow_download']) {
    throw new RuntimeException('Downloading is disabled for this document link.', 403);
}
if ($mode === 'preview' && !(bool) $filePayload['previewable']) {
    throw new RuntimeException('This file type cannot be previewed online. Download access may be available from the shared page.', 422);
}

$absolutePath = resolveDocumentAbsolutePath($revision);
if ($absolutePath === null) {
    throw new RuntimeException('The shared document file is unavailable.', 404);
}

$isDownload = $mode === 'download';
touchDocumentShareAccess($conn, (int) $share['id'], $isDownload);
writeAuditLog($conn, null, $isDownload ? 'document.share_downloaded' : 'document.share_previewed', 'document', (int) $share['document_id'], [
    'share_id' => (int) $share['id'],
    'revision_id' => (int) $revision['id'],
    'revision_code' => $revision['revision_code'],
    'original_name' => $revision['original_name'],
    'ip_hash' => documentShareIpHash(),
]);

$filename = cleanDocumentOriginalName((string) $revision['original_name']);
$disposition = $isDownload ? 'attachment' : 'inline';
$mimeType = $isDownload ? 'application/octet-stream' : (string) $revision['mime_type'];

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
