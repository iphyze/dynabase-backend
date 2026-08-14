<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/agreementExternal.php';

requireMethod('GET');
assertAgreementExternalSchema($conn);
assertDocumentRevisionSchema($conn);
$rawToken = cleanString($_GET['token'] ?? '');
$sessionToken = cleanString($_GET['session_token'] ?? '');
$share = assertPublicAgreementExternalAvailable($conn, $rawToken);
assertAgreementExternalAccess($conn, $share, $sessionToken);
if (!(bool) $share['allow_document_download']) {
    throw new RuntimeException('Document downloading is disabled for this agreement workspace.', 403);
}
$revision = agreementExternalCurrentRevision($conn, $share);
if (!$revision) {
    throw new RuntimeException('No agreement document is currently available.', 404);
}
$absolutePath = resolveDocumentAbsolutePath($revision);
if ($absolutePath === null) {
    throw new RuntimeException('The agreement document file is unavailable.', 404);
}

dbExecute(
    $conn,
    'UPDATE agreement_external_links
     SET download_count = download_count + 1, access_count = access_count + 1,
         last_accessed_at = CURRENT_TIMESTAMP, updated_at = updated_at
     WHERE id = ? AND status = "active"',
    'i',
    [(int) $share['id']]
)->close();
writeAuditLog($conn, null, 'agreement_register.external_document_downloaded', 'agreement_register', (int) $share['agreement_id'], [
    'share_id' => (int) $share['id'],
    'revision_id' => (int) $revision['id'],
    'revision_code' => $revision['revision_code'],
    'ip_hash' => agreementExternalIpHash(),
]);

$filename = cleanDocumentOriginalName((string) $revision['original_name']);
while (ob_get_level() > 0) {
    ob_end_clean();
}
header_remove('Content-Type');
header('Content-Type: application/octet-stream');
header('Content-Length: ' . (string) filesize($absolutePath));
header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
readfile($absolutePath);
exit;
