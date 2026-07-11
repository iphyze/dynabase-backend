<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/pagination.php';

requireMethod('GET');
$authUser = authenticateUser();

$q = cleanString($_GET['search'] ?? ($_GET['q'] ?? ''));
$clientId = (int) ($_GET['client_id'] ?? 0);
$keypersonId = (int) ($_GET['keyperson_id'] ?? 0);
$keyPerson = cleanString($_GET['key_person'] ?? '');
$category = cleanString($_GET['category'] ?? '');
$ownerPmsAdminRaw = cleanString($_GET['owner_pms_admin_id'] ?? '');
$ownerPmsAdminId = (int) $ownerPmsAdminRaw;
$sort = cleanString($_GET['sort'] ?? 'created_at');
$order = strtolower(cleanString($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
[$page, $limit, $offset] = paginationParams();

$sortMap = [
    'created_at' => 'l.created_at',
    'updated_at' => 'l.updated_at',
    'client' => 'l.clients_name',
    'key_person' => 'l.key_person',
    'category' => 'l.clients_category',
    'owner' => 'owner_pms_admin_name',
];
$orderBy = $sortMap[$sort] ?? $sortMap['created_at'];

if ($keypersonId > 0) {
    $selectedKeyperson = assertKeypersonAccessible($conn, $authUser, $keypersonId, true);
    $clientId = (int) $selectedKeyperson['clients_id'];
    $keyPerson = (string) $selectedKeyperson['key_person'];
}

$where = ' WHERE 1 = 1';
$types = '';
$params = [];

if ($clientId > 0) {
    $where .= ' AND l.clients_id = ?';
    $types .= 'i';
    $params[] = $clientId;
}

if ($keyPerson !== '') {
    $where .= ' AND l.key_person = ?';
    $types .= 's';
    $params[] = $keyPerson;
}

if ($category !== '' && $category !== 'all') {
    $where .= ' AND l.clients_category = ?';
    $types .= 's';
    $params[] = $category;
}

if (isGlobalDataUser($authUser) && $ownerPmsAdminRaw === 'global') {
    $where .= ' AND l.owner_pms_admin_id IS NULL';
} elseif ($ownerPmsAdminId > 0 && isGlobalDataUser($authUser)) {
    $where .= ' AND l.owner_pms_admin_id = ?';
    $types .= 'i';
    $params[] = $ownerPmsAdminId;
}

if ($q !== '') {
    $where .= ' AND (l.clients_name LIKE ? OR l.key_person LIKE ? OR l.clients_hq_location LIKE ? OR l.clients_category LIKE ? OR l.log LIKE ? OR l.created_by LIKE ? OR l.updated_by LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'sssssss';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}

[$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'l');
$where .= $scopeSql;
$types .= $scopeTypes;
$params = array_merge($params, $scopeParams);

$total = dbScalarInt($conn, "SELECT COUNT(*) AS total FROM log_table l{$where}", $types, $params);

$summary = dbFetchOne(
    $conn,
    "SELECT
        COUNT(*) AS total,
        COUNT(DISTINCT l.clients_id) AS linked_clients,
        COUNT(DISTINCT l.key_person) AS key_people,
        SUM(CASE WHEN DATE(l.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recent_logs
     FROM log_table l{$where}",
    $types,
    $params
) ?? [];

$listTypes = $types . 'ii';
$listParams = array_merge($params, [$limit, $offset]);
$rows = dbFetchAll(
    $conn,
    "SELECT l.id, l.clients_id, l.clients_name, l.key_person, l.clients_hq_location, l.clients_category,
            l.log, l.created_by, l.created_by_id, l.updated_by, l.updated_by_id, l.owner_pms_admin_id,
            l.created_at, l.updated_at,
            NULLIF(TRIM(CONCAT(COALESCE(owner.first_name, ''), ' ', COALESCE(owner.last_name, ''))), '') AS owner_pms_admin_name,
            owner.email AS owner_pms_admin_email,
            NULLIF(TRIM(CONCAT(COALESCE(created_user.first_name, ''), ' ', COALESCE(created_user.last_name, ''))), '') AS created_by_name,
            NULLIF(TRIM(CONCAT(COALESCE(updated_user.first_name, ''), ' ', COALESCE(updated_user.last_name, ''))), '') AS updated_by_name,
            k.id AS keyperson_id,
            k.title AS keyperson_title,
            k.key_persons_email,
            k.key_persons_tel,
            c.status AS client_status,
            k.status AS keyperson_status
     FROM log_table l
     LEFT JOIN users owner ON owner.id = l.owner_pms_admin_id
     LEFT JOIN users created_user ON created_user.id = l.created_by_id
     LEFT JOIN users updated_user ON updated_user.id = l.updated_by_id
     LEFT JOIN clients_table c ON c.id = l.clients_id
     LEFT JOIN keypersons_table k ON k.clients_id = l.clients_id AND k.key_person = l.key_person
     {$where}
     ORDER BY {$orderBy} {$order}, l.id DESC
     LIMIT ? OFFSET ?",
    $listTypes,
    $listParams
);

jsonResponse([
    'status' => 'Success',
    'message' => 'Influence logs retrieved successfully.',
    'data' => [
        'items' => $rows,
        'pagination' => paginationMeta($page, $limit, $total),
        'summary' => [
            'total' => (int) ($summary['total'] ?? 0),
            'linked_clients' => (int) ($summary['linked_clients'] ?? 0),
            'key_people' => (int) ($summary['key_people'] ?? 0),
            'recent_logs' => (int) ($summary['recent_logs'] ?? 0),
        ],
        'sorting' => ['sort' => $sort, 'order' => strtolower($order)],
    ],
]);
