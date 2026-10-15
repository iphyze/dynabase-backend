<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/dbHelpers.php';
require_once __DIR__ . '/keypersons.php';

function isGlobalDataUser(array $authUser): bool
{
    return userHasRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN, DYNABASE_ROLE_USER]);
}

function resolveAssignableOwnerPmsAdminId(mysqli $conn, array $authUser, ?int $requestedOwnerPmsAdminId = null): ?int
{
    $fixedOwnerId = resolveOwnerPmsAdminId($authUser);
    if ($fixedOwnerId !== null) {
        return $fixedOwnerId;
    }

    if ($requestedOwnerPmsAdminId === null || $requestedOwnerPmsAdminId <= 0) {
        return null;
    }

    $pmsAdmin = dbFetchOne(
        $conn,
        "SELECT id FROM users WHERE id = ? AND status = 'active' AND (role = 'pms_admin' OR is_pms_admin = 1) LIMIT 1",
        'i',
        [$requestedOwnerPmsAdminId]
    );

    if (!$pmsAdmin) {
        throw new RuntimeException('The selected PMS Admin is not available.', 422);
    }

    return (int) $pmsAdmin['id'];
}


function resolveClientOwnerPmsAdminId(mysqli $conn, array $authUser, ?int $requestedOwnerPmsAdminId = null): int
{
    $role = userRole($authUser);

    // PMS-scoped users cannot transfer ownership by posting another PMS Admin ID.
    // Their ownership scope is fixed server-side and the lookup endpoint mirrors it.
    if (userActsAsPmsAdmin($authUser)
        && !userHasRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN])) {
        $ownerPmsAdminId = (int) ($authUser['id'] ?? 0);
    } elseif ($role === DYNABASE_ROLE_PMS_USER) {
        $parentPmsAdminId = (int) ($authUser['parent_pms_admin_id'] ?? 0);
        $ownerPmsAdminId = $parentPmsAdminId > 0 ? $parentPmsAdminId : null;
    } else {
        $ownerPmsAdminId = $requestedOwnerPmsAdminId !== null && $requestedOwnerPmsAdminId > 0
            ? $requestedOwnerPmsAdminId
            : null;
    }

    if ($ownerPmsAdminId === null || $ownerPmsAdminId <= 0) {
        throw new RuntimeException('Please assign this client to a PMS Admin.', 422);
    }

    $pmsAdmin = dbFetchOne(
        $conn,
        "SELECT id FROM users WHERE id = ? AND status = 'active' AND (role = 'pms_admin' OR is_pms_admin = 1) LIMIT 1",
        'i',
        [$ownerPmsAdminId]
    );

    if (!$pmsAdmin) {
        throw new RuntimeException('The selected PMS Admin is not available.', 422);
    }

    return (int) $pmsAdmin['id'];
}

function fetchClientRecordById(mysqli $conn, int $clientId, bool $includeInactive = false): array
{
    $statusSql = $includeInactive ? '' : " AND status = 'active'";
    $client = dbFetchOne(
        $conn,
        "SELECT * FROM clients_table WHERE id = ?{$statusSql} LIMIT 1",
        'i',
        [$clientId]
    );

    if (!$client) {
        throw new RuntimeException('Client not found.', 404);
    }

    return $client;
}

function scopedRecordWhere(array $authUser, string $alias = ''): array
{
    return buildPmsOwnershipWhereClause($authUser, $alias);
}

function appendScopedWhere(array $authUser, string $alias, string $types = '', array $params = []): array
{
    [$scopeSql, $scopeParams] = scopedRecordWhere($authUser, $alias);
    if ($scopeSql === '') {
        return ['', $types, $params];
    }

    foreach ($scopeParams as $scopeParam) {
        $types .= 'i';
        $params[] = $scopeParam;
    }

    return [$scopeSql, $types, $params];
}

function ownerDuplicateSql(?int $ownerPmsAdminId, string $alias = ''): array
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    if ($ownerPmsAdminId !== null && $ownerPmsAdminId > 0) {
        return [" AND {$prefix}owner_pms_admin_id = ?", 'i', [$ownerPmsAdminId]];
    }

    return [" AND {$prefix}owner_pms_admin_id IS NULL", '', []];
}

function scopedKeypersonWhere(array $authUser, string $alias = 'k'): array
{
    if (isGlobalDataUser($authUser)) {
        return ['', []];
    }

    $ownerPmsAdminId = resolveOwnerPmsAdminId($authUser);
    if ($ownerPmsAdminId === null || $ownerPmsAdminId <= 0) {
        return [' AND 1 = 0', []];
    }

    $prefix = $alias !== '' ? $alias . '.' : '';
    return [
        " AND EXISTS (SELECT 1 FROM keyperson_pms_assignments kpa_scope WHERE kpa_scope.keyperson_id = {$prefix}id AND kpa_scope.pms_admin_id = ?)",
        [$ownerPmsAdminId],
    ];
}

