<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN], 'Only Super Admins and Admins can delete documents.');
$ids = parseDocumentIds(readJsonBody());
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$idTypes = str_repeat('i', count($ids));
$available = dbFetchAll(
    $conn,
    "SELECT id, document_title FROM document_table WHERE id IN ({$placeholders}) AND status = 'active'",
    $idTypes,
    $ids
);
if ($available === []) {
    throw new RuntimeException('No selected documents are available for deletion.', 404);
}

$availableIds = array_map(static fn (array $row): int => (int) $row['id'], $available);
$availablePlaceholders = implode(',', array_fill(0, count($availableIds), '?'));
$availableTypes = str_repeat('i', count($availableIds));
dbExecute(
    $conn,
    "UPDATE document_table SET status = 'deleted', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$availablePlaceholders})",
    'si' . $availableTypes,
    array_merge([actorEmail($authUser), (int) $authUser['id']], $availableIds)
)->close();

writeAuditLog($conn, $authUser, 'document.bulk_deleted', 'document', null, [
    'requested_ids' => $ids,
    'deleted_ids' => $availableIds,
    'deleted_count' => count($availableIds),
]);

jsonResponse([
    'status' => 'Success',
    'message' => count($availableIds) . ' document(s) deleted successfully.',
    'data' => ['deleted_ids' => $availableIds, 'deleted_count' => count($availableIds)],
]);
