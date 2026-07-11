<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/dbHelpers.php';

function normalizePrequalificationWebsite(mixed $value): string
{
    $website = cleanString($value ?? '');
    if ($website === '') {
        return '';
    }

    $candidate = preg_match('/^https?:\/\//i', $website) ? $website : 'https://' . $website;
    if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Company website must be a valid website address.', 422);
    }

    if ((function_exists('mb_strlen') ? mb_strlen($website) : strlen($website)) > 255) {
        throw new RuntimeException('Company website must not exceed 255 characters.', 422);
    }

    return $website;
}

function normalizePrequalificationPayload(mysqli $conn, array $authUser, array $payload): array
{
    $clientId = (int) ($payload['client_id'] ?? $payload['clients_id'] ?? 0);
    $keypersonId = (int) ($payload['keyperson_id'] ?? 0);
    $client = null;
    $keyperson = null;

    if ($clientId > 0) {
        $client = assertClientAccessible($conn, $authUser, $clientId);
    }

    if ($keypersonId > 0) {
        $keyperson = assertKeypersonAccessible($conn, $authUser, $keypersonId);
        if ($clientId <= 0) {
            $clientId = (int) ($keyperson['clients_id'] ?? 0);
            if ($clientId > 0) {
                $client = assertClientAccessible($conn, $authUser, $clientId);
            }
        } elseif ((int) ($keyperson['clients_id'] ?? 0) !== $clientId) {
            throw new RuntimeException('The selected client representative does not belong to the selected client.', 422);
        }
    }

    $clientsName = cleanString($payload['clients_name'] ?? '');
    if ($clientsName === '' && $client) {
        $clientsName = cleanString($client['clients_name'] ?? '');
    }
    if ($clientsName === '') {
        throw new RuntimeException('Company name is required.', 422);
    }
    if ((function_exists('mb_strlen') ? mb_strlen($clientsName) : strlen($clientsName)) > 255) {
        throw new RuntimeException('Company name must not exceed 255 characters.', 422);
    }

    $clientsAddress = optionalStringField($payload, 'clients_address', 1000);
    if ($clientsAddress === '' && $client) {
        $clientsAddress = cleanString($client['clients_address'] ?? '');
    }

    $clientsEmail = optionalEmailField($payload, 'clients_email', 'Company email');
    if ($clientsEmail === '' && $client) {
        $clientsEmail = cleanEmail($client['clients_email'] ?? '');
    }

    $clientsWebsite = normalizePrequalificationWebsite($payload['clients_website'] ?? '');
    if ($clientsWebsite === '' && $client) {
        $clientsWebsite = cleanString($client['clients_website'] ?? '');
    }

    $keyPerson = optionalStringField($payload, 'key_person', 255);
    if ($keyPerson === '' && $keyperson) {
        $keyPerson = cleanString($keyperson['key_person'] ?? '');
    }

    $keyPersonsTel = optionalStringField($payload, 'key_persons_tel', 255);
    if ($keyPersonsTel === '' && $keyperson) {
        $keyPersonsTel = cleanString($keyperson['key_persons_tel'] ?? '');
    }

    $title = optionalStringField($payload, 'title', 255);
    if ($title === '' && $keyperson) {
        $title = cleanString($keyperson['title'] ?? '');
    }

    return [
        'clients_id' => $clientId,
        'clients_name' => $clientsName,
        'clients_address' => $clientsAddress,
        'clients_email' => $clientsEmail,
        'clients_phone' => optionalStringField($payload, 'clients_phone', 255),
        'clients_website' => $clientsWebsite,
        'keyperson_id' => $keypersonId > 0 ? $keypersonId : null,
        'key_person' => $keyPerson,
        'key_persons_tel' => $keyPersonsTel,
        'title' => $title,
        'business_info' => requireStringField($payload, 'business_info', 'Client business information', 5000),
        'prospective_project' => requireStringField($payload, 'prospective_project', 'Prospective project', 2000),
        'budget' => optionalStringField($payload, 'budget', 255),
        'services' => requireStringField($payload, 'services', 'Services required from Lambert', 5000),
        'remarks' => requireStringField($payload, 'remarks', 'Remarks / next steps', 5000),
        'owner_pms_admin_id' => $client && $client['owner_pms_admin_id'] !== null
            ? (int) $client['owner_pms_admin_id']
            : null,
        'client' => $client,
        'keyperson' => $keyperson,
    ];
}

