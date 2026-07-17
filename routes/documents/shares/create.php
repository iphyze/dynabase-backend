<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/request.php';
require_once __DIR__ . '/../../../includes/documentShares.php';
require_once __DIR__ . '/../../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
assertDocumentRevisionSchema($conn);
assertDocumentShareSchema($conn);
$payload = readJsonBody();

$documentId = (int) ($payload['document_id'] ?? 0);
if ($documentId <= 0) {
    throw new RuntimeException('Document ID is required.', 422);
}
$revisionId = isset($payload['revision_id']) && (int) $payload['revision_id'] > 0
    ? (int) $payload['revision_id']
    : null;
$document = assertDocumentAccessible($conn, $authUser, $documentId);
$targetRevision = assertDocumentShareTargetRevision($conn, $authUser, $documentId, $revisionId);
$accessMode = normaliseDocumentShareAccessMode($payload['access_mode'] ?? 'open');
$passwordHash = normaliseDocumentSharePassword($payload['password'] ?? '', $accessMode === 'controlled');
$linkName = normaliseDocumentShareLinkName($payload['link_name'] ?? '');
$allowDownload = documentFormBoolean($payload['allow_download'] ?? true, true);
$deliveryRevision = assertDocumentShareDeliveryOptions($conn, $documentId, $targetRevision, $allowDownload);
$expiresAt = normaliseDocumentShareExpiry($payload['expires_at'] ?? '');
$actorId = (int) $authUser['id'];
$actorEmail = actorEmail($authUser);

$rawToken = '';
$shareId = 0;
$share = null;

$conn->begin_transaction();
try {
    // Lock the parent record so a link cannot be created while deletion is committing.
    $lockedDocument = dbFetchOne(
        $conn,
        "SELECT id FROM document_table WHERE id = ? AND status = 'active' LIMIT 1 FOR UPDATE",
        'i',
        [$documentId]
    );
    if (!$lockedDocument) {
        throw new RuntimeException('Document not found or no longer available.', 404);
    }

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $rawToken = documentShareRandomToken();
        $tokenHash = documentShareHash($rawToken);
        $tokenCiphertext = documentShareEncryptToken($rawToken);
        try {
            $stmt = dbExecute(
                $conn,
                'INSERT INTO document_share_links
                    (document_id, revision_id, token_hash, token_ciphertext, link_name, access_mode, password_hash,
                     allow_download, expires_at, status, created_by_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "active", ?, ?)',
                'iisssssisis',
                [
                    $documentId,
                    $revisionId,
                    $tokenHash,
                    $tokenCiphertext,
                    $linkName,
                    $accessMode,
                    $passwordHash,
                    $allowDownload ? 1 : 0,
                    $expiresAt,
                    $actorId,
                    $actorEmail,
                ]
            );
            $shareId = (int) $stmt->insert_id;
            $stmt->close();
            break;
        } catch (mysqli_sql_exception $exception) {
            if ((int) $exception->getCode() !== 1062 || $attempt === 2) {
                throw $exception;
            }
        }
    }

    if ($shareId <= 0) {
        throw new RuntimeException('Unable to generate a secure document link.', 500);
    }

    $share = fetchDocumentShareById($conn, $shareId);
    if (!$share) {
        throw new RuntimeException('The document link could not be loaded after creation.', 500);
    }
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

writeAuditLog($conn, $authUser, 'document.share_created', 'document', $documentId, [
    'share_id' => $shareId,
    'title' => $document['document_title'],
    'revision_id' => $revisionId,
    'revision_code' => $deliveryRevision['revision_code'] ?? null,
    'targets_current_revision' => $revisionId === null,
    'access_mode' => $accessMode,
    'allow_download' => $allowDownload,
    'expires_at' => $expiresAt,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Secure document link generated successfully.',
    'data' => array_merge(documentShareAdminPayload($conn, $share), [
        'token' => $rawToken,
        'share_url' => documentShareFrontendUrl($rawToken),
    ]),
], 201);
