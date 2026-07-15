<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/dbHelpers.php';

const DYNABASE_SUBMISSION_CATEGORIES = ['Prequalification', 'Technical', 'Registration'];
const DYNABASE_SUBMISSION_MODES = ['Hard Copy', 'Email', 'Both'];
const DYNABASE_SUBMISSION_STATUSES = ['Ongoing', 'Completed'];

function submissionRegisterCategories(): array
{
    return DYNABASE_SUBMISSION_CATEGORIES;
}

function submissionRegisterModes(): array
{
    return DYNABASE_SUBMISSION_MODES;
}

function submissionRegisterStatuses(): array
{
    return DYNABASE_SUBMISSION_STATUSES;
}

function parseSubmissionRegisterIds(array $payload, int $maximum = 100): array
{
    $rawIds = $payload['ids'] ?? null;
    if (!is_array($rawIds)) {
        throw new RuntimeException('Please select at least one submission record.', 422);
    }

    $ids = array_values(array_unique(array_filter(
        array_map('intval', $rawIds),
        static fn (int $id): bool => $id > 0
    )));

    if ($ids === []) {
        throw new RuntimeException('Please select at least one submission record.', 422);
    }

    if (count($ids) > $maximum) {
        throw new RuntimeException("You can process a maximum of {$maximum} submission records at once.", 422);
    }

    return $ids;
}

function submissionRegisterBulkRecords(mysqli $conn, array $authUser, array $ids): array
{
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $params = $ids;
    [$scopeSql, $types, $params] = appendScopedWhere($authUser, 'sr', $types, $params);

    $rows = dbFetchAll(
        $conn,
        "SELECT sr.id, sr.submission_reference, sr.project_company_name, sr.client_name,
                sr.category, sr.status, sr.owner_pms_admin_id
         FROM submission_registers sr
         WHERE sr.id IN ({$placeholders})
           AND sr.record_status = 'active'{$scopeSql}
         ORDER BY sr.id ASC",
        $types,
        $params
    );

    foreach ($rows as &$row) {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['owner_pms_admin_id'] = isset($row['owner_pms_admin_id']) && $row['owner_pms_admin_id'] !== null
            ? (int) $row['owner_pms_admin_id']
            : null;
    }
    unset($row);

    return $rows;
}

function submissionRegisterBulkIds(array $records): array
{
    return array_values(array_map(
        static fn (array $record): int => (int) ($record['id'] ?? 0),
        $records
    ));
}

function ensureSubmissionRegisterSchema(mysqli $conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $database = (string) (dbFetchOne($conn, 'SELECT DATABASE() AS database_name')['database_name'] ?? '');
    if ($database === '') {
        throw new RuntimeException('Dynabase database connection is not using an active database.', 500);
    }

    $requiredTables = ['submission_registers', 'submission_register_updates'];
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $rows = dbFetchAll(
        $conn,
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
        str_repeat('s', count($requiredTables)),
        $requiredTables
    );

    $availableTables = array_map(static fn (array $row): string => (string) ($row['TABLE_NAME'] ?? ''), $rows);
    $missingTables = array_values(array_diff($requiredTables, $availableTables));
    if ($missingTables !== []) {
        throw new RuntimeException(
            'Submission Register database migration has not been applied. Please run 20260714_create_submission_register.sql, then refresh this page.',
            409
        );
    }

    $requiredColumns = [
        'submission_registers' => [
            'id', 'submission_reference', 'project_id', 'project_code', 'project_tender_code',
            'project_company_name', 'client_id', 'client_name', 'category', 'date_received',
            'date_submitted', 'mode_of_submission', 'hard_copy_contact_name', 'hard_copy_contact_email',
            'hard_copy_contact_phone', 'hard_copy_contact_address', 'email_recipient', 'status',
            'owner_pms_admin_id', 'record_status', 'created_by', 'created_by_id', 'created_at',
            'updated_by', 'updated_by_id', 'updated_at',
        ],
        'submission_register_updates' => [
            'id', 'submission_id', 'message', 'created_by', 'created_by_id', 'created_at',
            'updated_by', 'updated_by_id', 'updated_at', 'deleted_at',
        ],
    ];

    foreach ($requiredColumns as $table => $columns) {
        $columnRows = dbFetchAll(
            $conn,
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            's',
            [$table]
        );
        $availableColumns = array_map(static fn (array $row): string => (string) ($row['COLUMN_NAME'] ?? ''), $columnRows);
        $missingColumns = array_values(array_diff($columns, $availableColumns));
        if ($missingColumns !== []) {
            throw new RuntimeException(
                'Submission Register database schema is incomplete. Please re-run 20260714_create_submission_register.sql or add the missing migration columns: ' . implode(', ', $missingColumns) . '.',
                409
            );
        }
    }

    try {
        $validationStmt = $conn->prepare(submissionRegisterListSelectSql() . ' WHERE 1 = 0');
        $validationStmt->close();
    } catch (mysqli_sql_exception $exception) {
        error_log('[Dynabase Submission Register] Core list-query validation failed: ' . $exception->getMessage());
        throw new RuntimeException(
            'Submission Register database schema is incomplete or incompatible. Please run 20260714_create_submission_register.sql, then refresh this page.',
            409,
            $exception
        );
    }

    $checked = true;
}

