<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/request.php';
require_once __DIR__ . '/../../../includes/documentShares.php';
require_once __DIR__ . '/../../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN], 'Only Super Admins and Admins can update document links.');
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
    throw new RuntimeException('Revoked document links cannot be updated.', 409);
}

$accessMode = normaliseDocumentShareAccessMode($payload['access_mode'] ?? $share['access_mode']);
$linkName = normaliseDocumentShareLinkName($payload['link_name'] ?? $share['link_name']);
$allowDownload = array_key_exists('allow_download', $payload)
    ? documentFormBoolean($payload['allow_download'], false)
    : (bool) $share['allow_download'];
$expiresAt = array_key_exists('expires_at', $payload)
    ? normaliseDocumentShareExpiry($payload['expires_at'])
    : (string) $share['expires_at'];

$passwordProvided = array_key_exists('password', $payload) && (string) $payload['password'] !== '';
if ($accessMode === 'open') {
    $passwordHash = null;
} elseif ($passwordProvided) {
    $passwordHash = normaliseDocumentSharePassword($payload['password'], true);
} elseif (($share['access_mode'] ?? '') === 'controlled' && !empty($share['password_hash'])) {
    $passwordHash = (string) $share['password_hash'];
} else {
    throw new RuntimeException('A password is required when changing this link to controlled access.', 422);
}

$conn->begin_transaction();
try {
    dbExecute(
        $conn,
        'UPDATE document_share_links
         SET link_name = ?, access_mode = ?, password_hash = ?, allow_download = ?, expires_at = ?,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = "active"',
        'sssisi',
        [$linkName, $accessMode, $passwordHash, $allowDownload ? 1 : 0, $expiresAt, $shareId]
    )->close();

    // Settings and password changes invalidate previous controlled-access sessions.
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

$updated = fetchDocumentShareById($conn, $shareId);
if (!$updated) {
    throw new RuntimeException('The updated document link could not be loaded.', 500);
}

writeAuditLog($conn, $authUser, 'document.share_updated', 'document', (int) $share['document_id'], [
    'share_id' => $shareId,
    'access_mode' => $accessMode,
    'allow_download' => $allowDownload,
    'expires_at' => $expiresAt,
    'password_changed' => $passwordProvided,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Document link settings updated successfully.',
    'data' => documentShareAdminPayload($conn, $updated),
]);
