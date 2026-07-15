<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN], 'Only Super Admins and Admins can add document revisions.');
assertDocumentRevisionSchema($conn);

$documentId = (int) ($_POST['id'] ?? $_POST['document_id'] ?? 0);
if ($documentId <= 0) {
    throw new RuntimeException('Document ID is required.', 422);
}
$document = assertDocumentAccessible($conn, $authUser, $documentId);
if (!documentUploadWasProvided()) {
    throw new RuntimeException('Please select a revision file to upload.', 422);
}

$revisionNotes = normaliseDocumentRevisionNotes($_POST['revision_notes'] ?? '');
$makeCurrent = documentFormBoolean($_POST['make_current'] ?? null, true);
$file = storeDocumentUpload($_FILES['document']);
$revision = null;

$conn->begin_transaction();
try {
    lockDocumentForRevision($conn, $documentId);
    $nextRevisionNo = nextDocumentRevisionNo($conn, $documentId);
    $revisionCode = normaliseDocumentRevisionCode($_POST['revision_code'] ?? '', $nextRevisionNo);
    assertDocumentRevisionCodeAvailable($conn, $documentId, $revisionCode);

    $revision = insertDocumentRevisionRecord(
        $conn,
        $documentId,
        $revisionCode,
        $revisionNotes,
        $file,
        $authUser,
        $makeCurrent
    );
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    removeManagedDocumentFile($file['absolute_path']);
    throw $exception;
}

writeAuditLog($conn, $authUser, 'document.revision_added', 'document', $documentId, [
    'title' => $document['document_title'],
    'revision_id' => $revision['id'],
    'revision_code' => $revision['revision_code'],
    'is_current' => (bool) $revision['is_current'],
    'original_name' => $revision['original_name'],
    'file_size' => (int) $revision['file_size'],
]);

$updatedDocument = assertDocumentAccessible($conn, $authUser, $documentId);
jsonResponse([
    'status' => 'Success',
    'message' => $makeCurrent
        ? 'New document revision uploaded and marked as current.'
        : 'New document revision uploaded successfully.',
    'data' => [
        'revision' => documentRevisionResponsePayload($revision),
        'document' => documentDetailResponsePayload($conn, $updatedDocument),
    ],
], 201);
