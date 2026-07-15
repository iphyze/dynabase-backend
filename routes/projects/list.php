<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/projects.php';

requireMethod('GET');
$authUser = authenticateUser();
[$page, $limit, $offset] = paginationParams();

$q = cleanString($_GET['q'] ?? $_GET['search'] ?? '');
$country = cleanString($_GET['country'] ?? '');
$city = cleanString($_GET['city'] ?? '');
$division = cleanString($_GET['division'] ?? '');
$status = cleanString($_GET['status'] ?? '');
$progress = cleanString($_GET['progress'] ?? '');
$dateFrom = cleanString($_GET['date_from'] ?? '');
$dateTo = cleanString($_GET['date_to'] ?? '');
$includeInactive = userHasRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN]) && cleanString($_GET['include_inactive'] ?? '') === '1';

$allowedSorts = [
    'project_title' => 'p.`project_title`',
    'country' => 'p.`project_country`',
    'city' => 'p.`project_city`',
    'division' => 'p.`division`',
    'status' => 'p.`project_status`',
    'progress' => 'p.`progress`',
    'due' => 'p.`tender_due`',
    'created_at' => 'p.`created_at`',
    'updated_at' => 'p.`updated_at`',
];
$sort = cleanString($_GET['sort'] ?? 'created_at');
$order = strtolower(cleanString($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$orderBy = $allowedSorts[$sort] ?? $allowedSorts['created_at'];

$where = $includeInactive ? 'WHERE 1 = 1' : "WHERE p.`record_status` = 'active'";
$types = '';
$params = [];

if ($q !== '') {
    $where .= ' AND (
        p.`project_title` LIKE ?
        OR p.`tender_code` LIKE ?
        OR p.`project_country` LIKE ?
        OR p.`project_city` LIKE ?
        OR p.`division` LIKE ?
        OR p.`project_status` LIKE ?
        OR p.`progress` LIKE ?
        OR pc.`project_code` LIKE ?
        OR p.`project_client` LIKE ?
        OR p.`keyperson` LIKE ?
        OR EXISTS (
            SELECT 1
            FROM `clients_keypersons_table` search_ck
            WHERE search_ck.`project_id` = p.`code`
              AND search_ck.`record_status` = \'active\'
              AND (search_ck.`clients_name` LIKE ? OR search_ck.`keyperson` LIKE ?)
        )
    )';
    $like = '%' . $q . '%';
    $types .= 'ssssssssssss';
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
}

if ($country !== '' && $country !== 'all') {
    $where .= ' AND p.`project_country` = ?';
    $types .= 's';
    $params[] = $country;
}

if ($city !== '' && $city !== 'all') {
    $where .= ' AND p.`project_city` = ?';
    $types .= 's';
    $params[] = $city;
}

if ($division !== '' && $division !== 'all') {
    $where .= ' AND p.`division` = ?';
    $types .= 's';
    $params[] = $division;
}

if ($dateFrom !== '') {
    $fromTs = strtotime($dateFrom);
    if ($fromTs === false) {
        throw new RuntimeException('date_from must be a valid date.', 422);
    }
    $where .= ' AND p.`created_at` >= ?';
    $types .= 's';
    $params[] = date('Y-m-d 00:00:00', $fromTs);
}

if ($dateTo !== '') {
    $toTs = strtotime($dateTo);
    if ($toTs === false) {
        throw new RuntimeException('date_to must be a valid date.', 422);
    }
    $where .= ' AND p.`created_at` <= ?';
    $types .= 's';
    $params[] = date('Y-m-d 23:59:59', $toTs);
}

[$scopeSql, $types, $params] = appendScopedWhere($authUser, 'p', $types, $params);
$where .= $scopeSql;

$statusCardWhere = $where;
$statusCardTypes = $types;
$statusCardParams = $params;

if ($status !== '' && $status !== 'all') {
    $status = normalizeProjectStatus($status);
    $where .= ' AND p.`project_status` = ?';
    $types .= 's';
    $params[] = $status;
}

if ($progress !== '' && $progress !== 'all') {
    $progress = normalizeProjectProgress($progress);
    $where .= ' AND p.`progress` = ?';
    $types .= 's';
    $params[] = $progress;
}

$total = dbScalarInt(
    $conn,
    "SELECT COUNT(*) AS total
     FROM `project_info_table` p
     LEFT JOIN `project_code_table` pc ON pc.`tender_code` = CAST(p.`code` AS CHAR)
     {$where}",
    $types,
    $params
);

$summary = dbFetchOne(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN p.`progress` = 'Awarded' OR p.`project_status` = 'Awarded' THEN 1 ELSE 0 END) AS awarded,
        SUM(CASE WHEN p.`progress` = 'Pending' OR p.`project_status` = 'Pending' THEN 1 ELSE 0 END) AS pending,
        COUNT(DISTINCT NULLIF(p.`project_country`, '')) AS countries
     FROM `project_info_table` p
     LEFT JOIN `project_code_table` pc ON pc.`tender_code` = CAST(p.`code` AS CHAR)
     {$where}",
    $types,
    $params
) ?: [];

