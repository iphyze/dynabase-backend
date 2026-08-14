<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/agreementExternal.php';

requireMethod('POST');
enforceTrustedOrigin();
assertAgreementExternalSchema($conn);
assertDocumentRevisionSchema($conn);
$rawToken = cleanString($_POST['token'] ?? '');
$sessionToken = cleanString($_POST['session_token'] ?? '');
$share = assertPublicAgreementExternalAvailable($conn, $rawToken, true);
assertAgreementExternalAccess($conn, $share, $sessionToken);
if (!(bool) $share['allow_document_upload']) {
    throw new RuntimeException('Document uploading is disabled for this agreement workspace.', 403);
}
if ((int) ($share['linked_document_id'] ?? 0) <= 0) {
    throw new RuntimeException('No agreement document is attached yet. Ask Lambert to attach the first document before re-uploading.', 409);
}
if (!documentUploadWasProvided()) {
    throw new RuntimeException('Select the signed or updated agreement document to upload.', 422);
}
$file = storeDocumentUpload($_FILES['document']);

$conn->begin_transaction();
try {
    $revision = insertAgreementExternalDocumentRevision($conn, $share, $file);
    dbExecute(
        $conn,
        'UPDATE agreement_registers
         SET updated_by = "external-client-workspace", updated_by_id = NULL, updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND record_status = "active"',
        'i',
        [(int) $share['agreement_id']]
    )->close();
    $submissionId = recordAgreementExternalSubmission(
        $conn,
        $share,
        'document',
        null,
        (int) $revision['id'],
        (string) $file['original_name']
    );
    dbExecute(
        $conn,
        'UPDATE agreement_external_links
         SET upload_count = upload_count + 1, last_submitted_at = CURRENT_TIMESTAMP,
             last_accessed_at = CURRENT_TIMESTAMP, updated_at = updated_at
         WHERE id = ? AND status = "active"',
        'i',
        [(int) $share['id']]
    )->close();
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    removeManagedDocumentFile($file['absolute_path']);
    throw $exception;
}

writeAuditLog($conn, null, 'agreement_register.external_document_uploaded', 'agreement_register', (int) $share['agreement_id'], [
    'share_id' => (int) $share['id'],
    'submission_id' => $submissionId,
    'document_id' => (int) $share['linked_document_id'],
    'revision_id' => (int) $revision['id'],
    'revision_code' => $revision['revision_code'],
    'original_name' => $file['original_name'],
    'ip_hash' => agreementExternalIpHash(),
]);
$refreshed = assertPublicAgreementExternalAvailable($conn, $rawToken);
jsonResponse([
    'status' => 'Success',
    'message' => 'Signed or updated agreement uploaded successfully.',
    'data' => ['locked' => false, 'submission_id' => $submissionId] + agreementExternalPublicPayload($conn, $refreshed),
], 201);