function appendKeypersonScopedWhere(array $authUser, string $alias = 'k', string $types = '', array $params = []): array
{
    [$scopeSql, $scopeParams] = scopedKeypersonWhere($authUser, $alias);
    foreach ($scopeParams as $scopeParam) {
        $types .= 'i';
        $params[] = $scopeParam;
    }
    return [$scopeSql, $types, $params];
}

function assertClientAccessible(mysqli $conn, array $authUser, int $clientId, bool $includeInactive = false): array
{
    [$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'c', 'i', [$clientId]);
    $statusSql = $includeInactive ? '' : " AND c.status = 'active'";

    $client = dbFetchOne(
        $conn,
        "SELECT c.* FROM clients_table c WHERE c.id = ?{$statusSql}{$scopeSql} LIMIT 1",
        $scopeTypes,
        $scopeParams
    );

    if (!$client) {
        throw new RuntimeException('Client not found or not accessible.', 404);
    }

    return $client;
}

function assertKeypersonAccessible(mysqli $conn, array $authUser, int $keypersonId, bool $includeInactive = false): array
{
    [$scopeSql, $scopeTypes, $scopeParams] = appendKeypersonScopedWhere($authUser, 'k', 'i', [$keypersonId]);
    $statusSql = $includeInactive ? '' : " AND k.status = 'active'";

    $keyperson = dbFetchOne(
        $conn,
        "SELECT k.* FROM keypersons_table k WHERE k.id = ?{$statusSql}{$scopeSql} LIMIT 1",
        $scopeTypes,
        $scopeParams
    );

    if (!$keyperson) {
        throw new RuntimeException('Keyperson not found or not accessible.', 404);
    }

    return $keyperson;
}

function assertLogAccessible(mysqli $conn, array $authUser, int $logId): array
{
    [$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'l', 'i', [$logId]);

    $log = dbFetchOne(
        $conn,
        "SELECT l.* FROM log_table l WHERE l.id = ?{$scopeSql} LIMIT 1",
        $scopeTypes,
        $scopeParams
    );

    if (!$log) {
        throw new RuntimeException('Log not found or not accessible.', 404);
    }

    return $log;
}

function actorEmail(array $authUser): string
{
    return strtolower(trim((string) ($authUser['email'] ?? '')));
}

/** Case- and spacing-insensitive client name, identical to the generated clients_table.normalized_name column
 *  (LOWER(TRIM(REGEXP_REPLACE(clients_name, '[[:space:]]+', ' ')))) that carries the unique key. */
function normalizeClientName(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

/**
 * Clients are one shared directory across every PMS Admin: a name may exist only once
 * (active or deactivated). Throws a 409 that names the existing record so the user can pick it.
 */
function assertClientNameAvailable(mysqli $conn, string $clientsName, ?int $excludeId = null): void
{
    $types = 's';
    $params = [normalizeClientName($clientsName)];
    $exclude = '';
    if ($excludeId !== null && $excludeId > 0) {
        $exclude = ' AND c.id <> ?';
        $types .= 'i';
        $params[] = $excludeId;
    }
    $existing = dbFetchOne(
        $conn,
        "SELECT c.id, c.clients_name, c.status, c.owner_pms_admin_id,
                NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), '') AS owner_name
         FROM clients_table c
         LEFT JOIN users u ON u.id = c.owner_pms_admin_id
         WHERE c.normalized_name = ?{$exclude}
         LIMIT 1",
        $types,
        $params
    );
    if (!$existing) {
        return;
    }
    $name = (string) $existing['clients_name'];
    $message = (string) $existing['status'] === 'active'
        ? sprintf('"%s" already exists in Dynabase%s. Select the existing client instead of creating a new one.',
            $name, $existing['owner_name'] ? ' (managed by ' . $existing['owner_name'] . ')' : '')
        : sprintf('"%s" already exists in Dynabase but is deactivated. Ask an administrator to reactivate it.', $name);

    jsonResponse([
        'status' => 'DuplicateClient',
        'message' => $message,
        'data' => ['existing_client' => [
            'id' => (int) $existing['id'],
            'clients_name' => $name,
            'status' => (string) $existing['status'],
            'owner_pms_admin_id' => $existing['owner_pms_admin_id'] !== null ? (int) $existing['owner_pms_admin_id'] : null,
            'owner_name' => $existing['owner_name'],
        ]],
    ], 409);
}

/** Turns the database's unique-name guard (concurrent saves) into the same friendly 409. */
function isDuplicateKeyError(Throwable $exception): bool
{
    return $exception instanceof mysqli_sql_exception && (int) $exception->getCode() === 1062;
}
