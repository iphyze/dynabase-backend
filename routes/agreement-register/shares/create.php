<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/agreementExternal.php';

requireMethod('POST');
$authUser = authenticateUser();
assertAgreementExternalSchema($conn);
$payload = readJsonBody();

$agreementId = (int) ($payload['agreement_id'] ?? $payload['id'] ?? 0);
if ($agreementId <= 0) {
    throw new RuntimeException('Agreement record ID is required.', 422);
}
$agreement = assertAgreementAccessible($conn, $authUser, $agreementId);
$accessMode = normaliseAgreementExternalAccessMode($payload['access_mode'] ?? 'open');
$passwordHash = normaliseAgreementExternalPassword($payload['password'] ?? '', $accessMode === 'controlled');
$linkName = normaliseAgreementExternalLinkName($payload['link_name'] ?? '');
$editableFields = normaliseAgreementExternalEditableFields($payload['editable_fields'] ?? []);
$allowDownload = documentFormBoolean($payload['allow_document_download'] ?? true, true);
$allowUpload = documentFormBoolean($payload['allow_document_upload'] ?? true, true);
$expiresAt = normaliseAgreementExternalExpiry($payload['expires_at'] ?? '');
$actorId = (int) $authUser['id'];
$actorEmail = actorEmail($authUser);
$fieldsJson = json_encode($editableFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($fieldsJson === false) {
    throw new RuntimeException('Unable to save the selected client-editable fields.', 500);
}

$rawToken = '';
$shareId = 0;
$conn->begin_transaction();
try {
    $lockedAgreement = dbFetchOne(
        $conn,
        "SELECT id FROM agreement_registers WHERE id = ? AND record_status = 'active' LIMIT 1 FOR UPDATE",
        'i',
        [$agreementId]
    );
    if (!$lockedAgreement) {
        throw new RuntimeException('Agreement record is no longer available.', 404);
    }

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $rawToken = agreementExternalRandomToken();
        try {
            $stmt = dbExecute(
                $conn,
                'INSERT INTO agreement_external_links
                    (agreement_id, token_hash, token_ciphertext, link_name, access_mode, password_hash,
                     editable_fields, allow_document_download, allow_document_upload, expires_at,
                     status, created_by_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active", ?, ?)',
                'issssssiisis',
                [
                    $agreementId,
                    agreementExternalHash($rawToken),
                    agreementExternalEncryptToken($rawToken),
                    $linkName,
                    $accessMode,
                    $passwordHash,
                    $fieldsJson,
                    $allowDownload ? 1 : 0,
                    $allowUpload ? 1 : 0,
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
        throw new RuntimeException('Unable to generate a secure agreement workspace link.', 500);
    }
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

$share = fetchAgreementExternalShareById($conn, $shareId);
if (!$share) {
    throw new RuntimeException('The agreement workspace link could not be loaded after creation.', 500);
}
writeAuditLog($conn, $authUser, 'agreement_register.external_link_created', 'agreement_register', $agreementId, [
    'document_ref_no' => $agreement['document_ref_no'],
    'share_id' => $shareId,
    'access_mode' => $accessMode,
    'editable_fields' => $editableFields,
    'allow_document_download' => $allowDownload,
    'allow_document_upload' => $allowUpload,
    'expires_at' => $expiresAt,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Client agreement workspace created successfully.',
    'data' => array_merge(agreementExternalAdminPayload($share), [
        'token' => $rawToken,
        'share_url' => agreementExternalFrontendUrl($rawToken),
    ]),
], 201);