function normalizeSubmissionOption(string $value, array $allowed, string $label): string
{
    $value = trim($value);
    foreach ($allowed as $option) {
        if (strcasecmp($value, $option) === 0) {
            return $option;
        }
    }

    throw new RuntimeException("Please choose a valid {$label}.", 422);
}

function normalizeSubmissionDate(array $payload, string $field, string $label): string
{
    $value = cleanString($payload[$field] ?? '');
    if ($value === '') {
        throw new RuntimeException("{$label} is required.", 422);
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        throw new RuntimeException("{$label} must be a valid date.", 422);
    }

    return date('Y-m-d', $timestamp);
}

function submissionRegisterListSelectSql(): string
{
    return "SELECT sr.id, sr.submission_reference, sr.project_id, sr.project_code, sr.project_tender_code,
            sr.project_company_name, sr.client_id, sr.client_name, sr.category, sr.date_received,
            sr.date_submitted, sr.mode_of_submission, sr.hard_copy_contact_name,
            sr.hard_copy_contact_email, sr.hard_copy_contact_phone, sr.hard_copy_contact_address,
            sr.email_recipient, sr.status, sr.owner_pms_admin_id, sr.record_status,
            sr.created_by, sr.created_by_id, sr.created_at, sr.updated_by, sr.updated_by_id, sr.updated_at,
            NULL AS linked_project_title,
            NULL AS linked_project_tender_code,
            NULL AS linked_project_code,
            NULL AS linked_project_status,
            NULL AS linked_project_progress,
            NULL AS linked_client_name,
            NULL AS linked_client_email,
            NULL AS linked_client_location,
            NULL AS linked_client_category,
            NULL AS owner_pms_admin_name,
            NULL AS owner_pms_admin_email,
            NULL AS created_by_name,
            NULL AS updated_by_name,
            0 AS updates_count,
            NULL AS latest_update_message,
            NULL AS latest_update_at,
            NULL AS latest_update_by_name
     FROM submission_registers sr";
}

