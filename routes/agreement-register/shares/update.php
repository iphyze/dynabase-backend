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
    throw new RuntimeException('Revoked agreement workspace links cannot be updated.', 409);
}

$accessMode = normaliseAgreementExternalAccessMode($payload['access_mode'] ?? $share['access_mode']);
$linkName = normaliseAgreementExternalLinkName($payload['link_name'] ?? $share['link_name']);
$editableFields = array_key_exists('editable_fields', $payload)
    ? normaliseAgreementExternalEditableFields($payload['editable_fields'])
    : normaliseAgreementExternalEditableFields($share['editable_fields']);
$allowDownload = array_key_exists('allow_document_download', $payload)
    ? documentFormBoolean($payload['allow_document_download'], false)
    : (bool) $share['allow_document_download'];
$allowUpload = array_key_exists('allow_document_upload', $payload)
    ? documentFormBoolean($payload['allow_document_upload'], false)
    : (bool) $share['allow_document_upload'];
$expiresAt = array_key_exists('expires_at', $payload)
    ? normaliseAgreementExternalExpiry($payload['expires_at'])
    : (string) $share['expires_at'];

$passwordProvided = array_key_exists('password', $payload) && trim((string) $payload['password']) !== '';
if ($accessMode === 'open') {
    $passwordHash = null;
} elseif ($passwordProvided) {
    $passwordHash = normaliseAgreementExternalPassword($payload['password'], true);
} elseif (($share['access_mode'] ?? '') === 'controlled' && !empty($share['password_hash'])) {
    $passwordHash = (string) $share['password_hash'];
} else {
    throw new RuntimeException('A password is required when changing this link to protected access.', 422);
}

$fieldsJson = json_encode($editableFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($fieldsJson === false) {
    throw new RuntimeException('Unable to save the selected client-editable fields.', 500);
}

$conn->begin_transaction();
try {
    dbExecute(
        $conn,
        'UPDATE agreement_external_links
         SET link_name = ?, access_mode = ?, password_hash = ?, editable_fields = ?,
             allow_document_download = ?, allow_document_upload = ?, expires_at = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = "active"',
        'ssssiisi',
        [$linkName, $accessMode, $passwordHash, $fieldsJson, $allowDownload ? 1 : 0, $allowUpload ? 1 : 0, $expiresAt, $shareId]
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

$updated = fetchAgreementExternalShareById($conn, $shareId);
if (!$updated) {
    throw new RuntimeException('The updated agreement workspace link could not be loaded.', 500);
}
writeAuditLog($conn, $authUser, 'agreement_register.external_link_updated', 'agreement_register', (int) $share['agreement_id'], [
    'share_id' => $shareId,
    'access_mode' => $accessMode,
    'editable_fields' => $editableFields,
    'allow_document_download' => $allowDownload,
    'allow_document_upload' => $allowUpload,
    'expires_at' => $expiresAt,
    'password_changed' => $passwordProvided,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Agreement workspace link updated successfully.',
    'data' => agreementExternalAdminPayload($updated),
]);
