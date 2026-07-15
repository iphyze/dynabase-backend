<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';

requireMethod('GET');
$authUser = authenticateUser();
assertDocumentRevisionSchema($conn);

$documentId = (int) ($_GET['id'] ?? $_GET['document_id'] ?? 0);
if ($documentId <= 0) {
    throw new RuntimeException('Document ID is required.', 422);
}

$document = assertDocumentAccessible($conn, $authUser, $documentId);
$revisions = array_map('documentRevisionResponsePayload', documentRevisionRows($conn, $documentId));

jsonResponse([
    'status' => 'Success',
    'message' => 'Document revisions retrieved successfully.',
    'data' => [
        'document' => documentResponsePayload($document),
        'items' => $revisions,
        'revision_count' => count(array_filter(
            $revisions,
            static fn (array $revision): bool => $revision['record_status'] === 'active'
        )),
        'upload_count' => count($revisions),
    ],
]);
