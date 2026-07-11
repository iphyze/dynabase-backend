<?php
declare(strict_types=1);

require_once __DIR__ . '/authMiddleware.php';
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/dbHelpers.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/pagination.php';
require_once __DIR__ . '/audit.php';

const DYNABASE_PROJECT_STATUSES = [
    'Approved',
    'Pending',
    'In Progress',
    'Awaiting',
    'Submitted',
    'On Hold',
    'Declined',
    'Awarded',
    'Abortive',
];

const DYNABASE_PROJECT_PROGRESS = [
    'Approved',
    'Pending',
    'In Progress',
    'Awaiting',
    'Submitted',
    'On Hold',
    'Declined',
    'Awarded',
    'Abortive',
];

function projectCountryCode(string $country): string
{
    return match (strtolower(trim($country))) {
        'nigeria' => 'NGN',
        'ghana' => 'GHA',
        'ivory coast', 'côte d\'ivoire', 'cote d\'ivoire' => 'CIV',
        default => '',
    };
}

function projectDivisionCode(string $division): string
{
    return match (strtolower(trim($division))) {
        'power', 'power & energy' => 'PWR',
        'data centre', 'data center', 'ict & data center', 'ict & data centre' => 'DCT',
        'building & factories', 'building and factories' => 'BNF',
        'oil & gas', 'oil and gas' => 'ONG',
        'facilities & maintenance', 'facilities and maintenance' => 'FNM',
        'water & infrastructure', 'water and infrastructure' => 'WNI',
        default => '',
    };
}

function projectInitialCode(string $title, int $length = 3): string
{
    $words = preg_split('/[\s,_\-]+/', strtoupper(trim($title))) ?: [];
    $code = '';
    foreach ($words as $word) {
        if ($word !== '') {
            $code .= function_exists('mb_substr') ? mb_substr($word, 0, 1) : substr($word, 0, 1);
        }
    }

    $code = preg_replace('/[^A-Z0-9]/', '', $code) ?: 'PRJ';
    return substr($code, 0, $length);
}

function normalizeProjectStatus(string $status, string $fallback = 'On Hold'): string
{
    $status = trim($status);
    if ($status === '') {
        return $fallback;
    }

    foreach (DYNABASE_PROJECT_STATUSES as $allowed) {
        if (strcasecmp($status, $allowed) === 0) {
            return $allowed;
        }
    }

    throw new RuntimeException('Invalid tender status selected.', 422);
}

function normalizeProjectProgress(string $progress, string $fallback = 'Pending'): string
{
    $progress = trim($progress);
    if ($progress === '') {
        return $fallback;
    }

    foreach (DYNABASE_PROJECT_PROGRESS as $allowed) {
        if (strcasecmp($progress, $allowed) === 0) {
            return $allowed;
        }
    }

    throw new RuntimeException('Invalid tender progress selected.', 422);
}

function normalizeOptionalDate(array $payload, string $field): string
{
    $value = optionalStringField($payload, $field, 30);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        throw new RuntimeException(str_replace('_', ' ', ucfirst($field)) . ' must be a valid date.', 422);
    }

    return date('Y-m-d', $timestamp);
}

function resolveOtherProjectValue(array $payload, string $field, string $otherField, string $otherTrigger): string
{
    $value = optionalStringField($payload, $field, 255);
    if (strcasecmp($value, $otherTrigger) === 0) {
        return optionalStringField($payload, $otherField, 255);
    }

    return $value;
}

function nextTenderCodeNumber(mysqli $conn): int
{
    $row = dbFetchOne($conn, 'SELECT MAX(`code`) AS max_code FROM `project_info_table`');
    $maxCode = (int) ($row['max_code'] ?? 0);
    return $maxCode > 0 ? $maxCode + 1 : 101;
}

