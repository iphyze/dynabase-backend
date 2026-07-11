<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';

requireMethod('GET');
$authUser = authenticateUser();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    throw new RuntimeException('Document ID is required.', 422);
}

$document = assertDocumentAccessible($conn, $authUser, $id);
jsonResponse([
    'status' => 'Success',
    'message' => 'Document retrieved successfully.',
    'data' => documentResponsePayload($document),
]);
