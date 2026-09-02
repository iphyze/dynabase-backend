<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/pagination.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';
require_once __DIR__ . '/../../includes/permissions.php';

requireMethod('GET');
$authUser = authenticateUser();

$canViewKeypersons = userHasPermission($conn, $authUser, 'keypersons.view');
$canViewInfluenceLogs = userHasPermission($conn, $authUser, 'influence_logs.view');
$canViewTenders = userHasPermission($conn, $authUser, 'tenders.view');
$viewerPmsAdminId = resolveOwnerPmsAdminId($authUser);
$keypersonCountSql = '0';
if ($canViewKeypersons) {
    if ($viewerPmsAdminId !== null && $viewerPmsAdminId > 0) {
        $viewerPmsAdminIdSql = (int) $viewerPmsAdminId;
        $keypersonCountSql = "(SELECT COUNT(*) FROM keypersons_table kp
            WHERE kp.clients_id = c.id
              AND kp.status <> 'deactivated'
              AND EXISTS (
                  SELECT 1 FROM keyperson_pms_assignments kpa
                  WHERE kpa.keyperson_id = kp.id AND kpa.pms_admin_id = {$viewerPmsAdminIdSql}
              ))";
    } else {
        $keypersonCountSql = "(SELECT COUNT(*) FROM keypersons_table kp WHERE kp.clients_id = c.id AND kp.status <> 'deactivated')";
    }
}
$logCountSql = $canViewInfluenceLogs
    ? "(SELECT COUNT(*) FROM log_table lg WHERE lg.clients_id = c.id AND lg.owner_pms_admin_id <=> c.owner_pms_admin_id)"
    : '0';
$projectCountSql = $canViewTenders
    ? "(SELECT COUNT(*) FROM project_info_table pr WHERE pr.project_client = c.clients_name AND pr.record_status <> 'deleted')"
    : '0';

$q = cleanString($_GET['q'] ?? $_GET['search'] ?? '');
$category = cleanString($_GET['category'] ?? '');
$status = cleanString($_GET['status'] ?? 'active');
$owner = (int) ($_GET['owner_pms_admin_id'] ?? 0);
[$page, $limit, $offset] = paginationParams();

$allowedSorts = [
    'name' => 'c.clients_name',
    'email' => 'c.clients_email',
    'category' => 'c.clients_category',
    'hq_location' => 'c.clients_hq_location',
    'status' => 'c.status',
    'owner' => 'owner_pms_admin_name',
    'created_at' => 'c.created_at',
    'updated_at' => 'c.updated_at',
];
$sort = cleanString($_GET['sort'] ?? 'name');
$order = strtolower(cleanString($_GET['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$sortSql = $allowedSorts[$sort] ?? $allowedSorts['name'];

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
    $where .= ' AND (c.clients_name LIKE ? OR c.clients_email LIKE ? OR c.clients_website LIKE ? OR c.clients_address LIKE ? OR c.clients_hq_location LIKE ? OR c.clients_category LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ssssss';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

if ($category !== '' && $category !== 'all') {
    $where .= ' AND c.clients_category = ?';
    $types .= 's';
    $params[] = $category;
}

if (isGlobalDataUser($authUser) && $owner > 0) {
    $where .= ' AND c.owner_pms_admin_id = ?';
    $types .= 'i';
    $params[] = $owner;
}

[$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'c');
$where .= $scopeSql;
$types .= $scopeTypes;
$params = array_merge($params, $scopeParams);

$total = dbScalarInt($conn, "SELECT COUNT(*) AS total FROM clients_table c{$where}", $types, $params);

$listTypes = $types . 'ii';
$listParams = array_merge($params, [$limit, $offset]);
$rows = dbFetchAll(
    $conn,
    "SELECT c.id, c.clients_name, c.clients_email, c.clients_website, c.clients_address,
            c.clients_hq_location, c.clients_category, c.created_by, c.created_by_id,
            c.updated_by, c.updated_by_id, c.owner_pms_admin_id, c.status,
            c.created_at, c.updated_at,
            TRIM(CONCAT(COALESCE(owner.first_name, ''), ' ', COALESCE(owner.last_name, ''))) AS owner_pms_admin_name,
            owner.email AS owner_pms_admin_email,
            TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))) AS created_by_name,
            creator.email AS created_by_email,
            {$keypersonCountSql} AS keyperson_count,
            {$logCountSql} AS log_count,
            {$projectCountSql} AS project_count
     FROM clients_table c
     LEFT JOIN users owner ON owner.id = c.owner_pms_admin_id
     LEFT JOIN users creator ON creator.id = c.created_by_id
     {$where}
     ORDER BY {$sortSql} {$order}, c.id DESC
     LIMIT ? OFFSET ?",
    $listTypes,
    $listParams
);

$summaryWhere = ' WHERE 1 = 1';
$summaryTypes = '';
$summaryParams = [];
[$summaryScopeSql, $summaryScopeTypes, $summaryScopeParams] = appendScopedWhere($authUser, 'c');
$summaryWhere .= $summaryScopeSql;
$summaryTypes .= $summaryScopeTypes;
$summaryParams = array_merge($summaryParams, $summaryScopeParams);

$summary = dbFetchOne(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN c.status = 'inactive' THEN 1 ELSE 0 END) AS inactive,
        SUM(CASE WHEN c.status = 'deactivated' THEN 1 ELSE 0 END) AS deactivated,
        COUNT(DISTINCT c.clients_category) AS categories
     FROM clients_table c{$summaryWhere}",
    $summaryTypes,
    $summaryParams
) ?: [];

$categoryBreakdown = dbFetchAll(
    $conn,
    "SELECT c.clients_category AS category, COUNT(*) AS total
     FROM clients_table c{$summaryWhere}
     GROUP BY c.clients_category
     ORDER BY total DESC, c.clients_category ASC
     LIMIT 8",
    $summaryTypes,
    $summaryParams
);

jsonResponse([
    'status' => 'Success',
    'message' => 'Clients retrieved successfully.',
    'data' => [
        'items' => $rows,
        'pagination' => paginationMeta($page, $limit, $total),
        'sorting' => [
            'sort' => $sort,
            'order' => strtolower($order),
        ],
        'summary' => [
            'total' => (int) ($summary['total'] ?? 0),
            'active' => (int) ($summary['active'] ?? 0),
            'inactive' => (int) ($summary['inactive'] ?? 0),
            'deactivated' => (int) ($summary['deactivated'] ?? 0),
            'categories' => (int) ($summary['categories'] ?? 0),
            'category_breakdown' => array_map(static fn (array $row): array => [
                'category' => $row['category'] ?: 'Uncategorised',
                'total' => (int) ($row['total'] ?? 0),
            ], $categoryBreakdown),
        ],
    ],
]);