function hydrateSubmissionRegisterListUpdates(mysqli $conn, array $rows): array
{
    if ($rows === []) {
        return [];
    }

    $submissionIds = array_values(array_unique(array_map(
        static fn (array $row): int => (int) ($row['id'] ?? 0),
        $rows
    )));
    $submissionIds = array_values(array_filter($submissionIds, static fn (int $id): bool => $id > 0));
    if ($submissionIds === []) {
        return $rows;
    }

    $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
    $types = str_repeat('i', count($submissionIds));
    $summaries = dbFetchAll(
        $conn,
        "SELECT submission_id, COUNT(*) AS total_updates, MAX(id) AS latest_update_id
         FROM submission_register_updates
         WHERE deleted_at IS NULL
           AND submission_id IN ({$placeholders})
         GROUP BY submission_id",
        $types,
        $submissionIds
    );

    $summaryBySubmission = [];
    $latestUpdateIds = [];
    foreach ($summaries as $summary) {
        $submissionId = (int) ($summary['submission_id'] ?? 0);
        if ($submissionId <= 0) {
            continue;
        }

        $latestUpdateId = (int) ($summary['latest_update_id'] ?? 0);
        $summaryBySubmission[$submissionId] = [
            'updates_count' => (int) ($summary['total_updates'] ?? 0),
            'latest_update_id' => $latestUpdateId,
        ];
        if ($latestUpdateId > 0) {
            $latestUpdateIds[] = $latestUpdateId;
        }
    }

    $latestById = [];
    if ($latestUpdateIds !== []) {
        $latestUpdateIds = array_values(array_unique($latestUpdateIds));
        $latestPlaceholders = implode(',', array_fill(0, count($latestUpdateIds), '?'));
        $latestRows = dbFetchAll(
            $conn,
            "SELECT id, submission_id, message, created_by, created_at
             FROM submission_register_updates
             WHERE deleted_at IS NULL
               AND id IN ({$latestPlaceholders})",
            str_repeat('i', count($latestUpdateIds)),
            $latestUpdateIds
        );

        foreach ($latestRows as $latestRow) {
            $latestById[(int) ($latestRow['id'] ?? 0)] = $latestRow;
        }
    }

    foreach ($rows as &$row) {
        $submissionId = (int) ($row['id'] ?? 0);
        $summary = $summaryBySubmission[$submissionId] ?? null;
        if ($summary === null) {
            continue;
        }

        $row['updates_count'] = (int) $summary['updates_count'];
        $latest = $latestById[(int) $summary['latest_update_id']] ?? null;
        if ($latest !== null) {
            $row['latest_update_message'] = $latest['message'] ?? null;
            $row['latest_update_at'] = $latest['created_at'] ?? null;
            $row['latest_update_by_name'] = $latest['created_by'] ?? null;
        }
    }
    unset($row);

    return $rows;
}

function submissionRegisterSelectSql(): string
{
    return "SELECT sr.id, sr.submission_reference, sr.project_id, sr.project_code, sr.project_tender_code,
            sr.project_company_name, sr.client_id, sr.client_name, sr.category, sr.date_received,
            sr.date_submitted, sr.mode_of_submission, sr.hard_copy_contact_name,
            sr.hard_copy_contact_email, sr.hard_copy_contact_phone, sr.hard_copy_contact_address,
            sr.email_recipient, sr.status, sr.owner_pms_admin_id, sr.record_status,
            sr.created_by, sr.created_by_id, sr.created_at, sr.updated_by, sr.updated_by_id, sr.updated_at,
            p.project_title AS linked_project_title, p.tender_code AS linked_project_tender_code,
            p.code AS linked_project_code, p.project_status AS linked_project_status, p.progress AS linked_project_progress,
            c.clients_name AS linked_client_name, c.clients_email AS linked_client_email,
            c.clients_hq_location AS linked_client_location, c.clients_category AS linked_client_category,
            NULLIF(TRIM(CONCAT(COALESCE(owner_user.first_name, ''), ' ', COALESCE(owner_user.last_name, ''))), '') AS owner_pms_admin_name,
            owner_user.email AS owner_pms_admin_email,
            NULLIF(TRIM(CONCAT(COALESCE(created_user.first_name, ''), ' ', COALESCE(created_user.last_name, ''))), '') AS created_by_name,
            NULLIF(TRIM(CONCAT(COALESCE(updated_user.first_name, ''), ' ', COALESCE(updated_user.last_name, ''))), '') AS updated_by_name,
            COALESCE(update_summary.total_updates, 0) AS updates_count,
            last_update.message AS latest_update_message,
            last_update.created_at AS latest_update_at,
            NULLIF(TRIM(CONCAT(COALESCE(last_update_user.first_name, ''), ' ', COALESCE(last_update_user.last_name, ''))), '') AS latest_update_by_name
     FROM submission_registers sr
     LEFT JOIN project_info_table p ON p.id = sr.project_id
     LEFT JOIN clients_table c ON c.id = sr.client_id
     LEFT JOIN users owner_user ON owner_user.id = sr.owner_pms_admin_id
     LEFT JOIN users created_user ON created_user.id = sr.created_by_id
     LEFT JOIN users updated_user ON updated_user.id = sr.updated_by_id
     LEFT JOIN (
        SELECT submission_id, COUNT(*) AS total_updates, MAX(id) AS latest_update_id
        FROM submission_register_updates
        WHERE deleted_at IS NULL
        GROUP BY submission_id
     ) update_summary ON update_summary.submission_id = sr.id
     LEFT JOIN submission_register_updates last_update
        ON last_update.id = update_summary.latest_update_id
       AND last_update.submission_id = sr.id
       AND last_update.deleted_at IS NULL
     LEFT JOIN users last_update_user ON last_update_user.id = last_update.created_by_id";
}

