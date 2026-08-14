<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/agreements.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/permissions.php';

requireMethod('POST');
$authUser = authenticateUser();
assertDocumentRevisionSchema($conn);

$agreementId = (int) ($_POST['id'] ?? $_POST['agreement_id'] ?? 0);
if ($agreementId <= 0) {
    throw new RuntimeException('Agreement record ID is required.', 422);
}
$agreement = assertAgreementAccessible($conn, $authUser, $agreementId);
$canEditAgreement = userHasPermission($conn, $authUser, 'agreement_register.edit');
$canAttachOwnCreatedAgreement = userHasPermission($conn, $authUser, 'agreement_register.create')
    && (int) ($agreement['created_by_id'] ?? 0) === (int) $authUser['id'];
$canAttachOwnRenewal = userHasPermission($conn, $authUser, 'agreement_register.renew')
    && (int) ($agreement['created_by_id'] ?? 0) === (int) $authUser['id']
    && !empty($agreement['renewed_from_id']);
if (!$canEditAgreement && !$canAttachOwnCreatedAgreement && !$canAttachOwnRenewal) {
    throw new RuntimeException('You are not authorised to attach a document to this agreement.', 403);
}
if (!documentUploadWasProvided()) {
    throw new RuntimeException('Please select an agreement document to upload.', 422);
}

$file = storeDocumentUpload($_FILES['document']);
$actorId = (int) $authUser['id'];
$actorEmail = actorEmail($authUser);
$documentId = (int) ($agreement['linked_document_id'] ?? 0);
$revision = null;

$conn->begin_transaction();
try {
    if ($documentId > 0) {
        assertDocumentAccessible($conn, $authUser, $documentId);
        lockDocumentForRevision($conn, $documentId);
        $nextRevisionNo = nextDocumentRevisionNo($conn, $documentId);
        $revisionCode = normaliseDocumentRevisionCode('', $nextRevisionNo);
        $revision = insertDocumentRevisionRecord(
            $conn,
            $documentId,
            $revisionCode,
            'Uploaded from Agreement Register ' . (string) $agreement['document_ref_no'],
            $file,
            $authUser,
            true
        );
    } else {
        $title = (string) $agreement['document_ref_no'] . ' — ' . (string) $agreement['client_company'];
        $relationshipType = !empty($agreement['client_id']) ? 'client' : 'general';
        $clientId = !empty($agreement['client_id']) ? (int) $agreement['client_id'] : null;
        $keypersonId = !empty($agreement['counterparty_keyperson_id']) ? (int) $agreement['counterparty_keyperson_id'] : null;
        $category = (string) $agreement['document_ref_type'] . ' Agreement';
        $description = 'Agreement document linked to ' . (string) $agreement['document_ref_no'] . '.';

        $stmt = dbExecute(
            $conn,
            'INSERT INTO document_table
                (document_title, document_type, presentation_code, document_category, updated_content, document,
                 created_by, updated_by, description, relationship_type, project_code, client_id, keyperson_id,
                 original_name, storage_path, mime_type, file_extension, file_size, checksum_sha256, version_no,
                 status, created_by_id, updated_by_id)
             VALUES (?, "Agreement", ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 1, "active", ?, ?)',
            'sssssssssiissssisii',
            [
                $title,
                (string) $agreement['document_ref_no'],
                $category,
                'Initial agreement upload',
                $file['stored_name'],
                $actorEmail,
                $actorEmail,
                $description,
                $relationshipType,
                $clientId,
                $keypersonId,
                $file['original_name'],
                $file['storage_path'],
                $file['mime_type'],
                $file['file_extension'],
                $file['file_size'],
                $file['checksum_sha256'],
                $actorId,
                $actorId,
            ]
        );
        $documentId = (int) $stmt->insert_id;
        $stmt->close();

        lockDocumentForRevision($conn, $documentId);
        $revision = insertDocumentRevisionRecord(
            $conn,
            $documentId,
            normaliseDocumentRevisionCode('', 1),
            'Initial agreement upload',
            $file,
            $authUser,
            true
        );

    }

    dbExecute(
        $conn,
        'UPDATE agreement_registers SET linked_document_id = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
        'isii',
        [$documentId, $actorEmail, $actorId, $agreementId]
    )->close();

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    removeManagedDocumentFile($file['absolute_path']);
    throw $exception;
}

writeAuditLog($conn, $authUser, 'agreement_register.document_attached', 'agreement_register', $agreementId, [
    'document_ref_no' => $agreement['document_ref_no'],
    'document_id' => $documentId,
    'revision_id' => $revision['id'] ?? null,
    'revision_code' => $revision['revision_code'] ?? null,
    'original_name' => $file['original_name'],
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Agreement document attached successfully.',
    'data' => assertAgreementAccessible($conn, $authUser, $agreementId),
], 201);