function buildTenderCode(string $country, string $division, string $projectTitle, int $code): string
{
    $countryCode = projectCountryCode($country);
    $divisionCode = projectDivisionCode($division);

    if ($countryCode === '') {
        throw new RuntimeException('Please select a valid project country.', 422);
    }

    if ($divisionCode === '') {
        throw new RuntimeException('Please select a valid project division.', 422);
    }

    $yearCode = substr(date('Y'), 2, 2);
    return $countryCode . '-' . $divisionCode . '-' . projectInitialCode($projectTitle) . '-' . $yearCode . '/' . $code;
}

function deriveCityCode(string $city, string $fallback = ''): string
{
    $fallback = strtoupper(trim($fallback));
    if ($fallback !== '') {
        return substr(preg_replace('/[^A-Z0-9]/', '', $fallback) ?: $fallback, 0, 8);
    }

    $city = strtoupper(trim($city));
    if ($city === '') {
        return 'CITY';
    }

    $words = preg_split('/[\s,_\-]+/', $city) ?: [];
    $code = '';
    foreach ($words as $word) {
        if ($word !== '') {
            $code .= substr($word, 0, 1);
        }
    }

    return substr($code !== '' ? $code : preg_replace('/[^A-Z0-9]/', '', $city), 0, 8) ?: 'CITY';
}

function nextAwardedProjectSubCode(mysqli $conn): int
{
    $row = dbFetchOne($conn, 'SELECT MAX(CAST(`sub_code` AS UNSIGNED)) AS max_sub_code FROM `project_code_table`');
    $maxCode = (int) ($row['max_sub_code'] ?? 0);
    return $maxCode > 0 ? $maxCode + 1 : 251;
}

function syncAwardedProjectCode(mysqli $conn, array $authUser, array $project): ?string
{
    $code = (int) $project['code'];
    $progress = normalizeProjectProgress((string) ($project['progress'] ?? 'Pending'));
    $actorId = (int) $authUser['id'];

    // A generated project code is permanent. Later tender edits or a temporary
    // workflow correction must never delete it or consume a new sequence.
    $existing = dbFetchOne($conn, 'SELECT `project_code` FROM `project_code_table` WHERE `tender_code` = ? LIMIT 1', 's', [(string) $code]);
    if ($existing) {
        $stmt = dbExecute(
            $conn,
            'UPDATE `project_code_table` SET `project_title` = ?, `updated_by_id` = ? WHERE `tender_code` = ?',
            'sis',
            [(string) $project['project_title'], $actorId, (string) $code]
        );
        $stmt->close();
        return (string) $existing['project_code'];
    }

    if (strcasecmp($progress, 'Awarded') !== 0) {
        return null;
    }

    $subCode = nextAwardedProjectSubCode($conn);
    $projectCode = 'LEM-' . projectInitialCode((string) $project['project_title']) . '-' . deriveCityCode((string) ($project['project_city'] ?? ''), (string) ($project['city_code'] ?? '')) . '-' . $subCode;
    $createdById = $actorId;

    $stmt = dbExecute(
        $conn,
        'INSERT INTO `project_code_table` (`tender_code`, `project_title`, `project_code`, `sub_code`, `created_by_id`, `updated_by_id`) VALUES (?, ?, ?, ?, ?, ?)',
        'sssiii',
        [(string) $code, (string) $project['project_title'], $projectCode, $subCode, $createdById, $createdById]
    );
    $stmt->close();

    return $projectCode;
}