function castSubmissionRegisterRecord(array $record): array
{
    $intFields = ['id', 'project_id', 'project_code', 'client_id', 'owner_pms_admin_id', 'created_by_id', 'updated_by_id', 'linked_project_code', 'updates_count'];
    foreach ($intFields as $field) {
        $record[$field] = isset($record[$field]) && $record[$field] !== null ? (int) $record[$field] : null;
    }
    $record['updates_count'] = (int) ($record['updates_count'] ?? 0);
    return $record;
}

function castSubmissionRegisterUpdate(array $record): array
{
    $record['id'] = (int) $record['id'];
    $record['submission_id'] = (int) $record['submission_id'];
    $record['created_by_id'] = isset($record['created_by_id']) && $record['created_by_id'] !== null ? (int) $record['created_by_id'] : null;
    $record['updated_by_id'] = isset($record['updated_by_id']) && $record['updated_by_id'] !== null ? (int) $record['updated_by_id'] : null;
    return $record;
}

function assertSubmissionRegisterAccessible(mysqli $conn, array $authUser, int $id, bool $includeDeleted = false): array
{
    [$scopeSql, $types, $params] = appendScopedWhere($authUser, 'sr', 'i', [$id]);
    $statusSql = $includeDeleted ? '' : " AND sr.record_status = 'active'";

    $record = dbFetchOne(
        $conn,
        submissionRegisterSelectSql() . " WHERE sr.id = ?{$statusSql}{$scopeSql} LIMIT 1",
        $types,
        $params
    );

    if (!$record) {
        throw new RuntimeException('Submission record not found or not accessible.', 404);
    }

    return castSubmissionRegisterRecord($record);
}

function assertSubmissionRegisterUpdateAccessible(mysqli $conn, array $authUser, int $submissionId, int $updateId): array
{
    assertSubmissionRegisterAccessible($conn, $authUser, $submissionId);
    $row = dbFetchOne(
        $conn,
        "SELECT sru.id, sru.submission_id, sru.message, sru.created_by, sru.created_by_id, sru.created_at,
                sru.updated_by, sru.updated_by_id, sru.updated_at,
                NULLIF(TRIM(CONCAT(COALESCE(created_user.first_name, ''), ' ', COALESCE(created_user.last_name, ''))), '') AS created_by_name,
                NULLIF(TRIM(CONCAT(COALESCE(updated_user.first_name, ''), ' ', COALESCE(updated_user.last_name, ''))), '') AS updated_by_name
         FROM submission_register_updates sru
         LEFT JOIN users created_user ON created_user.id = sru.created_by_id
         LEFT JOIN users updated_user ON updated_user.id = sru.updated_by_id
         WHERE sru.id = ? AND sru.submission_id = ? AND sru.deleted_at IS NULL
         LIMIT 1",
        'ii',
        [$updateId, $submissionId]
    );

    if (!$row) {
        throw new RuntimeException('Submission update not found or not accessible.', 404);
    }

    return castSubmissionRegisterUpdate($row);
}

function submissionRegisterUpdates(mysqli $conn, array $authUser, int $submissionId): array
{
    assertSubmissionRegisterAccessible($conn, $authUser, $submissionId);
    $rows = dbFetchAll(
        $conn,
        "SELECT sru.id, sru.submission_id, sru.message, sru.created_by, sru.created_by_id, sru.created_at,
                sru.updated_by, sru.updated_by_id, sru.updated_at,
                NULLIF(TRIM(CONCAT(COALESCE(created_user.first_name, ''), ' ', COALESCE(created_user.last_name, ''))), '') AS created_by_name,
                NULLIF(TRIM(CONCAT(COALESCE(updated_user.first_name, ''), ' ', COALESCE(updated_user.last_name, ''))), '') AS updated_by_name
         FROM submission_register_updates sru
         LEFT JOIN users created_user ON created_user.id = sru.created_by_id
         LEFT JOIN users updated_user ON updated_user.id = sru.updated_by_id
         WHERE sru.submission_id = ? AND sru.deleted_at IS NULL
         ORDER BY sru.created_at ASC, sru.id ASC",
        'i',
        [$submissionId]
    );

    return array_map('castSubmissionRegisterUpdate', $rows);
}

