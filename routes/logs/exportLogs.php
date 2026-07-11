<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';

requireMethod('GET');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN, DYNABASE_ROLE_PMS_ADMIN], 'You are not authorised to export influence logs.');

$q = cleanString($_GET['search'] ?? ($_GET['q'] ?? ''));
$clientId = (int) ($_GET['client_id'] ?? 0);
$keypersonId = (int) ($_GET['keyperson_id'] ?? 0);
$keyPerson = cleanString($_GET['key_person'] ?? '');
$category = cleanString($_GET['category'] ?? '');
$ownerPmsAdminRaw = cleanString($_GET['owner_pms_admin_id'] ?? '');
$ownerPmsAdminId = (int) $ownerPmsAdminRaw;
$sort = cleanString($_GET['sort'] ?? 'created_at');
$order = strtolower(cleanString($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

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

$rows = dbFetchAll(
    $conn,
    "SELECT l.clients_name, l.clients_category, l.clients_hq_location, l.key_person, l.log,
            l.created_by, l.updated_by, l.created_at, l.updated_at,
            NULLIF(TRIM(CONCAT(COALESCE(owner.first_name, ''), ' ', COALESCE(owner.last_name, ''))), '') AS owner_pms_admin_name,
            NULLIF(TRIM(CONCAT(COALESCE(created_user.first_name, ''), ' ', COALESCE(created_user.last_name, ''))), '') AS created_by_name,
            NULLIF(TRIM(CONCAT(COALESCE(updated_user.first_name, ''), ' ', COALESCE(updated_user.last_name, ''))), '') AS updated_by_name
     FROM log_table l
     LEFT JOIN users owner ON owner.id = l.owner_pms_admin_id
     LEFT JOIN users created_user ON created_user.id = l.created_by_id
     LEFT JOIN users updated_user ON updated_user.id = l.updated_by_id
     {$where}
     ORDER BY {$orderBy} {$order}, l.id DESC",
    $types,
    $params
);

$filename = 'dynabase-influence-logs-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fputcsv($output, [
    'Client', 'Category', 'HQ Location', 'Key Person', 'Influence Log', 'PMS Owner',
    'Created By', 'Created Email', 'Updated By', 'Updated Email', 'Created At', 'Updated At'
]);

foreach ($rows as $row) {
    fputcsv($output, [
        $row['clients_name'] ?? '',
        $row['clients_category'] ?? '',
        $row['clients_hq_location'] ?? '',
        $row['key_person'] ?? '',
        $row['log'] ?? '',
        $row['owner_pms_admin_name'] ?? 'Global',
        $row['created_by_name'] ?? '',
        $row['created_by'] ?? '',
        $row['updated_by_name'] ?? '',
        $row['updated_by'] ?? '',
        $row['created_at'] ?? '',
        $row['updated_at'] ?? '',
    ]);
}

fclose($output);
exit;
