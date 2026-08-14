<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();

$canManageTaxonomy = userHasPermission($conn, $authUser, 'documents.create')
    || userHasPermission($conn, $authUser, 'documents.edit');
if (!$canManageTaxonomy) {
    throw new RuntimeException('You are not authorised to manage document classifications.', 403);
}

$payload = readJsonBody();
$action = strtolower(cleanString($payload['action'] ?? ''));
$actorId = (int) ($authUser['id'] ?? 0);
$actorEmail = actorEmail($authUser);

if ($action === 'type') {
    $documentType = documentTypeLabel(requireStringField($payload, 'document_type', 'Document type'));
    if (documentStringLength($documentType) > 120) {
        throw new RuntimeException('Document type must not exceed 120 characters.', 422);
    }

    foreach (documentTypes($conn) as $existingType) {
        if (strcasecmp($existingType, $documentType) === 0) {
            throw new RuntimeException('This document type already exists.', 409);
        }
    }

    $documentTypes = array_merge(documentTypes($conn), [$documentType]);

    $conn->begin_transaction();
    try {
        saveAppSettings($conn, ['document_types' => $documentTypes], $actorId);
        writeAuditLog($conn, $authUser, 'document_type.created', 'document_type', $documentType, [
            'document_type' => $documentType,
        ]);
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    jsonResponse([
        'status' => 'Success',
        'message' => 'Document type created successfully.',
        'data' => [
            'value' => $documentType,
            'label' => $documentType,
        ],
    ], 201);
}

if ($action === 'category') {
    $documentType = normaliseDocumentType($conn, $payload['document_type'] ?? '');
    $categoryName = requireStringField($payload, 'category_name', 'Category name');
    if (documentStringLength($categoryName) > 180) {
        throw new RuntimeException('Category name must not exceed 180 characters.', 422);
    }

    $existing = dbFetchOne(
        $conn,
        "SELECT id FROM tender_document_sections
         WHERE LOWER(TRIM(section_title)) = LOWER(TRIM(?))
           AND LOWER(TRIM(section_type)) = LOWER(TRIM(?))
         LIMIT 1",
        'ss',
        [$categoryName, $documentType]
    );
    if ($existing) {
        throw new RuntimeException('This category already exists for the selected document type.', 409);
    }

    $conn->begin_transaction();
    try {
        $stmt = dbExecute(
            $conn,
            'INSERT INTO tender_document_sections (section_title, section_type, created_by, updated_by) VALUES (?, ?, ?, ?)',
            'ssss',
            [$categoryName, $documentType, $actorEmail, $actorEmail]
        );
        $categoryId = (int) $stmt->insert_id;
        $stmt->close();

        writeAuditLog($conn, $authUser, 'document_category.created', 'document_category', $categoryId, [
            'category_name' => $categoryName,
            'document_type' => $documentType,
        ]);
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    jsonResponse([
        'status' => 'Success',
        'message' => 'Document category created successfully.',
        'data' => [
            'id' => $categoryId,
            'value' => $categoryName,
            'label' => $categoryName,
            'document_type' => $documentType,
        ],
    ], 201);
}

throw new RuntimeException('Please choose a valid document classification action.', 422);
