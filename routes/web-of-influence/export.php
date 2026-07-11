<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/webOfInfluence.php';

requireMethod('GET');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);

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

$where = " WHERE w.record_status = 'active'";
$types = '';
$params = [];

foreach ([['client_id', $clientId], ['project_id', $projectId], ['keyperson_id', $keypersonId]] as [$field, $value]) {
    if ($value > 0) {
        $where .= " AND w.{$field} = ?";
        $types .= 'i';
        $params[] = $value;
    }
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
                   OR w.notes LIKE ? OR c.clients_name LIKE ? OR p.project_title LIKE ? OR p.tender_code LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ssssssss';
    for ($index = 0; $index < 8; $index++) {
        $params[] = $like;
    }
}

[$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'w');
$where .= $scopeSql;
$types .= $scopeTypes;
$params = array_merge($params, $scopeParams);

$rows = dbFetchAll(
    $conn,
    woiRecordSelectSql() . "{$where} ORDER BY w.updated_at DESC, w.id DESC LIMIT 10000",
    $types,
    $params
);

$filename = 'dynabase-web-of-influence-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
$output = fopen('php://output', 'wb');
fputcsv($output, [
    'Stakeholder', 'Role', 'Client', 'Tender/Project', 'Tender Code', 'DLP Period',
    'Project Director', 'Country Manager', 'Phone', 'Email', 'Authority',
    'Influence Level', 'Company Recommendation', 'Personal Win', 'Notes',
    'PMS Owner', 'Created By', 'Created At', 'Updated By', 'Updated At'
]);

foreach ($rows as $row) {
    fputcsv($output, [
        $row['stakeholder_name'], $row['stakeholder_role'], $row['clients_name'],
        $row['project_title'], $row['tender_code'], $row['dlp_period'],
        $row['project_director'], $row['country_manager'], $row['phone'], $row['email'],
        $row['authority'], $row['influence_level'], $row['company_recommendation'],
        $row['personal_win'], $row['notes'], $row['owner_pms_admin_name'] ?: $row['owner_pms_admin_email'],
        $row['created_by_name'] ?: $row['created_by'], $row['created_at'],
        $row['updated_by_name'] ?: $row['updated_by'], $row['updated_at'],
    ]);
}

fclose($output);
exit;
