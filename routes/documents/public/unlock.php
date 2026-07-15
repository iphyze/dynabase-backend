<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/request.php';
require_once __DIR__ . '/../../../includes/documentShares.php';
require_once __DIR__ . '/../../../includes/audit.php';

requireMethod('POST');
enforceTrustedOrigin();
assertDocumentRevisionSchema($conn);
assertDocumentShareSchema($conn);
$payload = readJsonBody();

$rawToken = cleanString($payload['token'] ?? '');
$password = (string) ($payload['password'] ?? '');
$share = assertPublicDocumentShareAvailable($conn, $rawToken, true);
$revision = resolveDocumentShareTargetRevision($conn, $share);

if (($share['access_mode'] ?? '') !== 'controlled') {
    touchDocumentShareAccess($conn, (int) $share['id']);
    jsonResponse([
        'status' => 'Success',
        'message' => 'This document link is open.',
        'data' => [
            'session' => null,
            'locked' => false,
        ] + documentSharePublicPayload($conn, $share, $revision),
    ]);
}

assertDocumentSharePasswordRateLimit($conn, (int) $share['id']);
if ($password === '' || empty($share['password_hash']) || !password_verify($password, (string) $share['password_hash'])) {
    recordDocumentSharePasswordAttempt($conn, $share, 'denied');
    throw new RuntimeException('The document-link password is incorrect.', 401);
}

$conn->begin_transaction();
try {
    recordDocumentSharePasswordAttempt($conn, $share, 'granted');
    $session = createDocumentShareSession($conn, (int) $share['id']);
    touchDocumentShareAccess($conn, (int) $share['id']);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

writeAuditLog($conn, null, 'document.share_unlocked', 'document', (int) $share['document_id'], [
    'share_id' => (int) $share['id'],
    'revision_id' => (int) $revision['id'],
    'revision_code' => $revision['revision_code'],
    'ip_hash' => documentShareIpHash(),
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Document access granted.',
    'data' => [
        'session' => [
            'token' => $session['token'],
            'expires_at' => $session['expires_at'],
        ],
        'locked' => false,
    ] + documentSharePublicPayload($conn, $share, $revision),
]);
