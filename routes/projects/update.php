<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/projects.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN], 'Only Super Admins and Admins can manage tenders.');
$payload = readJsonBody();
$code = requiredIntFromPayload($payload, 'code', 'Tender code');

$existing = assertProjectAccessible($conn, $authUser, $code, true);
$project = normalizeProjectPayload($payload, true);
$clients = array_key_exists('clients', $payload) || array_key_exists('project_clients', $payload) || array_key_exists('clients_name', $payload)
    ? normalizeProjectClients($payload, true)
    : null;
$tenderDocuments = array_key_exists('tender_documents', $payload) || array_key_exists('tender_document', $payload)
    ? normalizeDocumentRows($payload, 'tender_documents', 'tender_document', 'tender documents')
    : null;
$technicalDocuments = array_key_exists('technical_documents', $payload) || array_key_exists('technical_document', $payload)
    ? normalizeDocumentRows($payload, 'technical_documents', 'technical_document', 'technical documents')
    : null;

$requestedOwner = isset($payload['owner_pms_admin_id']) ? (int) $payload['owner_pms_admin_id'] : ($existing['owner_pms_admin_id'] !== null ? (int) $existing['owner_pms_admin_id'] : null);
$ownerPmsAdminId = resolveAssignableOwnerPmsAdminId($conn, $authUser, $requestedOwner);
if ($ownerPmsAdminId === null && $existing['owner_pms_admin_id'] !== null && !isGlobalDataUser($authUser)) {
    $ownerPmsAdminId = (int) $existing['owner_pms_admin_id'];
}

$duplicate = dbFetchOne(
    $conn,
    "SELECT `code` FROM `project_info_table` WHERE LOWER(TRIM(`project_title`)) = LOWER(TRIM(?)) AND `code` <> ? AND `record_status` = 'active' LIMIT 1",
    'si',
    [$project['project_title'], $code]
);

if ($duplicate) {
    throw new RuntimeException('Another tender already uses this project title.', 422);
}

$tenderCode = buildTenderCode($project['project_country'], $project['division'], $project['project_title'], $code);
$updatedBy = actorEmail($authUser);
$updatedById = (int) $authUser['id'];

$conn->begin_transaction();
try {
    $stmt = dbExecute(
        $conn,
        'UPDATE `project_info_table`
         SET `project_title` = ?, `end_user` = ?, `division` = ?, `project_manager` = ?, `qs_manager` = ?,
             `mep_consultants` = ?, `architect` = ?, `project_duration` = ?, `rfi_due` = ?, `tender_received_date` = ?,
             `tender_due` = ?, `tender_submission_date` = ?, `tender_amount` = ?, `currency` = ?, `project_country` = ?,
             `project_city` = ?, `city_code` = ?, `project_importance` = ?, `contract_type` = ?, `prelim_pricing` = ?,
             `pricing_strategy` = ?, `date_extension` = ?, `rate_used` = ?, `procurement_type` = ?, `tender_code` = ?,
             `project_status` = ?, `progress` = ?, `tender_awarded_date` = ?, `vendor_information` = ?, `document_link` = ?,
             `additional_information` = ?, `updated_by` = ?, `updated_by_id` = ?, `owner_pms_admin_id` = ?, `updated_at` = CURRENT_TIMESTAMP
         WHERE `code` = ?',
        'ssssssssssssssssssssssssssssssssiii',
        [
            $project['project_title'], $project['end_user'], $project['division'], $project['project_manager'], $project['qs_manager'],
            $project['mep_consultants'], $project['architect'], $project['project_duration'], $project['rfi_due'], $project['tender_received_date'],
            $project['tender_due'], $project['tender_submission_date'], $project['tender_amount'], $project['currency'], $project['project_country'],
            $project['project_city'], $project['city_code'], $project['project_importance'], $project['contract_type'], $project['prelim_pricing'],
            $project['pricing_strategy'], $project['date_extension'], $project['rate_used'], $project['procurement_type'], $tenderCode,
            $project['project_status'], $project['progress'], $project['tender_awarded_date'], $project['vendor_information'], $project['document_link'],
            $project['additional_information'], $updatedBy, $updatedById, $ownerPmsAdminId, $code,
        ]
    );
    $stmt->close();

    if ($clients !== null) {
        replaceProjectClients($conn, $authUser, $code, $project['project_title'], $ownerPmsAdminId, $clients);
    }

    if ($tenderDocuments !== null) {
        replaceProjectDocuments($conn, $authUser, $code, $project['project_title'], $ownerPmsAdminId, $tenderDocuments, 'tender_document_table', 'tender_document');
    }

    if ($technicalDocuments !== null) {
        replaceProjectDocuments($conn, $authUser, $code, $project['project_title'], $ownerPmsAdminId, $technicalDocuments, 'technical_document_table', 'technical_document');
    }

    $project['code'] = $code;
    $awardedProjectCode = syncAwardedProjectCode($conn, $authUser, $project);

    writeAuditLog($conn, $authUser, 'project.updated', 'project', (string) $code, [
        'project_title' => $project['project_title'],
        'tender_code' => $tenderCode,
        'awarded_project_code' => $awardedProjectCode,
        'relations_replaced' => [
            'clients' => $clients !== null,
            'tender_documents' => $tenderDocuments !== null,
            'technical_documents' => $technicalDocuments !== null,
        ],
    ]);

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

$updated = assertProjectAccessible($conn, $authUser, $code, true);

jsonResponse([
    'status' => 'Success',
    'message' => 'Tender updated successfully.',
    'data' => projectResponsePayload($conn, $authUser, $updated),
]);
