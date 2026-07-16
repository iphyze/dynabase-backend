<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/projects.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$code = requiredIntFromPayload($payload, 'code', 'Tender code');

$project = assertProjectAccessible($conn, $authUser, $code, true);

$conn->begin_transaction();
try {
    dbExecute($conn, "UPDATE `project_info_table` SET `record_status` = 'deactivated', `updated_by` = ?, `updated_by_id` = ?, `updated_at` = CURRENT_TIMESTAMP WHERE `code` = ?", 'sii', [actorEmail($authUser), (int) $authUser['id'], $code])->close();
    dbExecute($conn, "UPDATE `clients_keypersons_table` SET `record_status` = 'deactivated', `updated_by` = ?, `updated_by_id` = ? WHERE `project_id` = ?", 'sii', [actorEmail($authUser), (int) $authUser['id'], $code])->close();
    dbExecute($conn, "UPDATE `tender_document_table` SET `record_status` = 'deactivated', `updated_by` = ?, `updated_by_id` = ? WHERE `project_id` = ?", 'sii', [actorEmail($authUser), (int) $authUser['id'], $code])->close();
    dbExecute($conn, "UPDATE `technical_document_table` SET `record_status` = 'deactivated', `updated_by` = ?, `updated_by_id` = ? WHERE `project_id` = ?", 'sii', [actorEmail($authUser), (int) $authUser['id'], $code])->close();
    dbExecute($conn, 'DELETE FROM `project_code_table` WHERE `tender_code` = ?', 's', [(string) $code])->close();

    writeAuditLog($conn, $authUser, 'project.deleted', 'project', (string) $code, [
        'project_title' => $project['project_title'] ?? null,
        'tender_code' => $project['tender_code'] ?? null,
    ]);

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Tender deleted successfully.',
]);
