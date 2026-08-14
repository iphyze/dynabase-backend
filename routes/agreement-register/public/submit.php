<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/agreementExternal.php';

requireMethod('POST');
enforceTrustedOrigin();
assertAgreementExternalSchema($conn);
$payload = readJsonBody();
$rawToken = cleanString($payload['token'] ?? '');
$sessionToken = cleanString($payload['session_token'] ?? '');
$share = assertPublicAgreementExternalAvailable($conn, $rawToken, true);
assertAgreementExternalAccess($conn, $share, $sessionToken);
$fields = normaliseAgreementExternalSubmittedFields($share, $payload['fields'] ?? null);

$conn->begin_transaction();
try {
    $result = applyAgreementExternalSubmittedFields($conn, $share, $fields);
    $submissionId = recordAgreementExternalSubmission($conn, $share, 'details', $result);
    dbExecute(
        $conn,
        'UPDATE agreement_external_links
         SET submission_count = submission_count + 1, last_submitted_at = CURRENT_TIMESTAMP,
             last_accessed_at = CURRENT_TIMESTAMP, updated_at = updated_at
         WHERE id = ? AND status = "active"',
        'i',
        [(int) $share['id']]
    )->close();
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

writeAuditLog($conn, null, 'agreement_register.external_details_submitted', 'agreement_register', (int) $share['agreement_id'], [
    'share_id' => (int) $share['id'],
    'submission_id' => $submissionId,
    'changed_fields' => array_keys($result['changes']),
    'ip_hash' => agreementExternalIpHash(),
]);
$refreshed = assertPublicAgreementExternalAvailable($conn, $rawToken);
jsonResponse([
    'status' => 'Success',
    'message' => 'Agreement details submitted successfully.',
    'data' => ['locked' => false, 'submission_id' => $submissionId] + agreementExternalPublicPayload($conn, $refreshed),
], 201);
