<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN], 'Only Super Admins and Admins can change the current document revision.');
assertDocumentRevisionSchema($conn);

$payload = readJsonBody();
$documentId = (int) ($payload['document_id'] ?? $payload['id'] ?? 0);
$revisionId = (int) ($payload['revision_id'] ?? 0);
if ($documentId <= 0 || $revisionId <= 0) {
    throw new RuntimeException('Document ID and revision ID are required.', 422);
}
$document = assertDocumentAccessible($conn, $authUser, $documentId);
assertDocumentRevisionAccessible($conn, $authUser, $documentId, $revisionId, false);

$conn->begin_transaction();
try {
    lockDocumentForRevision($conn, $documentId);
    $revision = dbFetchOne(
        $conn,
        "SELECT * FROM document_revisions
         WHERE id = ? AND document_id = ? AND record_status = 'active'
         LIMIT 1 FOR UPDATE",
        'ii',
        [$revisionId, $documentId]
    );
    if (!$revision) {
        throw new RuntimeException('The selected revision is no longer available.', 409);
    }

    dbExecute(
        $conn,
        "UPDATE document_revisions SET is_current = 0 WHERE document_id = ? AND record_status = 'active'",
        'i',
        [$documentId]
    )->close();
    dbExecute(
        $conn,
        "UPDATE document_revisions SET is_current = 1 WHERE id = ? AND document_id = ? AND record_status = 'active'",
        'ii',
        [$revisionId, $documentId]
    )->close();
    syncDocumentCurrentRevision($conn, $documentId, $revisionId, $authUser);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

writeAuditLog($conn, $authUser, 'document.revision_current', 'document', $documentId, [
    'title' => $document['document_title'],
    'revision_id' => $revisionId,
    'revision_code' => $revision['revision_code'],
]);

$updatedDocument = assertDocumentAccessible($conn, $authUser, $documentId);
jsonResponse([
    'status' => 'Success',
    'message' => 'Current document revision updated successfully.',
    'data' => documentDetailResponsePayload($conn, $updatedDocument),
]);
