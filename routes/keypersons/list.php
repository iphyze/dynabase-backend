<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/giftLists.php';
require_once __DIR__ . '/../../includes/pagination.php';
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
$viewerOwnerPmsAdminId = resolveOwnerPmsAdminId($authUser);
$sort = cleanString($_GET['sort'] ?? 'key_person');
$order = strtolower(cleanString($_GET['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
[$page, $limit, $offset] = paginationParams();

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

$giftJoinSql = '';
$giftJoinTypes = '';
$giftJoinParams = [];
$giftContextOwnerId = $viewerOwnerPmsAdminId;
if (($giftContextOwnerId === null || $giftContextOwnerId <= 0) && $ownerPmsAdminId > 0 && userHasRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN])) {
    $giftContextOwnerId = $ownerPmsAdminId;
}
if ($canViewGiftLists) {
    if ($giftContextOwnerId !== null && $giftContextOwnerId > 0) {
        $giftJoinSql = ' LEFT JOIN gift_lists gl ON gl.owner_pms_admin_id = ? AND gl.gift_year = ?
                         LEFT JOIN gift_list_items gli ON gli.gift_list_id = gl.id AND gli.keyperson_id = k.id';
        $giftJoinTypes = 'ii';
        $giftJoinParams = [$giftContextOwnerId, $giftYear];
    } else {
        $giftJoinSql = " LEFT JOIN (
                            SELECT any_items.keyperson_id,
                                   'selected' AS gift_decision,
                                   CASE WHEN COUNT(DISTINCT any_items.gift_rate) = 1 THEN MAX(any_items.gift_rate) ELSE NULL END AS gift_rate,
                                   NULL AS source
                            FROM gift_list_items any_items
                            INNER JOIN gift_lists any_lists ON any_lists.id = any_items.gift_list_id
                            WHERE any_lists.gift_year = ? AND any_items.gift_decision = 'selected'
                            GROUP BY any_items.keyperson_id
                         ) gli ON gli.keyperson_id = k.id";
        $giftJoinTypes = 'i';
        $giftJoinParams = [$giftYear];
    }
}

$baseWhere = ' WHERE 1 = 1';
$baseWhereTypes = '';
$baseWhereParams = [];

if ($clientId > 0) {
    $baseWhere .= ' AND k.clients_id = ?';
    $baseWhereTypes .= 'i';
    $baseWhereParams[] = $clientId;
}

if ($q !== '') {
    $baseWhere .= ' AND (k.key_person LIKE ? OR k.key_persons_email LIKE ? OR k.key_persons_tel LIKE ? OR k.clients_name LIKE ? OR k.title LIKE ? OR k.key_persons_address LIKE ?)';
    $like = '%' . $q . '%';
    $baseWhereTypes .= 'ssssss';
    array_push($baseWhereParams, $like, $like, $like, $like, $like, $like);
}

if ($category !== '' && $category !== 'all') {
    $baseWhere .= ' AND k.clients_category = ?';
    $baseWhereTypes .= 's';
    $baseWhereParams[] = $category;
}

if ($canViewGiftLists && $giftStatus !== '' && $giftStatus !== 'all') {
    if (!in_array($giftStatus, ['Yes', 'No'], true)) {
        throw new RuntimeException('Invalid gift status filter.', 422);
    }
    $baseWhere .= $giftStatus === 'Yes'
        ? " AND gli.gift_decision = 'selected'"
        : " AND (gli.gift_decision IS NULL OR gli.gift_decision = 'not_selected')";
}

if ($ownerPmsAdminId > 0 && userHasRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN])) {
    $baseWhere .= ' AND EXISTS (SELECT 1 FROM keyperson_pms_assignments kpa_filter WHERE kpa_filter.keyperson_id = k.id AND kpa_filter.pms_admin_id = ?)';
    $baseWhereTypes .= 'i';
    $baseWhereParams[] = $ownerPmsAdminId;
}

[$scopeSql, $scopeTypes, $scopeParams] = appendKeypersonScopedWhere($authUser, 'k');
$baseWhere .= $scopeSql;
$baseWhereTypes .= $scopeTypes;
$baseWhereParams = array_merge($baseWhereParams, $scopeParams);

$where = $baseWhere;
$whereTypes = $baseWhereTypes;
$whereParams = $baseWhereParams;
if ($status !== '' && $status !== 'all') {
    if (!in_array($status, ['active', 'inactive', 'deactivated'], true)) {
        throw new RuntimeException('Invalid status filter.', 422);
    }
    $where .= ' AND k.status = ?';
    $whereTypes .= 's';
    $whereParams[] = $status;
}

$total = dbScalarInt(
    $conn,
    "SELECT COUNT(*) AS total FROM keypersons_table k{$giftJoinSql}{$where}",
    $giftJoinTypes . $whereTypes,
    array_merge($giftJoinParams, $whereParams)
);

