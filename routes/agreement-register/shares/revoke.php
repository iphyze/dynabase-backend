<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/agreementExternal.php';

requireMethod('POST');
$authUser = authenticateUser();
assertAgreementExternalSchema($conn);
$payload = readJsonBody();

$shareId = (int) ($payload['share_id'] ?? $payload['id'] ?? 0);
if ($shareId <= 0) {
    throw new RuntimeException('Agreement workspace link ID is required.', 422);
}
$share = assertAgreementExternalAdminAccessible($conn, $authUser, $shareId);
if (($share['status'] ?? '') === 'revoked') {
    jsonResponse(['status' => 'Success', 'message' => 'Agreement workspace link is already revoked.', 'data' => agreementExternalAdminPayload($share)]);
}

$conn->begin_transaction();
try {
    dbExecute(
        $conn,
        'UPDATE agreement_external_links
         SET status = "revoked", revoked_at = CURRENT_TIMESTAMP, revoked_by_id = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = "active"',
        'ii',
        [(int) $authUser['id'], $shareId]
    )->close();
    dbExecute(
        $conn,
        'UPDATE agreement_external_sessions SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP)
         WHERE share_link_id = ? AND revoked_at IS NULL',
        'i',
        [$shareId]
    )->close();
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

$revoked = fetchAgreementExternalShareById($conn, $shareId);
writeAuditLog($conn, $authUser, 'agreement_register.external_link_revoked', 'agreement_register', (int) $share['agreement_id'], ['share_id' => $shareId]);
jsonResponse([
    'status' => 'Success',
    'message' => 'Agreement workspace link revoked successfully.',
    'data' => $revoked ? agreementExternalAdminPayload($revoked) : null,
]);
