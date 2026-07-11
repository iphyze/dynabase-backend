<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/lookup.php';

requireMethod('GET');
$authUser = authenticateUser();

$q = lookupSearchTerm();
$category = cleanString($_GET['category'] ?? '');
$status = cleanString($_GET['status'] ?? 'active');
$limit = lookupLimit(100, 100);
$offset = lookupOffset();
$includeOther = cleanString($_GET['include_other'] ?? '') === '1';
$ownerPmsAdminId = (int) ($_GET['owner_pms_admin_id'] ?? 0);

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

if ($ownerPmsAdminId > 0 && isGlobalDataUser($authUser)) {
    $where .= ' AND c.owner_pms_admin_id = ?';
    $types .= 'i';
    $params[] = $ownerPmsAdminId;
}

[$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'c');
$where .= $scopeSql;
$types .= $scopeTypes;
$params = array_merge($params, $scopeParams);

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

$data = array_map(static fn (array $row): array => optionRow((int) $row['id'], (string) $row['clients_name'], [
    'id' => (int) $row['id'],
    'clients_name' => $row['clients_name'],
    'clients_email' => $row['clients_email'],
    'clients_website' => $row['clients_website'],
    'clients_hq_location' => $row['clients_hq_location'],
    'clients_category' => $row['clients_category'],
    'clients_address' => $row['clients_address'],
    'owner_pms_admin_id' => $row['owner_pms_admin_id'] !== null ? (int) $row['owner_pms_admin_id'] : null,
]), $rows);

if ($includeOther && $offset === 0) {
    $otherLabel = $category !== '' ? 'Other ' . $category : 'Other Client';
    $data[] = optionRow('__other__', $otherLabel, ['is_other' => true, 'category' => $category]);
}

lookupResponse('Client lookup retrieved successfully.', $data, $limit, $offset, $total);
