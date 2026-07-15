<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/documentShares.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN], 'Only Super Admins and Admins can delete documents.');
assertDocumentRevisionSchema($conn);
assertDocumentShareSchema($conn);
$payload = readJsonBody();
$id = (int) ($payload['id'] ?? 0);
if ($id <= 0) {
    throw new RuntimeException('Document ID is required.', 422);
}

$document = assertDocumentAccessible($conn, $authUser, $id);
$shareRevocation = ['links_revoked' => 0, 'sessions_revoked' => 0];

$conn->begin_transaction();
try {
    $locked = dbFetchOne(
        $conn,
        "SELECT id FROM document_table WHERE id = ? AND status = 'active' LIMIT 1 FOR UPDATE",
        'i',
        [$id]
    );
    if (!$locked) {
        throw new RuntimeException('Document not found or already deleted.', 404);
    }

    dbExecute(
        $conn,
        "UPDATE document_table
         SET status = 'deleted', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = 'active'",
        'sii',
        [actorEmail($authUser), (int) $authUser['id'], $id]
    )->close();

    $shareRevocation = revokeDocumentSharesForDocumentIds($conn, [$id], (int) $authUser['id']);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

writeAuditLog($conn, $authUser, 'document.deleted', 'document', $id, [
    'title' => $document['document_title'],
    'original_name' => $document['original_name'] ?? $document['document'],
    'share_links_revoked' => $shareRevocation['links_revoked'],
    'share_sessions_revoked' => $shareRevocation['sessions_revoked'],
    'files_retained_for_audit' => true,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Document deleted successfully.',
    'data' => [
        'id' => $id,
        'share_links_revoked' => $shareRevocation['links_revoked'],
        'files_retained_for_audit' => true,
    ],
]);
