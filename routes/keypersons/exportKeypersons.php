<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/giftLists.php';
require_once __DIR__ . '/../../includes/permissions.php';

requireMethod('GET');
$authUser = authenticateUser();
$canViewGiftLists = userHasPermission($conn, $authUser, 'gift_lists.view');

$q = cleanString($_GET['search'] ?? ($_GET['q'] ?? ''));
$clientId = (int) ($_GET['client_id'] ?? 0);
$category = cleanString($_GET['category'] ?? '');
$status = cleanString($_GET['status'] ?? 'active');
$giftStatus = cleanString($_GET['gift_status'] ?? '');
$giftYear = validateGiftYear($_GET['gift_year'] ?? date('Y'));
$ownerPmsAdminId = (int) ($_GET['owner_pms_admin_id'] ?? 0);
$sort = cleanString($_GET['sort'] ?? 'key_person');
$order = strtolower(cleanString($_GET['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

if (!$canViewGiftLists && $giftStatus !== '' && $giftStatus !== 'all') {
    throw new RuntimeException('Gift-list filters are not available to this account.', 403);
}
if (!$canViewGiftLists && $sort === 'gift') {
    $sort = 'key_person';
}

$sortMap = [
    'key_person' => 'k.key_person',
    'client' => 'k.clients_name',
    'category' => 'k.clients_category',
    'gift' => "COALESCE(gli.gift_decision, 'pending')",
    'updated' => 'k.updated_at',
    'created_at' => 'k.created_at',
];
$orderBy = $sortMap[$sort] ?? $sortMap['key_person'];

$where = ' WHERE 1 = 1';
$types = '';
$params = [];
if ($status !== '' && $status !== 'all') {
    if (!in_array($status, ['active', 'inactive', 'deactivated'], true)) {
        throw new RuntimeException('Invalid status filter.', 422);
    }
    $where .= ' AND k.status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($clientId > 0) {
    $where .= ' AND k.clients_id = ?';
    $types .= 'i';
    $params[] = $clientId;
}
if ($q !== '') {
    $where .= ' AND (k.key_person LIKE ? OR k.key_persons_email LIKE ? OR k.key_persons_tel LIKE ? OR k.clients_name LIKE ? OR k.title LIKE ? OR k.key_persons_address LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ssssss';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($category !== '' && $category !== 'all') {
    $where .= ' AND k.clients_category = ?';
    $types .= 's';
    $params[] = $category;
}
if ($ownerPmsAdminId > 0 && isGlobalDataUser($authUser)) {
    $where .= ' AND k.owner_pms_admin_id = ?';
    $types .= 'i';
    $params[] = $ownerPmsAdminId;
}
[$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'k');
$where .= $scopeSql;
$types .= $scopeTypes;
$params = array_merge($params, $scopeParams);

$giftJoinSql = '';
$giftSelectSql = "NULL AS gift_decision, NULL AS gift_rate";
if ($canViewGiftLists) {
    $giftJoinSql = ' LEFT JOIN gift_lists gl ON gl.owner_pms_admin_id = k.owner_pms_admin_id AND gl.gift_year = ?
                     LEFT JOIN gift_list_items gli ON gli.gift_list_id = gl.id AND gli.keyperson_id = k.id';
    $giftSelectSql = "COALESCE(gli.gift_decision, 'pending') AS gift_decision, gli.gift_rate";
    $types = 'i' . $types;
    array_unshift($params, $giftYear);
    if ($giftStatus !== '' && $giftStatus !== 'all') {
        if (!in_array($giftStatus, ['Yes', 'No'], true)) {
            throw new RuntimeException('Invalid gift status filter.', 422);
        }
        $where .= $giftStatus === 'Yes'
            ? " AND gli.gift_decision = 'selected'"
            : " AND (gli.gift_decision IS NULL OR gli.gift_decision = 'not_selected')";
    }
}

$rows = dbFetchAll(
    $conn,
    "SELECT k.clients_name, k.clients_category, k.clients_hq_location, k.key_person, k.title,
            k.key_persons_tel, k.key_persons_email, k.key_persons_address,
            {$giftSelectSql},
            k.info, k.status, k.created_by, k.updated_by, k.created_at, k.updated_at,
            NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), '') AS owner_pms_admin_name
     FROM keypersons_table k
     {$giftJoinSql}
     LEFT JOIN users u ON u.id = k.owner_pms_admin_id
     {$where}
     ORDER BY {$orderBy} {$order}, k.id DESC",
    $types,
    $params
);

$filename = 'dynabase-keypersons-' . ($canViewGiftLists ? $giftYear : 'directory') . '-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');
$output = fopen('php://output', 'w');
fputcsv($output, [
    'Client', 'Category', 'HQ Location', 'Key Person', 'Designation', 'Telephone', 'Email',
    'Address', 'Gift Year', 'Gift Decision', 'Gift Rate', 'Info', 'Status', 'PMS Owner', 'Created By', 'Updated By',
    'Created At', 'Updated At'
]);
foreach ($rows as $row) {
    fputcsv($output, [
        $row['clients_name'] ?? '', $row['clients_category'] ?? '', $row['clients_hq_location'] ?? '',
        $row['key_person'] ?? '', $row['title'] ?? '', $row['key_persons_tel'] ?? '',
        $row['key_persons_email'] ?? '', $row['key_persons_address'] ?? '', $canViewGiftLists ? $giftYear : '',
        $canViewGiftLists ? ($row['gift_decision'] ?? 'pending') : '', $canViewGiftLists ? ($row['gift_rate'] ?? '') : '',
        $row['info'] ?? '', $row['status'] ?? '', $row['owner_pms_admin_name'] ?? 'Global', $row['created_by'] ?? '',
        $row['updated_by'] ?? '', $row['created_at'] ?? '', $row['updated_at'] ?? '',
    ]);
}
fclose($output);
exit;
