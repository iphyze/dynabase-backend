<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/request.php';
require_once __DIR__ . '/../../../includes/documentShares.php';

requireMethod('GET');
$authUser = authenticateUser();
assertDocumentRevisionSchema($conn);
assertDocumentShareSchema($conn);
cleanupDocumentShareSecurityArtifacts($conn, true);

$documentId = (int) ($_GET['document_id'] ?? $_GET['id'] ?? 0);
if ($documentId <= 0) {
    throw new RuntimeException('Document ID is required.', 422);
}
assertDocumentAccessible($conn, $authUser, $documentId);

$rows = dbFetchAll(
    $conn,
    "SELECT s.*,
            CONCAT_WS(' ', creator.first_name, creator.last_name) AS creator_name,
            creator.email AS creator_email,
            CONCAT_WS(' ', revoker.first_name, revoker.last_name) AS revoker_name,
            fixed_revision.revision_code AS fixed_revision_code,
            fixed_revision.original_name AS fixed_original_name,
            fixed_revision.record_status AS fixed_revision_status
     FROM document_share_links s
     LEFT JOIN users creator ON creator.id = s.created_by_id
     LEFT JOIN users revoker ON revoker.id = s.revoked_by_id
     LEFT JOIN document_revisions fixed_revision ON fixed_revision.id = s.revision_id
     WHERE s.document_id = ?
     ORDER BY s.created_at DESC, s.id DESC
     LIMIT 250",
    'i',
    [$documentId]
);
$items = array_map(
    static fn (array $row): array => documentShareAdminPayload($conn, $row),
    $rows
);

jsonResponse([
    'status' => 'Success',
    'message' => 'Document share links retrieved successfully.',
    'data' => [
        'items' => $items,
        'links' => $items,
        'summary' => documentShareSummaryForDocument($conn, $documentId),
    ],
]);
