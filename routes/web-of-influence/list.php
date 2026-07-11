<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/webOfInfluence.php';
require_once __DIR__ . '/../../includes/pagination.php';

requireMethod('GET');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);
[$page, $limit, $offset] = paginationParams();

$q = cleanString($_GET['q'] ?? $_GET['search'] ?? '');
$clientId = (int) ($_GET['client_id'] ?? 0);
$projectId = (int) ($_GET['project_id'] ?? 0);
$keypersonId = (int) ($_GET['keyperson_id'] ?? 0);
$ownerPmsAdminRaw = cleanString($_GET['owner_pms_admin_id'] ?? '');
$ownerPmsAdminId = (int) $ownerPmsAdminRaw;
$authority = cleanString($_GET['authority'] ?? '');
$influenceLevel = cleanString($_GET['influence_level'] ?? '');
$recommendation = cleanString($_GET['company_recommendation'] ?? '');
$personalWin = cleanString($_GET['personal_win'] ?? '');
$sort = cleanString($_GET['sort'] ?? 'updated_at');
$order = strtolower(cleanString($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

$sortMap = [
    'stakeholder_name' => 'w.stakeholder_name',
    'client' => 'c.clients_name',
    'project' => 'p.project_title',
    'authority' => 'w.authority',
    'influence_level' => "FIELD(w.influence_level, 'Low', 'Med Low', 'Med', 'Med High', 'High')",
    'company_recommendation' => 'w.company_recommendation',
    'personal_win' => "FIELD(w.personal_win, 'Low', 'Med Low', 'Med', 'Med High', 'High')",
    'created_at' => 'w.created_at',
    'updated_at' => 'w.updated_at',
];
$orderBy = $sortMap[$sort] ?? $sortMap['updated_at'];
if (!isset($sortMap[$sort])) {
    $sort = 'updated_at';
}

$where = " WHERE w.record_status = 'active'";
$types = '';
$params = [];

if ($clientId > 0) {
    $where .= ' AND w.client_id = ?';
    $types .= 'i';
    $params[] = $clientId;
}
if ($projectId > 0) {
    $where .= ' AND w.project_id = ?';
    $types .= 'i';
    $params[] = $projectId;
}
if ($keypersonId > 0) {
    $where .= ' AND w.keyperson_id = ?';
    $types .= 'i';
    $params[] = $keypersonId;
}
if ($authority !== '') {
    $where .= ' AND w.authority = ?';
    $types .= 's';
    $params[] = normalizeWoiChoice($authority, 'authority', WOI_AUTHORITIES);
}
if ($influenceLevel !== '') {
    $where .= ' AND w.influence_level = ?';
    $types .= 's';
    $params[] = normalizeWoiChoice($influenceLevel, 'influence level', WOI_LEVELS);
}
if ($recommendation !== '') {
    $where .= ' AND w.company_recommendation = ?';
    $types .= 's';
    $params[] = normalizeWoiChoice($recommendation, 'company recommendation', WOI_RECOMMENDATIONS);
}
if ($personalWin !== '') {
    $where .= ' AND w.personal_win = ?';
    $types .= 's';
    $params[] = normalizeWoiChoice($personalWin, 'personal win', WOI_LEVELS);
}
if ($ownerPmsAdminRaw === 'global') {
    $where .= ' AND w.owner_pms_admin_id IS NULL';
} elseif ($ownerPmsAdminId > 0) {
    $where .= ' AND w.owner_pms_admin_id = ?';
    $types .= 'i';
    $params[] = $ownerPmsAdminId;
}
if ($q !== '') {
    $where .= ' AND (w.stakeholder_name LIKE ? OR w.stakeholder_role LIKE ? OR w.email LIKE ? OR w.phone LIKE ?
                   OR w.project_director LIKE ? OR w.country_manager LIKE ? OR w.dlp_period LIKE ? OR w.notes LIKE ?
                   OR c.clients_name LIKE ? OR c.clients_category LIKE ? OR p.project_title LIKE ? OR p.tender_code LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ssssssssssss';
    for ($index = 0; $index < 12; $index++) {
        $params[] = $like;
    }
}

[$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'w');
$where .= $scopeSql;
$types .= $scopeTypes;
$params = array_merge($params, $scopeParams);

$baseFrom = " FROM web_of_influence_table w
              LEFT JOIN clients_table c ON c.id = w.client_id
              LEFT JOIN project_info_table p ON p.id = w.project_id";
$total = dbScalarInt($conn, "SELECT COUNT(*) AS total{$baseFrom}{$where}", $types, $params);
$summary = dbFetchOne(
    $conn,
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN w.influence_level IN ('Med High', 'High') THEN 1 ELSE 0 END) AS high_influence,
            SUM(CASE WHEN w.company_recommendation = 'Yes' THEN 1 ELSE 0 END) AS recommenders,
            COUNT(DISTINCT w.project_id) AS mapped_projects,
            COUNT(DISTINCT w.client_id) AS mapped_clients
     {$baseFrom}{$where}",
    $types,
    $params
) ?? [];

$rows = dbFetchAll(
    $conn,
    woiRecordSelectSql() . "{$where} ORDER BY {$orderBy} {$order}, w.id DESC LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

foreach ($rows as &$row) {
    $row['id'] = (int) $row['id'];
    $row['client_id'] = (int) $row['client_id'];
    $row['project_id'] = (int) $row['project_id'];
    $row['keyperson_id'] = $row['keyperson_id'] !== null ? (int) $row['keyperson_id'] : null;
    $row['owner_pms_admin_id'] = $row['owner_pms_admin_id'] !== null ? (int) $row['owner_pms_admin_id'] : null;
}
unset($row);

jsonResponse([
    'status' => 'Success',
    'message' => 'Web of Influence records retrieved successfully.',
    'data' => [
        'items' => $rows,
        'pagination' => paginationMeta($page, $limit, $total),
        'summary' => [
            'total' => (int) ($summary['total'] ?? 0),
            'high_influence' => (int) ($summary['high_influence'] ?? 0),
            'recommenders' => (int) ($summary['recommenders'] ?? 0),
            'mapped_projects' => (int) ($summary['mapped_projects'] ?? 0),
            'mapped_clients' => (int) ($summary['mapped_clients'] ?? 0),
        ],
        'sorting' => ['sort' => $sort, 'order' => strtolower($order)],
    ],
]);
