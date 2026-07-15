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
$share = assertPublicDocumentShareAvailable($conn, $rawToken);

if (($share['access_mode'] ?? '') === 'controlled' && $sessionToken === '') {
    jsonResponse([
        'status' => 'Success',
        'message' => 'This document link requires controlled access.',
        'data' => [
            'locked' => true,
            'document' => [
                'title' => $share['document_title'],
                'type' => $share['document_type'],
            ],
            'share' => [
                'link_name' => $share['link_name'] ?? '',
                'access_mode' => 'controlled',
                'requires_password' => true,
                'allow_download' => (bool) $share['allow_download'],
                'expires_at' => $share['expires_at'],
            ],
        ],
    ]);
}

assertDocumentShareAccess($conn, $share, $sessionToken);
$revision = resolveDocumentShareTargetRevision($conn, $share);
touchDocumentShareAccess($conn, (int) $share['id']);
writeAuditLog($conn, null, 'document.share_accessed', 'document', (int) $share['document_id'], [
    'share_id' => (int) $share['id'],
    'revision_id' => (int) $revision['id'],
    'revision_code' => $revision['revision_code'],
    'access_mode' => $share['access_mode'],
    'ip_hash' => documentShareIpHash(),
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Shared document retrieved successfully.',
    'data' => ['locked' => false] + documentSharePublicPayload($conn, $share, $revision),
]);