function assertSubmissionProjectAccessible(mysqli $conn, array $authUser, int $projectId): array
{
    [$scopeSql, $types, $params] = appendScopedWhere($authUser, 'p', 'i', [$projectId]);
    $record = dbFetchOne(
        $conn,
        "SELECT p.id, p.code, p.tender_code, p.project_title, p.project_client, p.owner_pms_admin_id, p.record_status
         FROM project_info_table p
         WHERE p.id = ? AND p.record_status = 'active'{$scopeSql}
         LIMIT 1",
        $types,
        $params
    );

    if (!$record) {
        throw new RuntimeException('Selected tender/project not found or not accessible.', 404);
    }

    return $record;
}

function resolveSubmissionOwnerPmsAdminId(mysqli $conn, array $authUser, ?array $project, ?array $client, ?int $requestedOwnerPmsAdminId): ?int
{
    $fixedOwnerId = resolveOwnerPmsAdminId($authUser);
    if ($fixedOwnerId !== null) {
        return $fixedOwnerId;
    }

    $projectOwnerId = isset($project['owner_pms_admin_id']) && (int) $project['owner_pms_admin_id'] > 0
        ? (int) $project['owner_pms_admin_id']
        : null;
    $clientOwnerId = isset($client['owner_pms_admin_id']) && (int) $client['owner_pms_admin_id'] > 0
        ? (int) $client['owner_pms_admin_id']
        : null;

    if ($projectOwnerId !== null && $clientOwnerId !== null && $projectOwnerId !== $clientOwnerId) {
        throw new RuntimeException('The selected project and client belong to different PMS ownership scopes.', 422);
    }

    if ($clientOwnerId !== null) {
        return $clientOwnerId;
    }

    if ($projectOwnerId !== null) {
        return $projectOwnerId;
    }

    return resolveAssignableOwnerPmsAdminId($conn, $authUser, $requestedOwnerPmsAdminId);
}

