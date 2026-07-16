<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
assertDocumentRevisionSchema($conn);

$documentId = (int) ($_POST['document_id'] ?? $_POST['id'] ?? 0);
$revisionId = (int) ($_POST['revision_id'] ?? 0);
if ($documentId <= 0 || $revisionId <= 0) {
    throw new RuntimeException('Document ID and revision ID are required.', 422);
}
$document = assertDocumentAccessible($conn, $authUser, $documentId);
$existingRevision = assertDocumentRevisionAccessible($conn, $authUser, $documentId, $revisionId, false);
if (!documentUploadWasProvided()) {
    throw new RuntimeException('Please select the replacement file.', 422);
}

$revisionNotes = array_key_exists('revision_notes', $_POST)
    ? normaliseDocumentRevisionNotes($_POST['revision_notes'])
    : (string) ($existingRevision['revision_notes'] ?? '');
$file = storeDocumentUpload($_FILES['document']);
$newRevision = null;

$conn->begin_transaction();
try {
    lockDocumentForRevision($conn, $documentId);
    $lockedRevision = dbFetchOne(
        $conn,
        "SELECT * FROM document_revisions
         WHERE id = ? AND document_id = ? AND record_status = 'active'
         LIMIT 1 FOR UPDATE",
        'ii',
        [$revisionId, $documentId]
    );
    if (!$lockedRevision) {
        throw new RuntimeException('The selected active revision is no longer available.', 409);
    }

    $wasCurrent = (bool) $lockedRevision['is_current'];
    dbExecute(
        $conn,
        "UPDATE document_revisions
         SET is_current = 0, record_status = 'replaced', replaced_at = CURRENT_TIMESTAMP
         WHERE id = ? AND record_status = 'active'",
        'i',
        [$revisionId]
    )->close();

    $newRevision = insertDocumentRevisionRecord(
        $conn,
        $documentId,
        (string) $lockedRevision['revision_code'],
        $revisionNotes,
        $file,
        $authUser,
        $wasCurrent,
        $revisionId
    );
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    removeManagedDocumentFile($file['absolute_path']);
    throw $exception;
}

writeAuditLog($conn, $authUser, 'document.revision_replaced', 'document', $documentId, [
    'title' => $document['document_title'],
    'replaced_revision_id' => $revisionId,
    'revision_id' => $newRevision['id'],
    'revision_code' => $newRevision['revision_code'],
    'is_current' => (bool) $newRevision['is_current'],
    'old_original_name' => $existingRevision['original_name'],
    'new_original_name' => $newRevision['original_name'],
]);

$updatedDocument = assertDocumentAccessible($conn, $authUser, $documentId);
jsonResponse([
    'status' => 'Success',
    'message' => 'Revision file replaced successfully. The previous file remains in the revision history.',
    'data' => [
        'revision' => documentRevisionResponsePayload($newRevision),
        'document' => documentDetailResponsePayload($conn, $updatedDocument),
    ],
]);