$statusCards = dbFetchOne(
    $conn,
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN p.`progress` = 'Pending' THEN 1 ELSE 0 END) AS received,
        SUM(CASE WHEN p.`project_status` = 'On Hold' THEN 1 ELSE 0 END) AS on_hold,
        SUM(CASE WHEN p.`project_status` = 'Approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN p.`project_status` = 'Declined' THEN 1 ELSE 0 END) AS declined,
        SUM(CASE WHEN p.`progress` = 'In Progress' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN p.`progress` = 'Submitted' THEN 1 ELSE 0 END) AS submitted,
        SUM(CASE WHEN p.`progress` = 'Awaiting' THEN 1 ELSE 0 END) AS feedbacks,
        SUM(CASE WHEN p.`progress` = 'Awarded' THEN 1 ELSE 0 END) AS awarded,
        SUM(CASE WHEN p.`project_status` = 'Abortive' THEN 1 ELSE 0 END) AS abortive
     FROM `project_info_table` p
     LEFT JOIN `project_code_table` pc ON pc.`tender_code` = CAST(p.`code` AS CHAR)
     {$statusCardWhere}",
    $statusCardTypes,
    $statusCardParams
) ?: [];

$listTypes = $types . 'ii';
$listParams = array_merge($params, [$limit, $offset]);

$rows = dbFetchAll(
    $conn,
    "SELECT p.`id`, p.`project_title`, p.`division`, p.`project_country`, p.`project_city`, p.`code`, p.`tender_code`,
            p.`project_status`, p.`progress`, p.`tender_received_date`, p.`tender_due`, p.`tender_submission_date`,
            p.`tender_amount`, p.`currency`, p.`document_link`, p.`record_status`, p.`created_by`, p.`created_at`, p.`updated_by`, p.`updated_at`,
            p.`owner_pms_admin_id`, pc.`project_code` AS awarded_project_code,
            NULLIF(TRIM(CONCAT(COALESCE(owner.`first_name`, ''), ' ', COALESCE(owner.`last_name`, ''))), '') AS owner_pms_admin_name
     FROM `project_info_table` p
     LEFT JOIN `project_code_table` pc ON pc.`tender_code` = CAST(p.`code` AS CHAR)
     LEFT JOIN `users` owner ON owner.`id` = p.`owner_pms_admin_id`
     {$where}
     ORDER BY {$orderBy} {$order}, p.`id` DESC
     LIMIT ? OFFSET ?",
    $listTypes,
    $listParams
);

foreach ($rows as &$row) {
    $row['id'] = (int) $row['id'];
    $row['code'] = (int) $row['code'];
    $row['owner_pms_admin_id'] = $row['owner_pms_admin_id'] !== null ? (int) $row['owner_pms_admin_id'] : null;
    $row['awarded_project_code'] = $row['awarded_project_code'] ?: 'Unawarded';
}
unset($row);

jsonResponse([
    'status' => 'Success',
    'message' => 'Tenders retrieved successfully.',
    'data' => [
        'items' => $rows,
        'pagination' => paginationMeta($page, $limit, $total),
        'summary' => [
            'total' => (int) ($summary['total'] ?? 0),
            'awarded' => (int) ($summary['awarded'] ?? 0),
            'pending' => (int) ($summary['pending'] ?? 0),
            'countries' => (int) ($summary['countries'] ?? 0),
            'status_cards' => [
                'total' => (int) ($statusCards['total'] ?? 0),
                'received' => (int) ($statusCards['received'] ?? 0),
                'on_hold' => (int) ($statusCards['on_hold'] ?? 0),
                'approved' => (int) ($statusCards['approved'] ?? 0),
                'declined' => (int) ($statusCards['declined'] ?? 0),
                'in_progress' => (int) ($statusCards['in_progress'] ?? 0),
                'submitted' => (int) ($statusCards['submitted'] ?? 0),
                'feedbacks' => (int) ($statusCards['feedbacks'] ?? 0),
                'awarded' => (int) ($statusCards['awarded'] ?? 0),
                'abortive' => (int) ($statusCards['abortive'] ?? 0),
            ],
        ],
        'sorting' => [
            'sort' => $sort,
            'order' => strtolower($order),
        ],
    ],
]);
