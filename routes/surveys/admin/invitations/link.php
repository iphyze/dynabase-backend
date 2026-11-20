<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/surveys.php';
require_once __DIR__ . '/../../../../includes/surveyInvitationEmails.php';
require_once __DIR__ . '/../../../../includes/audit.php';

requireMethod('GET');
$authUser = authenticateUser();
$id = requiredIntFromRequest();
$record = dbFetchOne($conn,
    'SELECT token_hash, token_ciphertext FROM client_survey_invitations WHERE id = ? LIMIT 1',
    'i', [$id]
);
if (!$record) throw new RuntimeException('Survey link not found.', 404);
$raw = surveyInvitationDecryptToken($record['token_ciphertext'] ?? null);
if ($raw === null || !hash_equals((string) $record['token_hash'], surveyHash($raw))) {
    throw new RuntimeException('This link cannot be recovered securely. Create a replacement link instead.', 409);
}
writeAuditLog($conn, $authUser, 'client_surveys.invitation_link_revealed', 'client_survey_invitation', $id);
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
jsonResponse([
    'status' => 'Success',
    'message' => 'Secure survey link retrieved.',
    'data' => ['id' => $id, 'link' => surveyPublicFrontendBaseUrl() . '/client-survey/' . rawurlencode($raw)],
]);