function normalizeSubmissionRegisterPayload(mysqli $conn, array $authUser, array $payload): array
{
    $projectId = (int) ($payload['project_id'] ?? $payload['tender_id'] ?? 0);
    $clientId = (int) ($payload['client_id'] ?? 0);
    $requestedOwnerId = isset($payload['owner_pms_admin_id']) && $payload['owner_pms_admin_id'] !== ''
        ? (int) $payload['owner_pms_admin_id']
        : null;

    $project = $projectId > 0 ? assertSubmissionProjectAccessible($conn, $authUser, $projectId) : null;
    $client = $clientId > 0 ? assertClientAccessible($conn, $authUser, $clientId) : null;

    $projectCompanyName = cleanString($payload['project_company_name'] ?? $payload['project_name'] ?? $payload['company_name'] ?? '');
    if ($projectCompanyName === '' && $project) {
        $projectCompanyName = cleanString($project['project_title'] ?? '');
    }
    if ($projectCompanyName === '') {
        throw new RuntimeException('Project name / company is required.', 422);
    }
    if ((function_exists('mb_strlen') ? mb_strlen($projectCompanyName) : strlen($projectCompanyName)) > 255) {
        throw new RuntimeException('Project name / company must not exceed 255 characters.', 422);
    }

    $clientName = cleanString($payload['client_name'] ?? $payload['clients_name'] ?? '');
    if ($clientName === '' && $client) {
        $clientName = cleanString($client['clients_name'] ?? '');
    }
    if ($clientName === '' && $project) {
        $clientName = cleanString($project['project_client'] ?? '');
    }
    if ($clientName === '') {
        throw new RuntimeException('Client is required.', 422);
    }
    if ((function_exists('mb_strlen') ? mb_strlen($clientName) : strlen($clientName)) > 255) {
        throw new RuntimeException('Client must not exceed 255 characters.', 422);
    }

    $category = normalizeSubmissionOption((string) ($payload['category'] ?? ''), DYNABASE_SUBMISSION_CATEGORIES, 'submission category');
    $mode = normalizeSubmissionOption((string) ($payload['mode_of_submission'] ?? $payload['submission_mode'] ?? ''), DYNABASE_SUBMISSION_MODES, 'mode of submission');
    $status = normalizeSubmissionOption((string) ($payload['status'] ?? 'Ongoing'), DYNABASE_SUBMISSION_STATUSES, 'submission status');

    $dateReceived = normalizeSubmissionDate($payload, 'date_received', 'Date received');
    $dateSubmitted = normalizeSubmissionDate($payload, 'date_submitted', 'Date submitted');
    if (strtotime($dateSubmitted) < strtotime($dateReceived)) {
        throw new RuntimeException('Date submitted cannot be earlier than date received.', 422);
    }

    $hardCopyContactEmail = optionalEmailField($payload, 'hard_copy_contact_email', 'Hard copy contact email');
    $emailRecipient = optionalEmailField($payload, 'email_recipient', 'Submission email');

    $hardCopyContactName = optionalStringField($payload, 'hard_copy_contact_name', 255);
    $hardCopyContactPhone = optionalStringField($payload, 'hard_copy_contact_phone', 80);
    $hardCopyContactAddress = optionalStringField($payload, 'hard_copy_contact_address', 1000);

    if ($mode === 'Email') {
        $hardCopyContactName = '';
        $hardCopyContactEmail = '';
        $hardCopyContactPhone = '';
        $hardCopyContactAddress = '';
    }

    if ($mode === 'Hard Copy') {
        $emailRecipient = '';
    }

    return [
        'project_id' => $project ? (int) $project['id'] : null,
        'project_code' => $project && isset($project['code']) ? (int) $project['code'] : null,
        'project_tender_code' => $project ? cleanString($project['tender_code'] ?? '') : '',
        'project_company_name' => $projectCompanyName,
        'client_id' => $client ? (int) $client['id'] : null,
        'client_name' => $clientName,
        'category' => $category,
        'date_received' => $dateReceived,
        'date_submitted' => $dateSubmitted,
        'mode_of_submission' => $mode,
        'hard_copy_contact_name' => $hardCopyContactName,
        'hard_copy_contact_email' => $hardCopyContactEmail,
        'hard_copy_contact_phone' => $hardCopyContactPhone,
        'hard_copy_contact_address' => $hardCopyContactAddress,
        'email_recipient' => $emailRecipient,
        'status' => $status,
        'owner_pms_admin_id' => resolveSubmissionOwnerPmsAdminId($conn, $authUser, $project, $client, $requestedOwnerId),
        'project' => $project,
        'client' => $client,
    ];
}

