<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/prequalifications.php';

requireMethod('GET');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);

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

$where = " WHERE p.record_status = 'active'";
$types = '';
$params = [];
if ($clientId > 0) { $where .= ' AND p.clients_id = ?'; $types .= 'i'; $params[] = $clientId; }
if ($ownerRaw === 'global') { $where .= ' AND p.owner_pms_admin_id IS NULL'; }
elseif ($ownerId > 0) { $where .= ' AND p.owner_pms_admin_id = ?'; $types .= 'i'; $params[] = $ownerId; }
if ($linkStatus === 'linked') $where .= ' AND p.clients_id > 0';
elseif ($linkStatus === 'unlinked') $where .= ' AND p.clients_id = 0';
if ($representative === 'with') $where .= " AND TRIM(p.key_person) <> ''";
elseif ($representative === 'without') $where .= " AND TRIM(p.key_person) = ''";
if ($budgetStatus === 'with') $where .= " AND TRIM(p.budget) <> ''";
elseif ($budgetStatus === 'without') $where .= " AND TRIM(p.budget) = ''";
if ($q !== '') {
    $where .= ' AND (p.clients_name LIKE ? OR p.clients_email LIKE ? OR p.clients_phone LIKE ?
                   OR p.clients_website LIKE ? OR p.key_person LIKE ? OR p.key_persons_tel LIKE ?
                   OR p.title LIKE ? OR p.business_info LIKE ? OR p.prospective_project LIKE ?
                   OR p.budget LIKE ? OR p.services LIKE ? OR p.remarks LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ssssssssssss';
    for ($index = 0; $index < 12; $index++) $params[] = $like;
}
[$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'p');
$where .= $scopeSql;
$types .= $scopeTypes;
$params = array_merge($params, $scopeParams);

$rows = dbFetchAll(
    $conn,
    prequalificationSelectSql() . "{$where} ORDER BY {$orderBy} {$order}, p.id DESC LIMIT 10000",
    $types,
    $params
);

$filename = 'dynabase-prequalifications-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
$output = fopen('php://output', 'wb');
fputcsv($output, [
    'Company', 'Linked Client', 'Company Address', 'Company Email', 'Company Phone', 'Company Website',
    'Representative', 'Representative Phone', 'Representative Designation', 'Business Information',
    'Prospective Project', 'Budget', 'Services Required', 'Remarks / Next Steps', 'PMS Owner',
    'Created By', 'Created At', 'Updated By', 'Updated At'
]);
foreach ($rows as $row) {
    fputcsv($output, [
        $row['clients_name'], $row['linked_client_name'] ?: 'Unlinked legacy/company record',
        $row['clients_address'], $row['clients_email'], $row['clients_phone'], $row['clients_website'],
        $row['key_person'], $row['key_persons_tel'], $row['title'], $row['business_info'],
        $row['prospective_project'], $row['budget'], $row['services'], $row['remarks'],
        $row['owner_pms_admin_name'] ?: ($row['owner_pms_admin_email'] ?: 'Global / unassigned'),
        $row['created_by_name'] ?: $row['created_by'], $row['created_at'],
        $row['updated_by_name'] ?: $row['updated_by'], $row['updated_at'],
    ]);
}
fclose($output);
exit;
