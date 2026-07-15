<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN], 'Only Super Admins and Admins can update documents.');
assertDocumentRevisionSchema($conn);

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    throw new RuntimeException('Document ID is required.', 422);
}
$existing = assertDocumentAccessible($conn, $authUser, $id);
$form = documentFormPayload($conn, $_POST);
assertDocumentTitleAvailable($conn, $form['document_title'], $form['document_type'], $id);

$replacement = null;
if (documentUploadWasProvided()) {
    requirePermission(
        $conn,
        $authUser,
        'documents.revisions',
        'You are not authorised to replace document revision files.'
    );
    $replacement = storeDocumentUpload($_FILES['document']);
}
$revisionNotes = normaliseDocumentRevisionNotes($_POST['revision_notes'] ?? $form['updated_content']);
$actorId = (int) $authUser['id'];
$actorEmail = actorEmail($authUser);
$newRevision = null;
$replacedRevision = null;

$conn->begin_transaction();
try {
    lockDocumentForRevision($conn, $id);

    dbExecute(
        $conn,
        'UPDATE document_table
         SET document_title = ?, document_type = ?, presentation_code = ?, document_category = ?,
             updated_content = ?, description = ?, relationship_type = ?, project_code = ?, client_id = ?,
             keyperson_id = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = "active"',
        'sssssssiiisii',
        [
            $form['document_title'],
            $form['document_type'],
            $form['presentation_code'],
            $form['document_category'],
            $revisionNotes,
            $form['description'],
            $form['relationship_type'],
            $form['project_code'],
            $form['client_id'],
            $form['keyperson_id'],
            $actorEmail,
            $actorId,
            $id,
        ]
    )->close();

    $currentRevision = dbFetchOne(
        $conn,
        "SELECT * FROM document_revisions
         WHERE document_id = ? AND record_status = 'active' AND is_current = 1
         ORDER BY id DESC LIMIT 1 FOR UPDATE",
        'i',
        [$id]
    );
    if (!$currentRevision) {
        throw new RuntimeException('The document has no active current revision. Reapply the Documents revision migration.', 409);
    }

    if ($replacement !== null) {
        $replacedRevision = $currentRevision;
        dbExecute(
            $conn,
            "UPDATE document_revisions
             SET is_current = 0, record_status = 'replaced', replaced_at = CURRENT_TIMESTAMP
             WHERE id = ? AND record_status = 'active'",
            'i',
            [(int) $currentRevision['id']]
        )->close();

        $newRevision = insertDocumentRevisionRecord(
            $conn,
            $id,
            (string) $currentRevision['revision_code'],
            $revisionNotes,
            $replacement,
            $authUser,
            true,
            (int) $currentRevision['id']
        );
    } else {
        dbExecute(
            $conn,
            "UPDATE document_revisions SET revision_notes = ? WHERE id = ? AND record_status = 'active'",
            'si',
            [$revisionNotes, (int) $currentRevision['id']]
        )->close();
    }

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    if ($replacement !== null) {
        removeManagedDocumentFile($replacement['absolute_path']);
    }
    throw $exception;
}

writeAuditLog(
    $conn,
    $authUser,
    $replacement !== null ? 'document.revision_replaced' : 'document.updated',
    'document',
    $id,
    [
        'title' => $form['document_title'],
        'document_type' => $form['document_type'],
        'category' => $form['document_category'],
        'relationship_type' => $form['relationship_type'],
        'relationship_label' => $form['relationship_label'],
        'file_replaced' => $replacement !== null,
        'replaced_revision_id' => $replacedRevision['id'] ?? null,
        'revision_id' => $newRevision['id'] ?? ($existing['current_revision_id'] ?? null),
        'revision_code' => $newRevision['revision_code'] ?? ($existing['current_revision_code'] ?? null),
        'new_original_name' => $replacement['original_name'] ?? null,
    ]
);

$document = assertDocumentAccessible($conn, $authUser, $id);
jsonResponse([
    'status' => 'Success',
    'message' => $replacement !== null
        ? 'Document details and current revision file replaced successfully.'
        : 'Document details updated successfully.',
    'data' => documentDetailResponsePayload($conn, $document),
]);
