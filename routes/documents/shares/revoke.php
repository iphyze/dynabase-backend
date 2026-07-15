<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/request.php';
require_once __DIR__ . '/../../../includes/documentShares.php';
require_once __DIR__ . '/../../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN], 'Only Super Admins and Admins can revoke document links.');
assertDocumentRevisionSchema($conn);
assertDocumentShareSchema($conn);
cleanupDocumentShareSecurityArtifacts($conn);
$payload = readJsonBody();

$shareId = (int) ($payload['share_id'] ?? $payload['id'] ?? 0);
if ($shareId <= 0) {
    throw new RuntimeException('Share-link ID is required.', 422);
}
$share = assertDocumentShareAdminAccessible($conn, $authUser, $shareId);
if (($share['status'] ?? '') === 'revoked') {
    jsonResponse([
        'status' => 'Success',
        'message' => 'Document link is already revoked.',
        'data' => documentShareAdminPayload($conn, $share),
    ]);
}

$conn->begin_transaction();
try {
    dbExecute(
        $conn,
        'UPDATE document_share_links
         SET status = "revoked", revoked_at = CURRENT_TIMESTAMP, revoked_by_id = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = "active"',
        'ii',
        [(int) $authUser['id'], $shareId]
    )->close();
    dbExecute(
        $conn,
        'UPDATE document_share_sessions
         SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP)
         WHERE share_link_id = ? AND revoked_at IS NULL',
        'i',
        [$shareId]
    )->close();
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

$revoked = fetchDocumentShareById($conn, $shareId);
if (!$revoked) {
    throw new RuntimeException('The revoked document link could not be loaded.', 500);
}

writeAuditLog($conn, $authUser, 'document.share_revoked', 'document', (int) $share['document_id'], [
    'share_id' => $shareId,
    'access_mode' => $share['access_mode'],
    'allow_download' => (bool) $share['allow_download'],
    'expires_at' => $share['expires_at'],
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Document link revoked successfully.',
    'data' => documentShareAdminPayload($conn, $revoked),
]);
