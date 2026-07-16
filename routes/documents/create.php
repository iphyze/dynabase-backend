<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
assertDocumentRevisionSchema($conn);

$form = documentFormPayload($conn, $_POST);
assertDocumentTitleAvailable($conn, $form['document_title'], $form['document_type']);
if (!documentUploadWasProvided()) {
    throw new RuntimeException('Please select a document to upload.', 422);
}

$revisionCode = normaliseDocumentRevisionCode($_POST['revision_code'] ?? '', 1);
$revisionNotes = normaliseDocumentRevisionNotes($_POST['revision_notes'] ?? $form['updated_content']);
$file = storeDocumentUpload($_FILES['document']);
$actorId = (int) $authUser['id'];
$actorEmail = actorEmail($authUser);
$documentId = 0;
$revision = null;

$conn->begin_transaction();
try {
    $stmt = dbExecute(
        $conn,
        'INSERT INTO document_table
            (document_title, document_type, presentation_code, document_category, updated_content, document,
             created_by, updated_by, description, relationship_type, project_code, client_id, keyperson_id,
             original_name, storage_path, mime_type, file_extension, file_size, checksum_sha256, version_no,
             status, created_by_id, updated_by_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active", ?, ?)',
        'ssssssssssiiissssisiii',
        [
            $form['document_title'], $form['document_type'], $form['presentation_code'], $form['document_category'],
            $revisionNotes, $file['stored_name'], $actorEmail, $actorEmail, $form['description'],
            $form['relationship_type'], $form['project_code'], $form['client_id'], $form['keyperson_id'],
            $file['original_name'], $file['storage_path'], $file['mime_type'], $file['file_extension'],
            $file['file_size'], $file['checksum_sha256'], 1, $actorId, $actorId,
        ]
    );
    $documentId = (int) $stmt->insert_id;
    $stmt->close();

    lockDocumentForRevision($conn, $documentId);
    $revision = insertDocumentRevisionRecord(
        $conn,
        $documentId,
        $revisionCode,
        $revisionNotes,
        $file,
        $authUser,
        true
    );

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    removeManagedDocumentFile($file['absolute_path']);
    throw $exception;
}

writeAuditLog($conn, $authUser, 'document.created', 'document', $documentId, [
    'title' => $form['document_title'],
    'document_type' => $form['document_type'],
    'category' => $form['document_category'],
    'relationship_type' => $form['relationship_type'],
    'relationship_label' => $form['relationship_label'],
    'revision_id' => $revision['id'] ?? null,
    'revision_code' => $revisionCode,
    'original_name' => $file['original_name'],
    'file_size' => $file['file_size'],
]);

$document = assertDocumentAccessible($conn, $authUser, $documentId);
jsonResponse([
    'status' => 'Success',
    'message' => 'Document and initial revision uploaded successfully.',
    'data' => documentDetailResponsePayload($conn, $document),
], 201);
