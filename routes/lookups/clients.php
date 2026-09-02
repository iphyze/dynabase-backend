<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/lookup.php';
require_once __DIR__ . '/../../includes/permissions.php';

requireMethod('GET');
$authUser = authenticateUser();

$q = lookupSearchTerm();
$category = cleanString($_GET['category'] ?? '');
$status = cleanString($_GET['status'] ?? 'active');
$limit = lookupLimit(100, 100);
$offset = lookupOffset();
$includeOther = cleanString($_GET['include_other'] ?? '') === '1';
$ownerPmsAdminId = (int) ($_GET['owner_pms_admin_id'] ?? 0);
$referenceScope = cleanString($_GET['reference_scope'] ?? '');
$role = userRole($authUser);
$fixedOwnerPmsAdminId = resolveOwnerPmsAdminId($authUser);
$canViewFullOwnerDirectory = userHasRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN])
    || ($role === DYNABASE_ROLE_USER
        && (userHasPermission($conn, $authUser, 'clients.create')
            || userHasPermission($conn, $authUser, 'clients.edit')));

if ($referenceScope !== '' && !in_array($referenceScope, ['keypersons', 'gift_lists'], true)) {
    throw new RuntimeException('Invalid client reference scope.', 422);
}

$isSharedReference = in_array($referenceScope, ['keypersons', 'gift_lists'], true);
if ($isSharedReference) {
    $canUseReference = $referenceScope === 'keypersons'
        ? (userHasPermission($conn, $authUser, 'keypersons.create') || userHasPermission($conn, $authUser, 'keypersons.edit'))
        : (userHasPermission($conn, $authUser, 'gift_lists.create') || userHasPermission($conn, $authUser, 'gift_lists.edit'));
    if (!$canUseReference) {
        throw new RuntimeException('You are not authorised to use the shared client reference lookup.', 403);
    }

    if ($status !== '' && $status !== 'active') {
        throw new RuntimeException('Shared client references only include active clients.', 422);
    }

    if ($ownerPmsAdminId > 0) {
        throw new RuntimeException('PMS ownership filters are not available in shared client references.', 422);
    }
}

$where = ' WHERE 1 = 1';
$types = '';
$params = [];

if ($status !== '' && $status !== 'all') {
    if (!in_array($status, ['active', 'inactive', 'deactivated'], true)) {
        throw new RuntimeException('Invalid status filter.', 422);
    }
    $where .= ' AND c.status = ?';
    $types .= 's';
    $params[] = $status;
}

if ($q !== '') {
    $where .= ' AND (c.clients_name LIKE ? OR c.clients_email LIKE ? OR c.clients_hq_location LIKE ? OR c.clients_category LIKE ?)';
    $like = likeTerm($q);
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}

if ($category !== '') {
    $where .= ' AND c.clients_category = ?';
    $types .= 's';
    $params[] = $category;
}

if (!$isSharedReference && $ownerPmsAdminId > 0) {
    if ($canViewFullOwnerDirectory) {
        $where .= ' AND c.owner_pms_admin_id = ?';
        $types .= 'i';
        $params[] = $ownerPmsAdminId;
    } elseif ($fixedOwnerPmsAdminId !== null && $fixedOwnerPmsAdminId > 0) {
        if ($ownerPmsAdminId !== $fixedOwnerPmsAdminId) {
            throw new RuntimeException('You are not authorised to filter clients by another PMS Admin.', 403);
        }
    } else {
        throw new RuntimeException('You are not authorised to filter clients by PMS ownership.', 403);
    }
}

if (!$isSharedReference) {
    [$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'c');
    $where .= $scopeSql;
    $types .= $scopeTypes;
    $params = array_merge($params, $scopeParams);
}

$total = dbScalarInt($conn, "SELECT COUNT(*) AS total FROM clients_table c{$where}", $types, $params);
$rows = dbFetchAll(
    $conn,
    "SELECT c.id, c.clients_name, c.clients_email, c.clients_website, c.clients_hq_location, c.clients_category,
            c.clients_address, c.owner_pms_admin_id
     FROM clients_table c
     {$where}
     ORDER BY c.clients_name ASC
     LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

$data = array_map(static function (array $row) use (
    $isSharedReference,
    $canViewFullOwnerDirectory,
    $fixedOwnerPmsAdminId
): array {
    $meta = [
        'id' => (int) $row['id'],
        'clients_name' => $row['clients_name'],
        'clients_email' => $row['clients_email'],
        'clients_website' => $row['clients_website'],
        'clients_hq_location' => $row['clients_hq_location'],
        'clients_category' => $row['clients_category'],
        'clients_address' => $row['clients_address'],
    ];

    // Shared references deliberately do not expose PMS ownership information.
    // Normal lookups expose ownership only to users whose role/scope requires it.
    if (!$isSharedReference && $canViewFullOwnerDirectory) {
        $meta['owner_pms_admin_id'] = $row['owner_pms_admin_id'] !== null ? (int) $row['owner_pms_admin_id'] : null;
    } elseif (!$isSharedReference && $fixedOwnerPmsAdminId !== null && $fixedOwnerPmsAdminId > 0) {
        $meta['owner_pms_admin_id'] = $fixedOwnerPmsAdminId;
    }

    return optionRow((int) $row['id'], (string) $row['clients_name'], $meta);
}, $rows);

if ($includeOther && $offset === 0) {
    $otherLabel = $category !== '' ? 'Other ' . $category : 'Other Client';
    $data[] = optionRow('__other__', $otherLabel, ['is_other' => true, 'category' => $category]);
}

lookupResponse('Client lookup retrieved successfully.', $data, $limit, $offset, $total);