$giftedSql = $canViewGiftLists
    ? "SUM(CASE WHEN gli.gift_decision = 'selected' THEN 1 ELSE 0 END)"
    : '0';
$summary = dbFetchOne(
    $conn,
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN k.status = 'active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN k.status <> 'active' THEN 1 ELSE 0 END) AS inactive,
            {$giftedSql} AS gifted,
            COUNT(DISTINCT k.clients_id) AS linked_clients
     FROM keypersons_table k{$giftJoinSql}{$baseWhere}",
    $giftJoinTypes . $baseWhereTypes,
    array_merge($giftJoinParams, $baseWhereParams)
) ?? [];

$giftSelectSql = $canViewGiftLists
    ? "CASE WHEN gli.gift_decision = 'selected' THEN 'Yes' ELSE 'No' END AS gift_status,
       COALESCE(gli.gift_rate, 'N/A') AS gift_type,
       COALESCE(gli.gift_decision, 'pending') AS annual_gift_decision,
       gli.gift_rate AS annual_gift_rate,
       gli.source AS annual_gift_source,
       ? AS gift_year,"
    : "NULL AS gift_status,
       NULL AS gift_type,
       NULL AS annual_gift_decision,
       NULL AS annual_gift_rate,
       NULL AS annual_gift_source,
       NULL AS gift_year,";

$listPrefixTypes = $canViewGiftLists ? ('i' . $giftJoinTypes) : '';
$listPrefixParams = $canViewGiftLists ? array_merge([$giftYear], $giftJoinParams) : [];
$listTypes = $listPrefixTypes . $whereTypes . 'ii';
$listParams = array_merge($listPrefixParams, $whereParams, [$limit, $offset]);

$rows = dbFetchAll(
    $conn,
    "SELECT k.id, k.clients_id, k.clients_name, k.clients_email, k.clients_address, k.clients_hq_location,
            k.clients_category, k.key_person, k.key_persons_tel, k.key_persons_email, k.key_persons_address,
            {$giftSelectSql}
            k.title, k.info, k.created_by, k.created_by_id, k.updated_by,
            k.updated_by_id, k.status, k.created_at, k.updated_at,
            (SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(CONCAT(COALESCE(au.first_name, ''), ' ', COALESCE(au.last_name, ''))), '') ORDER BY au.first_name, au.last_name SEPARATOR ', ')
             FROM keyperson_pms_assignments kpa_names INNER JOIN users au ON au.id = kpa_names.pms_admin_id
             WHERE kpa_names.keyperson_id = k.id) AS pms_assignment_names,
            (SELECT COUNT(*) FROM keyperson_pms_assignments kpa_count WHERE kpa_count.keyperson_id = k.id) AS pms_assignment_count
     FROM keypersons_table k
     {$giftJoinSql}
     {$where}
     ORDER BY {$orderBy} {$order}, k.id DESC
     LIMIT ? OFFSET ?",
    $listTypes,
    $listParams
);

if ($viewerOwnerPmsAdminId !== null && $viewerOwnerPmsAdminId > 0) {
    $visibleOwner = dbFetchOne(
        $conn,
        "SELECT id, email, NULLIF(TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))), '') AS name FROM users WHERE id = ? LIMIT 1",
        'i',
        [$viewerOwnerPmsAdminId]
    );
    foreach ($rows as &$row) {
        $row['owner_pms_admin_id'] = $viewerOwnerPmsAdminId;
        $row['owner_pms_admin_name'] = $visibleOwner['name'] ?? null;
        $row['owner_pms_admin_email'] = $visibleOwner['email'] ?? null;
        unset($row['pms_assignment_names'], $row['pms_assignment_count']);
    }
    unset($row);
} elseif (userHasRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN])) {
    foreach ($rows as &$row) {
        $row['owner_pms_admin_id'] = null;
        $row['owner_pms_admin_name'] = $row['pms_assignment_names'] ?? null;
        $row['owner_pms_admin_email'] = null;
    }
    unset($row);
} else {
    foreach ($rows as &$row) {
        $row['owner_pms_admin_id'] = null;
        $row['owner_pms_admin_name'] = null;
        $row['owner_pms_admin_email'] = null;
        unset($row['pms_assignment_names'], $row['pms_assignment_count']);
    }
    unset($row);
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Key persons retrieved successfully.',
    'data' => [
        'items' => $rows,
        'pagination' => paginationMeta($page, $limit, $total),
        'summary' => [
            'total' => (int) ($summary['total'] ?? 0),
            'active' => (int) ($summary['active'] ?? 0),
            'inactive' => (int) ($summary['inactive'] ?? 0),
            'gifted' => (int) ($summary['gifted'] ?? 0),
            'linked_clients' => (int) ($summary['linked_clients'] ?? 0),
            'gift_year' => $canViewGiftLists ? $giftYear : null,
        ],
        'access' => [
            'gift_lists' => $canViewGiftLists,
        ],
        'sorting' => ['sort' => $sort, 'order' => strtolower($order)],
    ],
]);