function normalizeProjectPayload(array $payload, bool $isUpdate = false): array
{
    $projectTitle = requireStringField($payload, 'project_title', 'Project title', 255);
    $division = requireStringField($payload, 'division', 'Project division', 255);
    $country = requireStringField($payload, 'project_country', 'Project country', 255);
    $city = requireStringField($payload, 'project_city', 'Project city', 255);

    return [
        'project_title' => $projectTitle,
        'end_user' => resolveOtherProjectValue($payload, 'end_user', 'other_end_user', 'Other End User / Owner'),
        'division' => $division,
        'project_manager' => resolveOtherProjectValue($payload, 'project_manager', 'other_project_manager', 'Other Project Manager'),
        'qs_manager' => resolveOtherProjectValue($payload, 'qs_manager', 'other_qs_manager', 'Other Qs Manager'),
        'mep_consultants' => resolveOtherProjectValue($payload, 'mep_consultant', 'other_mep_consultant', 'Other Mep Consultant'),
        'architect' => resolveOtherProjectValue($payload, 'architects', 'other_architects', 'Other Architect'),
        'project_duration' => optionalStringField($payload, 'project_duration', 255),
        'rfi_due' => normalizeOptionalDate($payload, 'rfi_due'),
        'tender_received_date' => normalizeOptionalDate($payload, 'tender_received_date'),
        'tender_due' => normalizeOptionalDate($payload, 'tender_due'),
        'tender_submission_date' => normalizeOptionalDate($payload, 'tender_submission_date'),
        'tender_amount' => optionalStringField($payload, 'tender_amount', 255),
        'currency' => optionalStringField($payload, 'currency', 20),
        'project_country' => $country,
        'project_city' => $city,
        'city_code' => optionalStringField($payload, 'city_code', 255),
        'project_importance' => optionalStringField($payload, 'project_importance', 255),
        'contract_type' => optionalStringField($payload, 'contract_type', 255),
        'prelim_pricing' => optionalStringField($payload, 'prelim_pricing', 255),
        'pricing_strategy' => optionalStringField($payload, 'pricing_strategy', 255),
        'date_extension' => optionalStringField($payload, 'date_extension', 255),
        'rate_used' => optionalStringField($payload, 'rate_used', 255),
        'procurement_type' => optionalStringField($payload, 'procurement_type', 255),
        'project_status' => normalizeProjectStatus((string) ($payload['project_status'] ?? ''), $isUpdate ? 'On Hold' : 'On Hold'),
        'progress' => normalizeProjectProgress((string) ($payload['progress'] ?? ''), $isUpdate ? 'Pending' : 'Pending'),
        'tender_awarded_date' => normalizeOptionalDate($payload, 'tender_awarded_date'),
        'vendor_information' => optionalStringField($payload, 'vendor_information', 255),
        'document_link' => optionalStringField($payload, 'document_link', 255),
        'additional_information' => optionalStringField($payload, 'additional_information', 5000),
    ];
}

function normalizeProjectClients(array $payload, bool $required = true): array
{
    $rows = $payload['clients'] ?? $payload['project_clients'] ?? null;

    if ($rows === null && isset($payload['clients_name']) && is_array($payload['clients_name'])) {
        $rows = [];
        foreach ($payload['clients_name'] as $index => $clientName) {
            $rows[] = [
                'id' => $payload['clients_tab_id'][$index] ?? null,
                'client_id' => $payload['clients_id'][$index] ?? null,
                'client_name' => $clientName,
                'keyperson' => $payload['key_person'][$index] ?? '',
            ];
        }
    }

    if ($rows === null) {
        return [];
    }

    if (!is_array($rows)) {
        throw new RuntimeException('Project clients must be provided as a list.', 422);
    }

    $normalized = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $clientId = (int) ($row['client_id'] ?? $row['clients_id'] ?? 0);
        $clientName = cleanString($row['client_name'] ?? $row['clients_name'] ?? '');
        $keyperson = cleanString($row['keyperson'] ?? $row['key_person'] ?? '');

        if ($clientName === '' && $clientId <= 0 && $keyperson === '') {
            continue;
        }

        if ($clientName === '') {
            throw new RuntimeException('Each project client row must include a client name.', 422);
        }

        if ($keyperson === '') {
            throw new RuntimeException('Each project client row must include a keyperson.', 422);
        }

        $dedupeKey = strtolower($clientName);
        if (isset($seen[$dedupeKey])) {
            throw new RuntimeException('Duplicate project clients are not allowed on the same tender.', 422);
        }
        $seen[$dedupeKey] = true;

        $normalized[] = [
            'id' => isset($row['id']) && (int) $row['id'] > 0 ? (int) $row['id'] : null,
            'client_id' => $clientId,
            'client_name' => $clientName,
            'keyperson' => $keyperson,
        ];
    }

    if ($required && $normalized === []) {
        throw new RuntimeException('Please add at least one project client/keyperson row.', 422);
    }

    return $normalized;
}

