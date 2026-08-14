<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/agreementExternal.php';

requireMethod('POST');
enforceTrustedOrigin();
assertAgreementExternalSchema($conn);
$payload = readJsonBody();
$rawToken = cleanString($payload['token'] ?? '');
$password = (string) ($payload['password'] ?? '');
$share = assertPublicAgreementExternalAvailable($conn, $rawToken, true);

if (($share['access_mode'] ?? '') !== 'controlled') {
    touchAgreementExternalLink($conn, (int) $share['id']);
    jsonResponse([
        'status' => 'Success',
        'message' => 'This agreement workspace is open.',
        'data' => ['session' => null, 'locked' => false] + agreementExternalPublicPayload($conn, $share),
    ]);
}

assertAgreementExternalPasswordRateLimit($conn, (int) $share['id']);
if ($password === '' || empty($share['password_hash']) || !password_verify($password, (string) $share['password_hash'])) {
    recordAgreementExternalPasswordAttempt($conn, $share, 'denied');
    throw new RuntimeException('The agreement-link password is incorrect.', 401);
}

$conn->begin_transaction();
try {
    recordAgreementExternalPasswordAttempt($conn, $share, 'granted');
    $session = createAgreementExternalSession($conn, $share);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}
touchAgreementExternalLink($conn, (int) $share['id']);
writeAuditLog($conn, null, 'agreement_register.external_workspace_unlocked', 'agreement_register', (int) $share['agreement_id'], [
    'share_id' => (int) $share['id'],
    'ip_hash' => agreementExternalIpHash(),
]);
jsonResponse([
    'status' => 'Success',
    'message' => 'Agreement workspace access granted.',
    'data' => [
        'session' => ['token' => $session['token'], 'expires_at' => $session['expires_at']],
        'locked' => false,
    ] + agreementExternalPublicPayload($conn, $share),
]);
