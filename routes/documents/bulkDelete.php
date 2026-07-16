<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/documentShares.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
assertDocumentRevisionSchema($conn);
assertDocumentShareSchema($conn);
$ids = parseDocumentIds(readJsonBody());
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$idTypes = str_repeat('i', count($ids));

$available = dbFetchAll(
    $conn,
    "SELECT id, document_title
     FROM document_table
     WHERE id IN ({$placeholders}) AND status = 'active'
     ORDER BY id ASC",
    $idTypes,
    $ids
);
if ($available === []) {
    throw new RuntimeException('No selected documents are available for deletion.', 404);
}

$availableIds = array_map(static fn (array $row): int => (int) $row['id'], $available);
$availablePlaceholders = implode(',', array_fill(0, count($availableIds), '?'));
$availableTypes = str_repeat('i', count($availableIds));
$shareRevocation = ['links_revoked' => 0, 'sessions_revoked' => 0];

$conn->begin_transaction();
try {
    $lockedRows = dbFetchAll(
        $conn,
        "SELECT id
         FROM document_table
         WHERE id IN ({$availablePlaceholders}) AND status = 'active'
         ORDER BY id ASC FOR UPDATE",
        $availableTypes,
        $availableIds
    );
    $lockedIds = array_map(static fn (array $row): int => (int) $row['id'], $lockedRows);
    if (count($lockedIds) !== count($availableIds)) {
        throw new RuntimeException('One or more selected documents changed before deletion. Refresh and try again.', 409);
    }

    dbExecute(
        $conn,
        "UPDATE document_table
         SET status = 'deleted', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id IN ({$availablePlaceholders}) AND status = 'active'",
        'si' . $availableTypes,
        array_merge([actorEmail($authUser), (int) $authUser['id']], $availableIds)
    )->close();

    $shareRevocation = revokeDocumentSharesForDocumentIds($conn, $availableIds, (int) $authUser['id']);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

writeAuditLog($conn, $authUser, 'document.bulk_deleted', 'document', null, [
    'requested_ids' => $ids,
    'deleted_ids' => $availableIds,
    'deleted_count' => count($availableIds),
    'share_links_revoked' => $shareRevocation['links_revoked'],
    'share_sessions_revoked' => $shareRevocation['sessions_revoked'],
    'files_retained_for_audit' => true,
]);

jsonResponse([
    'status' => 'Success',
    'message' => count($availableIds) . ' document(s) deleted successfully.',
    'data' => [
        'deleted_ids' => $availableIds,
        'deleted_count' => count($availableIds),
        'share_links_revoked' => $shareRevocation['links_revoked'],
        'files_retained_for_audit' => true,
    ],
]);