function nextSubmissionReference(mysqli $conn): string
{
    $year = date('Y');
    $prefix = 'SUB-' . $year . '-';
    $row = dbFetchOne(
        $conn,
        "SELECT MAX(CAST(SUBSTRING(submission_reference, ?) AS UNSIGNED)) AS max_sequence
         FROM submission_registers
         WHERE submission_reference LIKE ?",
        'is',
        [strlen($prefix) + 1, $prefix . '%']
    );
    $next = (int) ($row['max_sequence'] ?? 0) + 1;
    return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

function submissionRegisterDetailPayload(mysqli $conn, array $authUser, int $id): array
{
    $record = assertSubmissionRegisterAccessible($conn, $authUser, $id);
    $record['updates'] = submissionRegisterUpdates($conn, $authUser, $id);
    return $record;
}

function submissionRegisterListWhere(array $authUser, array $source): array
{
    $where = " WHERE sr.record_status = 'active'";
    $types = '';
    $params = [];

    $q = cleanString($source['q'] ?? $source['search'] ?? '');
    if ($q !== '') {
        $where .= ' AND (sr.submission_reference LIKE ? OR sr.project_company_name LIKE ? OR sr.client_name LIKE ?
                    OR sr.category LIKE ? OR sr.mode_of_submission LIKE ? OR sr.status LIKE ?
                    OR sr.project_tender_code LIKE ? OR sr.email_recipient LIKE ?)';
        $like = '%' . $q . '%';
        $types .= 'ssssssss';
        for ($index = 0; $index < 8; $index++) {
            $params[] = $like;
        }
    }

    $projectId = (int) ($source['project_id'] ?? 0);
    if ($projectId > 0) {
        $where .= ' AND sr.project_id = ?';
        $types .= 'i';
        $params[] = $projectId;
    }

    $clientId = (int) ($source['client_id'] ?? 0);
    if ($clientId > 0) {
        $where .= ' AND sr.client_id = ?';
        $types .= 'i';
        $params[] = $clientId;
    }

    $category = cleanString($source['category'] ?? '');
    if ($category !== '') {
        $category = normalizeSubmissionOption($category, DYNABASE_SUBMISSION_CATEGORIES, 'submission category');
        $where .= ' AND sr.category = ?';
        $types .= 's';
        $params[] = $category;
    }

    $mode = cleanString($source['mode_of_submission'] ?? $source['submission_mode'] ?? '');
    if ($mode !== '') {
        $mode = normalizeSubmissionOption($mode, DYNABASE_SUBMISSION_MODES, 'mode of submission');
        $where .= ' AND sr.mode_of_submission = ?';
        $types .= 's';
        $params[] = $mode;
    }

    $status = cleanString($source['status'] ?? '');
    if ($status !== '') {
        $status = normalizeSubmissionOption($status, DYNABASE_SUBMISSION_STATUSES, 'submission status');
        $where .= ' AND sr.status = ?';
        $types .= 's';
        $params[] = $status;
    }

    $dateFrom = cleanString($source['date_from'] ?? $source['from'] ?? '');
    if ($dateFrom !== '') {
        $timestamp = strtotime($dateFrom);
        if ($timestamp === false) {
            throw new RuntimeException('Date from must be a valid date.', 422);
        }
        $where .= ' AND sr.date_submitted >= ?';
        $types .= 's';
        $params[] = date('Y-m-d', $timestamp);
    }

    $dateTo = cleanString($source['date_to'] ?? $source['to'] ?? '');
    if ($dateTo !== '') {
        $timestamp = strtotime($dateTo);
        if ($timestamp === false) {
            throw new RuntimeException('Date to must be a valid date.', 422);
        }
        $where .= ' AND sr.date_submitted <= ?';
        $types .= 's';
        $params[] = date('Y-m-d', $timestamp);
    }

    $ownerRaw = cleanString($source['owner_pms_admin_id'] ?? '');
    $ownerId = (int) $ownerRaw;
    if ($ownerRaw === 'global') {
        $where .= ' AND sr.owner_pms_admin_id IS NULL';
    } elseif ($ownerId > 0) {
        $where .= ' AND sr.owner_pms_admin_id = ?';
        $types .= 'i';
        $params[] = $ownerId;
    }

    [$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'sr');
    $where .= $scopeSql;
    $types .= $scopeTypes;
    $params = array_merge($params, $scopeParams);

    return [$where, $types, $params];
}

function submissionRegisterSummary(mysqli $conn, string $where, string $types, array $params): array
{
    $summary = dbFetchOne(
        $conn,
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN sr.status = 'Ongoing' THEN 1 ELSE 0 END) AS ongoing,
                SUM(CASE WHEN sr.status = 'Completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN sr.category = 'Prequalification' THEN 1 ELSE 0 END) AS prequalification,
                SUM(CASE WHEN sr.category = 'Technical' THEN 1 ELSE 0 END) AS technical,
                SUM(CASE WHEN sr.category = 'Registration' THEN 1 ELSE 0 END) AS registration,
                SUM(CASE WHEN sr.mode_of_submission = 'Hard Copy' THEN 1 ELSE 0 END) AS hard_copy,
                SUM(CASE WHEN sr.mode_of_submission = 'Email' THEN 1 ELSE 0 END) AS email,
                SUM(CASE WHEN sr.mode_of_submission = 'Both' THEN 1 ELSE 0 END) AS `both`
         FROM submission_registers sr{$where}",
        $types,
        $params
    ) ?? [];

    foreach (['total', 'ongoing', 'completed', 'prequalification', 'technical', 'registration', 'hard_copy', 'email', 'both'] as $field) {
        $summary[$field] = (int) ($summary[$field] ?? 0);
    }

    return $summary;
}
