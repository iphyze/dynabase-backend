<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/prequalifications.php';
require_once __DIR__ . '/../../includes/pagination.php';

requireMethod('GET');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);
[$page, $limit, $offset] = paginationParams();

$q = cleanString($_GET['q'] ?? $_GET['search'] ?? '');
$clientId = (int) ($_GET['client_id'] ?? 0);
$ownerRaw = cleanString($_GET['owner_pms_admin_id'] ?? '');
$ownerId = (int) $ownerRaw;
$linkStatus = cleanString($_GET['link_status'] ?? '');
$representative = cleanString($_GET['representative'] ?? '');
$budgetStatus = cleanString($_GET['budget_status'] ?? '');
$sort = cleanString($_GET['sort'] ?? 'updated_at');
$order = strtolower(cleanString($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

$sortMap = [
    'clients_name' => 'p.clients_name',
    'key_person' => 'p.key_person',
    'prospective_project' => 'p.prospective_project',
    'budget' => 'p.budget',
    'created_at' => 'p.created_at',
    'updated_at' => 'p.updated_at',
];
$orderBy = $sortMap[$sort] ?? $sortMap['updated_at'];
if (!isset($sortMap[$sort])) {
    $sort = 'updated_at';
}

$where = " WHERE p.record_status = 'active'";
$types = '';
$params = [];

if ($clientId > 0) {
    $where .= ' AND p.clients_id = ?';
    $types .= 'i';
    $params[] = $clientId;
}
if ($ownerRaw === 'global') {
    $where .= ' AND p.owner_pms_admin_id IS NULL';
} elseif ($ownerId > 0) {
    $where .= ' AND p.owner_pms_admin_id = ?';
    $types .= 'i';
    $params[] = $ownerId;
}
if ($linkStatus === 'linked') {
    $where .= ' AND p.clients_id > 0';
} elseif ($linkStatus === 'unlinked') {
    $where .= ' AND p.clients_id = 0';
}
if ($representative === 'with') {
    $where .= " AND TRIM(p.key_person) <> ''";
} elseif ($representative === 'without') {
    $where .= " AND TRIM(p.key_person) = ''";
}
if ($budgetStatus === 'with') {
    $where .= " AND TRIM(p.budget) <> ''";
} elseif ($budgetStatus === 'without') {
    $where .= " AND TRIM(p.budget) = ''";
}
if ($q !== '') {
    $where .= ' AND (p.clients_name LIKE ? OR p.clients_email LIKE ? OR p.clients_phone LIKE ?
                   OR p.clients_website LIKE ? OR p.key_person LIKE ? OR p.key_persons_tel LIKE ?
                   OR p.title LIKE ? OR p.business_info LIKE ? OR p.prospective_project LIKE ?
                   OR p.budget LIKE ? OR p.services LIKE ? OR p.remarks LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ssssssssssss';
    for ($index = 0; $index < 12; $index++) {
        $params[] = $like;
    }
}

[$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'p');
$where .= $scopeSql;
$types .= $scopeTypes;
$params = array_merge($params, $scopeParams);

$baseFrom = ' FROM prequalification_table p';
$total = dbScalarInt($conn, "SELECT COUNT(*) AS total{$baseFrom}{$where}", $types, $params);
$summary = dbFetchOne(
    $conn,
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN p.clients_id > 0 THEN 1 ELSE 0 END) AS linked_clients,
            SUM(CASE WHEN TRIM(p.key_person) <> '' THEN 1 ELSE 0 END) AS with_representatives,
            SUM(CASE WHEN TRIM(p.budget) <> '' THEN 1 ELSE 0 END) AS with_budgets,
            COUNT(DISTINCT CASE WHEN TRIM(p.prospective_project) <> '' THEN p.prospective_project END) AS prospective_projects
     {$baseFrom}{$where}",
    $types,
    $params
) ?? [];

$rows = dbFetchAll(
    $conn,
    prequalificationSelectSql() . "{$where} ORDER BY {$orderBy} {$order}, p.id DESC LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);
$rows = array_map('castPrequalificationRecord', $rows);

jsonResponse([
    'status' => 'Success',
    'message' => 'Prequalification records retrieved successfully.',
    'data' => [
        'items' => $rows,
        'pagination' => paginationMeta($page, $limit, $total),
        'summary' => [
            'total' => (int) ($summary['total'] ?? 0),
            'linked_clients' => (int) ($summary['linked_clients'] ?? 0),
            'with_representatives' => (int) ($summary['with_representatives'] ?? 0),
            'with_budgets' => (int) ($summary['with_budgets'] ?? 0),
            'prospective_projects' => (int) ($summary['prospective_projects'] ?? 0),
        ],
        'sorting' => ['sort' => $sort, 'order' => strtolower($order)],
    ],
]);
