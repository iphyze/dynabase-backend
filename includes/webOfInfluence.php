<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/dbHelpers.php';

const WOI_AUTHORITIES = ['Hire Us', 'Fire Us', 'Influence Decision'];
const WOI_LEVELS = ['Low', 'Med Low', 'Med', 'Med High', 'High'];
const WOI_RECOMMENDATIONS = ['Yes', 'No'];

function normalizeWoiChoice(mixed $value, string $label, array $allowed): string
{
    $clean = cleanString($value ?? '');
    if ($clean === '') {
        throw new RuntimeException("{$label} is required.", 422);
    }

    foreach ($allowed as $option) {
        if (strcasecmp($clean, $option) === 0) {
            return $option;
        }
    }

    throw new RuntimeException("Invalid {$label} value.", 422);
}

function assertWoiProjectAccessible(mysqli $conn, array $authUser, int $projectId, bool $includeInactive = false): array
{
    [$scopeSql, $types, $params] = appendScopedWhere($authUser, 'p', 'i', [$projectId]);
    $statusSql = $includeInactive ? '' : " AND p.record_status = 'active'";

    $project = dbFetchOne(
        $conn,
        "SELECT p.* FROM project_info_table p WHERE p.id = ?{$statusSql}{$scopeSql} LIMIT 1",
        $types,
        $params
    );

    if (!$project) {
        throw new RuntimeException('Tender/project not found or not accessible.', 404);
    }

    return $project;
}

function normalizeWoiPayload(mysqli $conn, array $authUser, array $payload): array
{
    $clientId = requiredIntFromPayload($payload, 'client_id', 'Client');
    $projectId = requiredIntFromPayload($payload, 'project_id', 'Tender/project');
    $keypersonId = (int) ($payload['keyperson_id'] ?? 0);

    $client = assertClientAccessible($conn, $authUser, $clientId);
    $project = assertWoiProjectAccessible($conn, $authUser, $projectId);
    $keyperson = null;

    if ($keypersonId > 0) {
        $keyperson = assertKeypersonAccessible($conn, $authUser, $keypersonId);
        if ((int) ($keyperson['clients_id'] ?? 0) !== $clientId) {
            throw new RuntimeException('The selected key person does not belong to the selected client.', 422);
        }
    }

    $stakeholderName = $keyperson
        ? cleanString($keyperson['key_person'] ?? '')
        : requireStringField($payload, 'stakeholder_name', 'Stakeholder name', 255);

    if ($stakeholderName === '') {
        throw new RuntimeException('Stakeholder name is required.', 422);
    }

    $stakeholderRole = optionalStringField($payload, 'stakeholder_role', 255);
    if ($stakeholderRole === '' && $keyperson) {
        $stakeholderRole = cleanString($keyperson['title'] ?? '');
    }

    $phone = optionalStringField($payload, 'phone', 100);
    if ($phone === '' && $keyperson) {
        $phone = cleanString($keyperson['key_persons_tel'] ?? '');
    }

    $email = optionalEmailField($payload, 'email', 'Stakeholder email');
    if ($email === '' && $keyperson) {
        $email = cleanEmail($keyperson['key_persons_email'] ?? '');
    }

    return [
        'client_id' => $clientId,
        'project_id' => $projectId,
        'keyperson_id' => $keypersonId > 0 ? $keypersonId : null,
        'stakeholder_name' => $stakeholderName,
        'stakeholder_role' => $stakeholderRole,
        'dlp_period' => optionalStringField($payload, 'dlp_period', 255),
        'project_director' => optionalStringField($payload, 'project_director', 255),
        'country_manager' => optionalStringField($payload, 'country_manager', 255),
        'phone' => $phone,
        'email' => $email,
        'authority' => normalizeWoiChoice($payload['authority'] ?? '', 'authority', WOI_AUTHORITIES),
        'influence_level' => normalizeWoiChoice($payload['influence_level'] ?? '', 'influence level', WOI_LEVELS),
        'company_recommendation' => normalizeWoiChoice($payload['company_recommendation'] ?? '', 'company recommendation', WOI_RECOMMENDATIONS),
        'personal_win' => normalizeWoiChoice($payload['personal_win'] ?? '', 'personal win', WOI_LEVELS),
        'notes' => optionalStringField($payload, 'notes', 5000),
        'owner_pms_admin_id' => $client['owner_pms_admin_id'] !== null ? (int) $client['owner_pms_admin_id'] : null,
        'client' => $client,
        'project' => $project,
        'keyperson' => $keyperson,
    ];
}

