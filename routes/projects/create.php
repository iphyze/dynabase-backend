<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/projects.php';
require_once __DIR__ . '/../../includes/lookup.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();

$project = normalizeProjectPayload($payload, false);
$lostOnCreate = isLostProjectStatus($project['project_status']) || isLostProjectProgress($project['progress']);
$lossComment = normalizeProjectLossComment($payload, $lostOnCreate);
$clients = normalizeProjectClients($payload, true);
// Only AI-reviewed tender requests contain this marker. Ordinary tender
// creation remains unchanged. Validate every selected client/key person pair
// against active, permission-scoped records before starting any INSERT.
if (($payload['ai_reviewed_clients'] ?? false) === true) {
    $cityTable = cityTableForCountry($project['project_country']);
    $city = $cityTable !== null
        ? dbFetchOne($conn, "SELECT id FROM {$cityTable} WHERE city_name=? LIMIT 1", 's', [$project['project_city']])
        : null;
    if (!$city) {
        throw new RuntimeException('Select a valid city for the selected tender country.', 422);
    }
    $selectedRows = $payload['clients'] ?? null;
    if (!is_array($selectedRows) || !$selectedRows || count($selectedRows) !== count($clients)) {
        throw new RuntimeException('Select a saved client and Key Person for each tender relationship.', 422);
    }
    foreach ($selectedRows as $position => $selected) {
        if (!is_array($selected)) throw new RuntimeException('Invalid tender relationship selection.', 422);
        $clientId = (int) ($selected['client_id'] ?? 0);
        $personId = (int) ($selected['keyperson_id'] ?? 0);
        if ($clientId <= 0 || $personId <= 0 || $clientId !== (int) $clients[$position]['client_id']) {
            throw new RuntimeException('Choose a saved client and its existing Key Person.', 422);
        }
        [$clientScope, $clientTypes, $clientParams] = appendScopedWhere($authUser, 'c', 'i', [$clientId]);
        $client = dbFetchOne($conn,
            "SELECT c.id,c.clients_name FROM clients_table c WHERE c.id=? AND c.status='active'{$clientScope} LIMIT 1",
            $clientTypes, $clientParams);
        [$personScope, $personTypes, $personParams] = appendKeypersonScopedWhere($authUser, 'k', 'ii', [$personId, $clientId]);
        $person = dbFetchOne($conn,
            "SELECT k.id,k.key_person FROM keypersons_table k WHERE k.id=? AND k.clients_id=? AND k.status='active'{$personScope} LIMIT 1",
            $personTypes, $personParams);
        if (!$client || !$person
            || strcasecmp((string) $client['clients_name'], (string) $clients[$position]['client_name']) !== 0
            || strcasecmp((string) $person['key_person'], (string) $clients[$position]['keyperson']) !== 0) {
            throw new RuntimeException('The selected client or Key Person is no longer active, accessible or correctly linked. Please reselect both.', 422);
        }
    }
}
$tenderDocuments = normalizeDocumentRows($payload, 'tender_documents', 'tender_document', 'tender documents');
$technicalDocuments = normalizeDocumentRows($payload, 'technical_documents', 'technical_document', 'technical documents');
$ownerPmsAdminId = resolveAssignableOwnerPmsAdminId($conn, $authUser, isset($payload['owner_pms_admin_id']) ? (int) $payload['owner_pms_admin_id'] : null);

$duplicate = dbFetchOne(
    $conn,
    "SELECT `code` FROM `project_info_table` WHERE LOWER(TRIM(`project_title`)) = LOWER(TRIM(?)) AND `record_status` = 'active' LIMIT 1",
    's',
    [$project['project_title']]
);

if ($duplicate) {
    throw new RuntimeException('A tender with this project title already exists.', 422);
}

$code = nextTenderCodeNumber($conn);
$tenderCode = buildTenderCode($project['project_country'], $project['division'], $project['project_title'], $code);
$createdBy = actorEmail($authUser);
$createdById = (int) $authUser['id'];

$conn->begin_transaction();
try {
    $stmt = dbExecute(
        $conn,
        'INSERT INTO `project_info_table`
         (`project_title`, `end_user`, `division`, `project_manager`, `qs_manager`, `mep_consultants`, `architect`,
          `project_duration`, `rfi_due`, `tender_received_date`, `tender_due`, `tender_submission_date`, `tender_amount`, `currency`,
          `project_country`, `project_city`, `city_code`, `project_importance`, `contract_type`, `prelim_pricing`, `pricing_strategy`,
          `date_extension`, `rate_used`, `procurement_type`, `code`, `tender_code`, `project_status`, `progress`, `tender_awarded_date`,
          `vendor_information`, `document_link`, `additional_information`, `created_by`, `updated_by`, `created_by_id`, `updated_by_id`,
          `owner_pms_admin_id`, `record_status`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\')',
        'ssssssssssssssssssssssssisssssssssiii',
        [
            $project['project_title'], $project['end_user'], $project['division'], $project['project_manager'], $project['qs_manager'],
            $project['mep_consultants'], $project['architect'], $project['project_duration'], $project['rfi_due'], $project['tender_received_date'],
            $project['tender_due'], $project['tender_submission_date'], $project['tender_amount'], $project['currency'], $project['project_country'],
            $project['project_city'], $project['city_code'], $project['project_importance'], $project['contract_type'], $project['prelim_pricing'],
            $project['pricing_strategy'], $project['date_extension'], $project['rate_used'], $project['procurement_type'], $code, $tenderCode,
            $project['project_status'], $project['progress'], $project['tender_awarded_date'], $project['vendor_information'], $project['document_link'],
            $project['additional_information'], $createdBy, $createdBy, $createdById, $createdById, $ownerPmsAdminId,
        ]
    );
    $stmt->close();

    replaceProjectClients($conn, $authUser, $code, $project['project_title'], $ownerPmsAdminId, $clients);
    syncProjectPrimaryRelationshipSnapshot($conn, $code);
    replaceProjectDocuments($conn, $authUser, $code, $project['project_title'], $ownerPmsAdminId, $tenderDocuments, 'tender_document_table', 'tender_document');
    replaceProjectDocuments($conn, $authUser, $code, $project['project_title'], $ownerPmsAdminId, $technicalDocuments, 'technical_document_table', 'technical_document');

    $project['code'] = $code;
    $project['city_code'] = $project['city_code'];
    $awardedProjectCode = syncAwardedProjectCode($conn, $authUser, $project);

    writeAuditLog($conn, $authUser, 'project.created', 'project', (string) $code, [
        'project_title' => $project['project_title'],
        'tender_code' => $tenderCode,
        'awarded_project_code' => $awardedProjectCode,
        'clients_count' => count($clients),
        'tender_documents_count' => count($tenderDocuments),
        'technical_documents_count' => count($technicalDocuments),
    ]);

    if ($lostOnCreate) {
        $triggerFields = [];
        if (isLostProjectStatus($project['project_status'])) { $triggerFields[] = 'status'; }
        if (isLostProjectProgress($project['progress'])) { $triggerFields[] = 'progress'; }
        writeProjectLossComment($conn, $authUser, $code, $lossComment, [
            'project_status' => $project['project_status'],
            'progress' => $project['progress'],
            'trigger_fields' => $triggerFields,
            'source' => 'create',
        ]);
    }

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

$created = assertProjectAccessible($conn, $authUser, $code, true);

jsonResponse([
    'status' => 'Success',
    'message' => 'Tender created successfully.',
    'data' => projectResponsePayload($conn, $authUser, $created),
], 201);
