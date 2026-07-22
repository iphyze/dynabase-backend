<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/dbHelpers.php';

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
    $ownerPmsAdminId = $requestedOwnerPmsAdminId !== null && $requestedOwnerPmsAdminId > 0
        ? $requestedOwnerPmsAdminId
        : null;

    if ($ownerPmsAdminId === null && userActsAsPmsAdmin($authUser)) {
        $ownerPmsAdminId = (int) ($authUser['id'] ?? 0);
    }

    if ($ownerPmsAdminId === null && userRole($authUser) === DYNABASE_ROLE_PMS_USER) {
        $parentPmsAdminId = (int) ($authUser['parent_pms_admin_id'] ?? 0);
        $ownerPmsAdminId = $parentPmsAdminId > 0 ? $parentPmsAdminId : null;
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
    [$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'k', 'i', [$keypersonId]);
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
