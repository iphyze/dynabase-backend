<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/giftLists.php';
require_once __DIR__ . '/../../includes/pagination.php';

requireMethod('GET');
$authUser = authenticateUser();
$q = cleanString($_GET['search'] ?? ($_GET['q'] ?? ''));
$giftYear = (int) ($_GET['gift_year'] ?? 0);
$ownerPmsAdminId = (int) ($_GET['owner_pms_admin_id'] ?? 0);
$clientId = (int) ($_GET['client_id'] ?? 0);
$giftRate = cleanString($_GET['gift_rate'] ?? '');
$sort = cleanString($_GET['sort'] ?? 'updated_at');
$order = strtolower(cleanString($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
[$page, $limit, $offset] = paginationParams();

if ($giftYear > 0) {
    validateGiftYear($giftYear);
}
if ($giftRate !== '' && !in_array($giftRate, DYNABASE_GIFT_RATES, true)) {
    throw new RuntimeException('Invalid gift-rate filter.', 422);
}

$where = ' WHERE EXISTS (SELECT 1 FROM gift_list_items has_items WHERE has_items.gift_list_id = gl.id)';
$types = '';
$params = [];

$fixedOwnerId = giftListFixedOwnerId($authUser);
if (!isGlobalDataUser($authUser)) {
    if ($fixedOwnerId === null) {
        $where .= ' AND 1 = 0';
    } else {
        $where .= ' AND gl.owner_pms_admin_id = ?';
        $types .= 'i';
        $params[] = $fixedOwnerId;
    }
} elseif ($ownerPmsAdminId > 0) {
    $where .= ' AND gl.owner_pms_admin_id = ?';
    $types .= 'i';
    $params[] = $ownerPmsAdminId;
}

$yearWhere = $where;
$yearTypes = $types;
$yearParams = $params;

if ($giftYear > 0) {
    $where .= ' AND gl.gift_year = ?';
    $types .= 'i';
    $params[] = $giftYear;
}
if ($clientId > 0) {
    $where .= ' AND EXISTS (SELECT 1 FROM gift_list_items client_filter WHERE client_filter.gift_list_id = gl.id AND client_filter.client_id = ?)';
    $types .= 'i';
    $params[] = $clientId;
}
if ($giftRate !== '') {
    $where .= ' AND EXISTS (SELECT 1 FROM gift_list_items rate_filter WHERE rate_filter.gift_list_id = gl.id AND rate_filter.gift_rate = ?)';
    $types .= 's';
    $params[] = $giftRate;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $where .= " AND (
        CONCAT_WS(' ', owner.first_name, owner.last_name) LIKE ?
        OR owner.email LIKE ?
        OR EXISTS (
            SELECT 1 FROM gift_list_items search_items
            WHERE search_items.gift_list_id = gl.id
              AND (search_items.keyperson_name_snapshot LIKE ? OR search_items.client_name_snapshot LIKE ?)
        )
    )";
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}

$sortMap = [
    'year' => 'gl.gift_year',
    'owner' => 'owner.first_name',
    'recipients' => 'recipient_count',
    'clients' => 'client_count',
    'updated_at' => 'gl.updated_at',
];
$orderBy = $sortMap[$sort] ?? $sortMap['updated_at'];

$baseFrom = ' FROM gift_lists gl INNER JOIN users owner ON owner.id = gl.owner_pms_admin_id';
$total = dbScalarInt($conn, "SELECT COUNT(*) AS total{$baseFrom}{$where}", $types, $params);

$rows = dbFetchAll(
    $conn,
    "SELECT gl.id, gl.gift_year, gl.owner_pms_admin_id, gl.created_by_id, gl.updated_by_id,
            gl.created_at, gl.updated_at,
            owner.first_name AS owner_first_name, owner.last_name AS owner_last_name,
            owner.email AS owner_email, owner.role AS owner_role, owner.is_pms_admin AS owner_is_pms_admin,
            COUNT(items.id) AS recipient_count,
            COUNT(DISTINCT items.client_id) AS client_count,
            GROUP_CONCAT(DISTINCT items.gift_rate ORDER BY FIELD(items.gift_rate, 'A+','A','B+','B','C+','C','D') SEPARATOR ',') AS rate_summary,
            MAX(items.updated_at) AS last_item_updated_at
     {$baseFrom}
     INNER JOIN gift_list_items items ON items.gift_list_id = gl.id
     {$where}
     GROUP BY gl.id
     ORDER BY {$orderBy} {$order}, gl.id DESC
     LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

$summary = dbFetchOne(
    $conn,
    "SELECT COUNT(DISTINCT gl.id) AS lists,
            COUNT(items.id) AS recipients,
            COUNT(DISTINCT CONCAT(gl.id, ':', items.client_id)) AS clients,
            COUNT(DISTINCT gl.owner_pms_admin_id) AS owners
     {$baseFrom}
     INNER JOIN gift_list_items items ON items.gift_list_id = gl.id
     {$where}",
    $types,
    $params
) ?? [];

$years = dbFetchAll(
    $conn,
    "SELECT DISTINCT gl.gift_year {$baseFrom}{$yearWhere} ORDER BY gl.gift_year DESC",
    $yearTypes,
    $yearParams
);

$items = array_map(static function (array $row): array {
    $ownerName = trim((string) $row['owner_first_name'] . ' ' . (string) $row['owner_last_name']);
    return [
        'id' => (int) $row['id'],
        'gift_year' => (int) $row['gift_year'],
        'owner_pms_admin_id' => (int) $row['owner_pms_admin_id'],
        'owner_name' => $ownerName !== '' ? $ownerName : (string) $row['owner_email'],
        'owner_email' => $row['owner_email'],
        'owner_role' => $row['owner_role'],
        'owner_is_pms_admin' => (int) $row['owner_is_pms_admin'] === 1,
        'recipient_count' => (int) $row['recipient_count'],
        'client_count' => (int) $row['client_count'],
        'rates' => $row['rate_summary'] !== null && $row['rate_summary'] !== '' ? explode(',', (string) $row['rate_summary']) : [],
        'created_by_id' => $row['created_by_id'] !== null ? (int) $row['created_by_id'] : null,
        'updated_by_id' => $row['updated_by_id'] !== null ? (int) $row['updated_by_id'] : null,
        'created_at' => $row['created_at'],
        'updated_at' => $row['last_item_updated_at'] ?? $row['updated_at'],
    ];
}, $rows);

jsonResponse([
    'status' => 'Success',
    'message' => 'Gift lists retrieved successfully.',
    'data' => [
        'items' => $items,
        'pagination' => paginationMeta($page, $limit, $total),
        'summary' => [
            'lists' => (int) ($summary['lists'] ?? 0),
            'recipients' => (int) ($summary['recipients'] ?? 0),
            'clients' => (int) ($summary['clients'] ?? 0),
            'owners' => (int) ($summary['owners'] ?? 0),
        ],
        'available_years' => array_map(static fn (array $row): int => (int) $row['gift_year'], $years),
        'sorting' => ['sort' => $sort, 'order' => strtolower($order)],
    ],
]);