function prequalificationSelectSql(): string
{
    return "SELECT p.id, p.clients_id, p.clients_name, p.clients_address, p.clients_email,
            p.clients_phone, p.clients_website, p.keyperson_id, p.key_person, p.key_persons_tel,
            p.title, p.business_info, p.prospective_project, p.budget, p.services, p.remarks,
            p.owner_pms_admin_id, p.record_status, p.created_by, p.created_by_id, p.created_at,
            p.updated_by, p.updated_by_id, p.updated_at,
            c.clients_name AS linked_client_name, c.clients_category, c.clients_hq_location,
            c.status AS linked_client_status,
            k.key_person AS linked_keyperson_name, k.key_persons_email AS linked_keyperson_email,
            k.key_persons_tel AS linked_keyperson_phone, k.title AS linked_keyperson_title,
            NULLIF(TRIM(CONCAT(COALESCE(owner.first_name, ''), ' ', COALESCE(owner.last_name, ''))), '') AS owner_pms_admin_name,
            owner.email AS owner_pms_admin_email,
            NULLIF(TRIM(CONCAT(COALESCE(created_user.first_name, ''), ' ', COALESCE(created_user.last_name, ''))), '') AS created_by_name,
            NULLIF(TRIM(CONCAT(COALESCE(updated_user.first_name, ''), ' ', COALESCE(updated_user.last_name, ''))), '') AS updated_by_name
     FROM prequalification_table p
     LEFT JOIN clients_table c ON c.id = p.clients_id
     LEFT JOIN keypersons_table k ON k.id = p.keyperson_id
     LEFT JOIN users owner ON owner.id = p.owner_pms_admin_id
     LEFT JOIN users created_user ON created_user.id = p.created_by_id
     LEFT JOIN users updated_user ON updated_user.id = p.updated_by_id";
}

function castPrequalificationRecord(array $record): array
{
    $record['id'] = (int) $record['id'];
    $record['clients_id'] = (int) ($record['clients_id'] ?? 0);
    $record['client_id'] = $record['clients_id'];
    $record['keyperson_id'] = $record['keyperson_id'] !== null ? (int) $record['keyperson_id'] : null;
    $record['owner_pms_admin_id'] = $record['owner_pms_admin_id'] !== null ? (int) $record['owner_pms_admin_id'] : null;
    $record['created_by_id'] = $record['created_by_id'] !== null ? (int) $record['created_by_id'] : null;
    $record['updated_by_id'] = $record['updated_by_id'] !== null ? (int) $record['updated_by_id'] : null;
    return $record;
}

function assertPrequalificationAccessible(mysqli $conn, array $authUser, int $id, bool $includeDeleted = false): array
{
    [$scopeSql, $types, $params] = appendScopedWhere($authUser, 'p', 'i', [$id]);
    $statusSql = $includeDeleted ? '' : " AND p.record_status = 'active'";

    $record = dbFetchOne(
        $conn,
        prequalificationSelectSql() . " WHERE p.id = ?{$statusSql}{$scopeSql} LIMIT 1",
        $types,
        $params
    );

    if (!$record) {
        throw new RuntimeException('Prequalification record not found or not accessible.', 404);
    }

    return castPrequalificationRecord($record);
}

function assertNoDuplicatePrequalification(mysqli $conn, array $record, ?int $ignoreId = null): void
{
    $sql = "SELECT id FROM prequalification_table WHERE record_status = 'active'";
    $types = '';
    $params = [];

    if ((int) $record['clients_id'] > 0) {
        $sql .= ' AND clients_id = ?';
        $types .= 'i';
        $params[] = (int) $record['clients_id'];
    } else {
        $sql .= ' AND LOWER(TRIM(clients_name)) = LOWER(TRIM(?))';
        $types .= 's';
        $params[] = $record['clients_name'];
    }

    if ($ignoreId !== null && $ignoreId > 0) {
        $sql .= ' AND id <> ?';
        $types .= 'i';
        $params[] = $ignoreId;
    }

    $sql .= ' LIMIT 1';
    if (dbFetchOne($conn, $sql, $types, $params)) {
        throw new RuntimeException('An active prequalification checklist already exists for this company.', 409);
    }
}