function normalizeDocumentRows(array $payload, string $key, string $legacyKey, string $label): array
{
    $rows = $payload[$key] ?? null;

    if ($rows === null && isset($payload[$legacyKey]) && is_array($payload[$legacyKey])) {
        $rows = array_map(static fn ($value): array => [$key === 'tender_documents' ? 'tender_document' : 'technical_document' => $value], $payload[$legacyKey]);
    }

    if ($rows === null) {
        return [];
    }

    if (!is_array($rows)) {
        throw new RuntimeException("{$label} must be provided as a list.", 422);
    }

    $field = $key === 'tender_documents' ? 'tender_document' : 'technical_document';
    $normalized = [];
    $seen = [];

    foreach ($rows as $row) {
        if (is_string($row)) {
            $value = cleanString($row);
            $id = null;
        } elseif (is_array($row)) {
            $value = cleanString($row[$field] ?? $row['title'] ?? $row['name'] ?? '');
            $id = isset($row['id']) && (int) $row['id'] > 0 ? (int) $row['id'] : null;
        } else {
            continue;
        }

        if ($value === '') {
            continue;
        }

        $dedupeKey = strtolower($value);
        if (isset($seen[$dedupeKey])) {
            throw new RuntimeException("Duplicate {$label} entries are not allowed.", 422);
        }
        $seen[$dedupeKey] = true;

        $normalized[] = ['id' => $id, $field => $value];
    }

    return $normalized;
}

function assertProjectAccessible(mysqli $conn, array $authUser, int $code, bool $includeInactive = false): array
{
    [$scopeSql, $types, $params] = appendScopedWhere($authUser, 'p', 'i', [$code]);
    $recordStatusSql = $includeInactive ? '' : " AND p.`record_status` = 'active'";

    $project = dbFetchOne(
        $conn,
        "SELECT p.*, pc.`project_code` AS awarded_project_code
         FROM `project_info_table` p
         LEFT JOIN `project_code_table` pc ON pc.`tender_code` = CAST(p.`code` AS CHAR)
         WHERE p.`code` = ?{$recordStatusSql}{$scopeSql}
         LIMIT 1",
        $types,
        $params
    );

    if (!$project) {
        throw new RuntimeException('Tender not found or not accessible.', 404);
    }

    return $project;
}

function loadProjectRelations(mysqli $conn, array $authUser, int $code): array
{
    [$scopeSql, $types, $params] = appendScopedWhere($authUser, 'ck', 'i', [$code]);
    $clients = dbFetchAll(
        $conn,
        "SELECT ck.`id`, ck.`clients_id` AS client_id, ck.`clients_name` AS client_name, ck.`keyperson`, ck.`created_by`, ck.`updated_by`
         FROM `clients_keypersons_table` ck
         WHERE ck.`project_id` = ? AND ck.`record_status` = 'active'{$scopeSql}
         ORDER BY ck.`id` ASC",
        $types,
        $params
    );

    [$scopeSql, $types, $params] = appendScopedWhere($authUser, 'td', 'i', [$code]);
    $tenderDocs = dbFetchAll(
        $conn,
        "SELECT td.`id`, td.`tender_document`, td.`created_by`, td.`updated_by`
         FROM `tender_document_table` td
         WHERE td.`project_id` = ? AND td.`record_status` = 'active'{$scopeSql}
         ORDER BY td.`id` ASC",
        $types,
        $params
    );

    [$scopeSql, $types, $params] = appendScopedWhere($authUser, 'td', 'i', [$code]);
    $technicalDocs = dbFetchAll(
        $conn,
        "SELECT td.`id`, td.`technical_document`, td.`created_by`, td.`updated_by`
         FROM `technical_document_table` td
         WHERE td.`project_id` = ? AND td.`record_status` = 'active'{$scopeSql}
         ORDER BY td.`id` ASC",
        $types,
        $params
    );

    return [
        'clients' => $clients,
        'tender_documents' => $tenderDocs,
        'technical_documents' => $technicalDocs,
    ];
}

