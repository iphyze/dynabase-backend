<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/projects.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$codes = parseProjectCodesFromPayload($payload);
$placeholders = projectCodesPlaceholders($codes);
$types = str_repeat('i', count($codes));
$params = $codes;
[$scopeSql, $types, $params] = projectScopeForBulk($authUser, 'p', $types, $params);

$accessible = dbFetchAll(
    $conn,
    "SELECT p.`code`, p.`project_title`, p.`tender_code`
     FROM `project_info_table` p
     WHERE p.`code` IN ({$placeholders}) AND p.`record_status` = 'active'{$scopeSql}",
    $types,
    $params
);

if ($accessible === []) {
    throw new RuntimeException('No selected tenders are available for deletion.', 404);
}

$accessibleCodes = array_map(static fn (array $row): int => (int) $row['code'], $accessible);
$accessiblePlaceholders = projectCodesPlaceholders($accessibleCodes);
$accessibleTypes = str_repeat('i', count($accessibleCodes));
$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

$conn->begin_transaction();
try {
    dbExecute($conn, "UPDATE `project_info_table` SET `record_status` = 'deactivated', `updated_by` = ?, `updated_by_id` = ?, `updated_at` = CURRENT_TIMESTAMP WHERE `code` IN ({$accessiblePlaceholders})", 'si' . $accessibleTypes, array_merge([$actorEmail, $actorId], $accessibleCodes))->close();
    dbExecute($conn, "UPDATE `clients_keypersons_table` SET `record_status` = 'deactivated', `updated_by` = ?, `updated_by_id` = ? WHERE `project_id` IN ({$accessiblePlaceholders})", 'si' . $accessibleTypes, array_merge([$actorEmail, $actorId], $accessibleCodes))->close();
    dbExecute($conn, "UPDATE `tender_document_table` SET `record_status` = 'deactivated', `updated_by` = ?, `updated_by_id` = ? WHERE `project_id` IN ({$accessiblePlaceholders})", 'si' . $accessibleTypes, array_merge([$actorEmail, $actorId], $accessibleCodes))->close();
    dbExecute($conn, "UPDATE `technical_document_table` SET `record_status` = 'deactivated', `updated_by` = ?, `updated_by_id` = ? WHERE `project_id` IN ({$accessiblePlaceholders})", 'si' . $accessibleTypes, array_merge([$actorEmail, $actorId], $accessibleCodes))->close();
    dbExecute($conn, "DELETE FROM `project_code_table` WHERE CAST(`tender_code` AS UNSIGNED) IN ({$accessiblePlaceholders})", $accessibleTypes, $accessibleCodes)->close();

    writeAuditLog($conn, $authUser, 'project.bulk_deleted', 'project', null, [
        'requested_codes' => $codes,
        'deleted_codes' => $accessibleCodes,
        'deleted_count' => count($accessibleCodes),
    ]);

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

jsonResponse([
    'status' => 'Success',
    'message' => count($accessibleCodes) . ' tender(s) deleted successfully.',
    'data' => [
        'deleted_codes' => $accessibleCodes,
        'deleted_count' => count($accessibleCodes),
    ],
]);
