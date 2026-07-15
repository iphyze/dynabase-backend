<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/projects.php';

requireMethod('GET');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN, DYNABASE_ROLE_PMS_ADMIN], 'You are not authorised to export tenders.');

$q = cleanString($_GET['q'] ?? $_GET['search'] ?? '');
$country = cleanString($_GET['country'] ?? '');
$city = cleanString($_GET['city'] ?? '');
$division = cleanString($_GET['division'] ?? '');
$status = cleanString($_GET['status'] ?? '');
$progress = cleanString($_GET['progress'] ?? '');
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

[$scopeSql, $types, $params] = appendScopedWhere($authUser, 'p', $types, $params);
$where .= $scopeSql;

$rows = dbFetchAll(
    $conn,
    "SELECT p.`project_title`, p.`division`, p.`project_country`, p.`project_city`, p.`city_code`, p.`code`, p.`tender_code`,
            p.`project_status`, p.`progress`, p.`tender_received_date`, p.`tender_due`, p.`tender_submission_date`,
            p.`tender_amount`, p.`currency`, p.`project_manager`, p.`qs_manager`, p.`mep_consultants`, p.`architect`,
            p.`project_duration`, p.`end_user`, p.`project_importance`, p.`contract_type`, p.`prelim_pricing`,
            p.`pricing_strategy`, p.`date_extension`, p.`rate_used`, p.`procurement_type`, p.`tender_awarded_date`,
            p.`vendor_information`, p.`document_link`, p.`additional_information`, p.`record_status`, p.`created_by`, p.`created_at`,
            p.`updated_by`, p.`updated_at`, pc.`project_code` AS awarded_project_code,
            NULLIF(TRIM(CONCAT(COALESCE(owner.`first_name`, ''), ' ', COALESCE(owner.`last_name`, ''))), '') AS owner_pms_admin_name
     FROM `project_info_table` p
     LEFT JOIN `project_code_table` pc ON pc.`tender_code` = CAST(p.`code` AS CHAR)
     LEFT JOIN `users` owner ON owner.`id` = p.`owner_pms_admin_id`
     {$where}
     ORDER BY {$orderBy} {$order}, p.`id` DESC
     LIMIT 10000",
    $types,
    $params
);

$filename = 'dynabase-tenders-' . date('Y-m-d-His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";
$output = fopen('php://output', 'w');

fputcsv($output, [
    'Tender Code', 'Awarded Project Code', 'Project Title', 'Division', 'Country', 'City', 'City Code',
    'Status', 'Progress', 'Tender Received', 'Tender Due', 'Submission Date', 'Tender Amount', 'Currency',
    'Project Manager', 'QS Manager', 'MEP Consultants', 'Architect', 'Project Duration', 'End User',
    'Importance', 'Contract Type', 'Prelim Pricing', 'Pricing Strategy', 'Date Extension', 'Rate Used',
    'Procurement Type', 'Awarded Date', 'Vendor Information', 'Document Link', 'Additional Information',
    'PMS Owner', 'Record Status', 'Created By', 'Created At', 'Updated By', 'Updated At'
]);

foreach ($rows as $row) {
    fputcsv($output, [
        $row['tender_code'] ?? '',
        $row['awarded_project_code'] ?: 'Unawarded',
        $row['project_title'] ?? '',
        $row['division'] ?? '',
        $row['project_country'] ?? '',
        $row['project_city'] ?? '',
        $row['city_code'] ?? '',
        $row['project_status'] ?? '',
        $row['progress'] ?? '',
        $row['tender_received_date'] ?? '',
        $row['tender_due'] ?? '',
        $row['tender_submission_date'] ?? '',
        $row['tender_amount'] ?? '',
        $row['currency'] ?? '',
        $row['project_manager'] ?? '',
        $row['qs_manager'] ?? '',
        $row['mep_consultants'] ?? '',
        $row['architect'] ?? '',
        $row['project_duration'] ?? '',
        $row['end_user'] ?? '',
        $row['project_importance'] ?? '',
        $row['contract_type'] ?? '',
        $row['prelim_pricing'] ?? '',
        $row['pricing_strategy'] ?? '',
        $row['date_extension'] ?? '',
        $row['rate_used'] ?? '',
        $row['procurement_type'] ?? '',
        $row['tender_awarded_date'] ?? '',
        $row['vendor_information'] ?? '',
        $row['document_link'] ?? '',
        $row['additional_information'] ?? '',
        $row['owner_pms_admin_name'] ?: 'Global',
        $row['record_status'] ?? '',
        $row['created_by'] ?? '',
        $row['created_at'] ?? '',
        $row['updated_by'] ?? '',
        $row['updated_at'] ?? '',
    ]);
}

fclose($output);
exit;
