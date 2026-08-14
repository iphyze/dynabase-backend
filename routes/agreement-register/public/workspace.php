<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/agreementExternal.php';

requireMethod('GET');
assertAgreementExternalSchema($conn);
$rawToken = cleanString($_GET['token'] ?? '');
$sessionToken = cleanString($_GET['session_token'] ?? '');
$share = assertPublicAgreementExternalAvailable($conn, $rawToken);

if (($share['access_mode'] ?? '') === 'controlled' && $sessionToken === '') {
    jsonResponse([
        'status' => 'Success',
        'message' => 'This agreement workspace requires protected access.',
        'data' => [
            'locked' => true,
            'agreement' => [
                'reference' => $share['document_ref_no'],
                'type' => $share['document_ref_type'],
            ],
            'share' => [
                'link_name' => $share['link_name'] ?? '',
                'access_mode' => 'controlled',
                'requires_password' => true,
                'expires_at' => $share['expires_at'],
            ],
        ],
    ]);
}

assertAgreementExternalAccess($conn, $share, $sessionToken);
touchAgreementExternalLink($conn, (int) $share['id']);
writeAuditLog($conn, null, 'agreement_register.external_workspace_accessed', 'agreement_register', (int) $share['agreement_id'], [
    'share_id' => (int) $share['id'],
    'ip_hash' => agreementExternalIpHash(),
]);
jsonResponse([
    'status' => 'Success',
    'message' => 'Agreement workspace loaded successfully.',
    'data' => ['locked' => false] + agreementExternalPublicPayload($conn, $share),
]);