function woiRecordSelectSql(): string
{
    return "SELECT w.id, w.client_id, w.project_id, w.keyperson_id, w.stakeholder_name,
            w.stakeholder_role, w.dlp_period, w.project_director, w.country_manager,
            w.phone, w.email, w.authority, w.influence_level, w.company_recommendation,
            w.personal_win, w.notes, w.owner_pms_admin_id, w.record_status,
            w.created_by, w.created_by_id, w.created_at, w.updated_by, w.updated_by_id, w.updated_at,
            c.clients_name, c.clients_category, c.clients_hq_location, c.clients_email,
            p.project_title, p.code AS project_code, p.tender_code, p.project_country, p.project_city, p.division,
            p.project_status, p.progress,
            k.key_person AS linked_keyperson_name, k.title AS linked_keyperson_title,
            k.key_persons_email AS linked_keyperson_email, k.key_persons_tel AS linked_keyperson_phone,
            NULLIF(TRIM(CONCAT(COALESCE(owner.first_name, ''), ' ', COALESCE(owner.last_name, ''))), '') AS owner_pms_admin_name,
            owner.email AS owner_pms_admin_email,
            NULLIF(TRIM(CONCAT(COALESCE(created_user.first_name, ''), ' ', COALESCE(created_user.last_name, ''))), '') AS created_by_name,
            NULLIF(TRIM(CONCAT(COALESCE(updated_user.first_name, ''), ' ', COALESCE(updated_user.last_name, ''))), '') AS updated_by_name
     FROM web_of_influence_table w
     LEFT JOIN clients_table c ON c.id = w.client_id
     LEFT JOIN project_info_table p ON p.id = w.project_id
     LEFT JOIN keypersons_table k ON k.id = w.keyperson_id
     LEFT JOIN users owner ON owner.id = w.owner_pms_admin_id
     LEFT JOIN users created_user ON created_user.id = w.created_by_id
     LEFT JOIN users updated_user ON updated_user.id = w.updated_by_id";
}

function assertWoiRecordAccessible(mysqli $conn, array $authUser, int $id, bool $includeDeleted = false): array
{
    [$scopeSql, $types, $params] = appendScopedWhere($authUser, 'w', 'i', [$id]);
    $statusSql = $includeDeleted ? '' : " AND w.record_status = 'active'";

    $record = dbFetchOne(
        $conn,
        woiRecordSelectSql() . " WHERE w.id = ?{$statusSql}{$scopeSql} LIMIT 1",
        $types,
        $params
    );

    if (!$record) {
        throw new RuntimeException('Web of Influence record not found or not accessible.', 404);
    }

    $record['id'] = (int) $record['id'];
    $record['client_id'] = (int) $record['client_id'];
    $record['project_id'] = (int) $record['project_id'];
    $record['keyperson_id'] = $record['keyperson_id'] !== null ? (int) $record['keyperson_id'] : null;
    $record['owner_pms_admin_id'] = $record['owner_pms_admin_id'] !== null ? (int) $record['owner_pms_admin_id'] : null;
    $record['created_by_id'] = $record['created_by_id'] !== null ? (int) $record['created_by_id'] : null;
    $record['updated_by_id'] = $record['updated_by_id'] !== null ? (int) $record['updated_by_id'] : null;

    return $record;
}

function assertNoDuplicateWoi(mysqli $conn, array $record, ?int $ignoreId = null): void
{
    $sql = "SELECT id FROM web_of_influence_table
            WHERE client_id = ? AND project_id = ? AND LOWER(TRIM(stakeholder_name)) = LOWER(TRIM(?))
              AND record_status = 'active'";
    $types = 'iis';
    $params = [$record['client_id'], $record['project_id'], $record['stakeholder_name']];

    if ($ignoreId !== null && $ignoreId > 0) {
        $sql .= ' AND id <> ?';
        $types .= 'i';
        $params[] = $ignoreId;
    }

    $sql .= ' LIMIT 1';
    if (dbFetchOne($conn, $sql, $types, $params)) {
        throw new RuntimeException('This stakeholder already has a Web of Influence record for the selected tender/project.', 409);
    }
}
