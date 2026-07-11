<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN], 'Only Super Admins and Admins can delete documents.');
$payload = readJsonBody();
$id = (int) ($payload['id'] ?? 0);
if ($id <= 0) {
    throw new RuntimeException('Document ID is required.', 422);
}

$document = assertDocumentAccessible($conn, $authUser, $id);
dbExecute(
    $conn,
    "UPDATE document_table SET status = 'deleted', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
    'sii',
    [actorEmail($authUser), (int) $authUser['id'], $id]
)->close();

writeAuditLog($conn, $authUser, 'document.deleted', 'document', $id, [
    'title' => $document['document_title'],
    'original_name' => $document['original_name'] ?? $document['document'],
]);

jsonResponse(['status' => 'Success', 'message' => 'Document deleted successfully.']);
