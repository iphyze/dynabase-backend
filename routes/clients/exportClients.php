<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';

requireMethod('GET');
$authUser = authenticateUser();

$q = cleanString($_GET['q'] ?? $_GET['search'] ?? '');
$category = cleanString($_GET['category'] ?? '');
$status = cleanString($_GET['status'] ?? 'active');
$owner = (int) ($_GET['owner_pms_admin_id'] ?? 0);

$allowedSorts = [
    'name' => 'c.clients_name',
    'email' => 'c.clients_email',
    'category' => 'c.clients_category',
    'hq_location' => 'c.clients_hq_location',
    'status' => 'c.status',
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

$sql = "SELECT c.id, c.clients_name, c.clients_email, c.clients_website, c.clients_address,
               c.clients_hq_location, c.clients_category, c.status, c.created_by, c.created_at,
               c.updated_by, c.updated_at,
               TRIM(CONCAT(COALESCE(owner.first_name, ''), ' ', COALESCE(owner.last_name, ''))) AS owner_pms_admin_name,
               owner.email AS owner_pms_admin_email,
               TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))) AS created_by_name,
               creator.email AS created_by_email
        FROM clients_table c
        LEFT JOIN users owner ON owner.id = c.owner_pms_admin_id
        LEFT JOIN users creator ON creator.id = c.created_by_id
        {$where}
        ORDER BY {$sortSql} {$order}, c.id DESC
        LIMIT 10000";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$filename = 'dynabase-clients-' . date('Y-m-d-His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');

fputcsv($out, [
    'ID',
    'Client Name',
    'Email',
    'Website',
    'Address',
    'HQ Location',
    'Category',
    'PMS Owner',
    'PMS Owner Email',
    'Created By',
    'Created By Email',
    'Status',
    'Created At',
    'Updated By',
    'Updated At',
]);

while ($row = $result->fetch_assoc()) {
    fputcsv($out, [
        $row['id'] ?? '',
        $row['clients_name'] ?? '',
        $row['clients_email'] ?? '',
        $row['clients_website'] ?? '',
        $row['clients_address'] ?? '',
        $row['clients_hq_location'] ?? '',
        $row['clients_category'] ?? '',
        $row['owner_pms_admin_name'] ?: ($row['owner_pms_admin_email'] ?? ''),
        $row['owner_pms_admin_email'] ?? '',
        $row['created_by_name'] ?: ($row['created_by'] ?? ''),
        $row['created_by_email'] ?? '',
        ucwords((string) ($row['status'] ?? '')),
        $row['created_at'] ?? '',
        $row['updated_by'] ?? '',
        $row['updated_at'] ?? '',
    ]);
}

$stmt->close();
fclose($out);
exit;
