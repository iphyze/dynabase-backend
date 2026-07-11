<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN], 'Only Super Admins and Admins can update documents.');

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    throw new RuntimeException('Document ID is required.', 422);
}
$existing = assertDocumentAccessible($conn, $authUser, $id);
$form = documentFormPayload($conn, $_POST);
assertDocumentTitleAvailable($conn, $form['document_title'], $form['document_type'], $id);

$replacement = null;
$oldAbsolutePath = resolveDocumentAbsolutePath($existing);
if (documentUploadWasProvided()) {
    $replacement = storeDocumentUpload($_FILES['document']);
}

$actorId = (int) $authUser['id'];
$actorEmail = actorEmail($authUser);
$set = [
    'document_title = ?', 'document_type = ?', 'presentation_code = ?', 'document_category = ?',
    'updated_content = ?', 'description = ?', 'relationship_type = ?', 'project_code = ?',
    'client_id = ?', 'keyperson_id = ?', 'updated_by = ?', 'updated_by_id = ?', 'updated_at = CURRENT_TIMESTAMP',
];
$types = 'sssssssiiisi';
$params = [
    $form['document_title'], $form['document_type'], $form['presentation_code'], $form['document_category'],
    $form['updated_content'], $form['description'], $form['relationship_type'], $form['project_code'],
    $form['client_id'], $form['keyperson_id'], $actorEmail, $actorId,
];

if ($replacement !== null) {
    array_push(
        $set,
        'document = ?', 'original_name = ?', 'storage_path = ?', 'mime_type = ?', 'file_extension = ?',
        'file_size = ?', 'checksum_sha256 = ?', 'version_no = version_no + 1', 'replaced_at = CURRENT_TIMESTAMP'
    );
    $types .= 'sssssis';
    array_push(
        $params,
        $replacement['stored_name'], $replacement['original_name'], $replacement['storage_path'],
        $replacement['mime_type'], $replacement['file_extension'], $replacement['file_size'],
        $replacement['checksum_sha256']
    );
}
$types .= 'i';
$params[] = $id;

try {
    dbExecute($conn, 'UPDATE document_table SET ' . implode(', ', $set) . ' WHERE id = ? AND status = "active"', $types, $params)->close();
} catch (Throwable $exception) {
    if ($replacement !== null) {
        removeManagedDocumentFile($replacement['absolute_path']);
    }
    throw $exception;
}

if ($replacement !== null && $oldAbsolutePath !== null && !str_starts_with((string) ($existing['storage_path'] ?? ''), 'legacy/')) {
    removeManagedDocumentFile($oldAbsolutePath);
}

writeAuditLog($conn, $authUser, $replacement !== null ? 'document.replaced' : 'document.updated', 'document', $id, [
    'title' => $form['document_title'],
    'document_type' => $form['document_type'],
    'category' => $form['document_category'],
    'relationship_type' => $form['relationship_type'],
    'relationship_label' => $form['relationship_label'],
    'file_replaced' => $replacement !== null,
    'new_original_name' => $replacement['original_name'] ?? null,
]);

$document = assertDocumentAccessible($conn, $authUser, $id);
jsonResponse([
    'status' => 'Success',
    'message' => $replacement !== null ? 'Document details and file replaced successfully.' : 'Document details updated successfully.',
    'data' => documentResponsePayload($document),
]);