function replaceProjectClients(mysqli $conn, array $authUser, int $code, string $projectTitle, ?int $ownerPmsAdminId, array $clients): void
{
    dbExecute($conn, 'UPDATE `clients_keypersons_table` SET `record_status` = \'deactivated\' WHERE `project_id` = ?', 'i', [$code])->close();

    foreach ($clients as $row) {
        $createdById = (int) $authUser['id'];
        $updatedById = (int) $authUser['id'];
        $createdBy = actorEmail($authUser);
        $updatedBy = actorEmail($authUser);
        $clientId = (int) $row['client_id'];
        $clientName = $row['client_name'];
        $keyperson = $row['keyperson'];

        $stmt = dbExecute(
            $conn,
            'INSERT INTO `clients_keypersons_table`
             (`project_id`, `project_title`, `clients_name`, `clients_id`, `keyperson`, `created_by`, `updated_by`, `created_by_id`, `updated_by_id`, `owner_pms_admin_id`, `record_status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\')',
            'ississsiii',
            [$code, $projectTitle, $clientName, $clientId, $keyperson, $createdBy, $updatedBy, $createdById, $updatedById, $ownerPmsAdminId]
        );
        $stmt->close();
    }
}

function replaceProjectDocuments(mysqli $conn, array $authUser, int $code, string $projectTitle, ?int $ownerPmsAdminId, array $rows, string $table, string $field): void
{
    dbExecute($conn, "UPDATE `{$table}` SET `record_status` = 'deactivated' WHERE `project_id` = ?", 'i', [$code])->close();

    foreach ($rows as $row) {
        $title = (string) $row[$field];
        $createdById = (int) $authUser['id'];
        $updatedById = (int) $authUser['id'];
        $createdBy = actorEmail($authUser);
        $updatedBy = actorEmail($authUser);

        $stmt = dbExecute(
            $conn,
            "INSERT INTO `{$table}` (`project_id`, `project_title`, `{$field}`, `created_by`, `updated_by`, `created_by_id`, `updated_by_id`, `owner_pms_admin_id`, `record_status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')",
            'issssiii',
            [$code, $projectTitle, $title, $createdBy, $updatedBy, $createdById, $updatedById, $ownerPmsAdminId]
        );
        $stmt->close();
    }
}

function projectResponsePayload(mysqli $conn, array $authUser, array $project, bool $withRelations = true): array
{
    $project['id'] = (int) $project['id'];
    $project['code'] = (int) $project['code'];
    $project['created_by_id'] = $project['created_by_id'] !== null ? (int) $project['created_by_id'] : null;
    $project['updated_by_id'] = $project['updated_by_id'] !== null ? (int) $project['updated_by_id'] : null;
    $project['owner_pms_admin_id'] = $project['owner_pms_admin_id'] !== null ? (int) $project['owner_pms_admin_id'] : null;
    $project['awarded_project_code'] = $project['awarded_project_code'] ?: 'Unawarded';

    if ($withRelations) {
        $project['relations'] = loadProjectRelations($conn, $authUser, (int) $project['code']);
    }

    return $project;
}

function parseProjectCodesFromPayload(array $payload): array
{
    $codes = $payload['codes'] ?? $payload['project_codes'] ?? [];
    if (!is_array($codes)) {
        throw new RuntimeException('Please provide a valid list of tender codes.', 422);
    }

    $normalized = [];
    foreach ($codes as $code) {
        $code = (int) $code;
        if ($code > 0) {
            $normalized[$code] = $code;
        }
    }

    if ($normalized === []) {
        throw new RuntimeException('Please select at least one tender.', 422);
    }

    if (count($normalized) > 250) {
        throw new RuntimeException('You can update a maximum of 250 tenders at once.', 422);
    }

    return array_values($normalized);
}

function projectCodesPlaceholders(array $codes): string
{
    return implode(',', array_fill(0, count($codes), '?'));
}

function projectScopeForBulk(array $authUser, string $alias, string $types, array $params): array
{
    [$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, $alias, $types, $params);
    return [$scopeSql, $scopeTypes, $scopeParams];
}
