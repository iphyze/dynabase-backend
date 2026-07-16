<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/lookup.php';

requireMethod('GET');
authenticateUser();

$q = lookupSearchTerm();
$status = cleanString($_GET['status'] ?? '');
$country = cleanString($_GET['country'] ?? '');
$division = cleanString($_GET['division'] ?? '');
$limit = lookupLimit(20, 50);
$offset = lookupOffset();

$where = " WHERE COALESCE(record_status, 'active') = 'active'";
$types = '';
$params = [];

if ($q !== '') {
    $where .= ' AND (project_title LIKE ? OR tender_code LIKE ? OR project_client LIKE ? OR project_city LIKE ?)';
    $like = likeTerm($q);
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}

if ($status !== '') {
    $where .= ' AND project_status = ?';
    $types .= 's';
    $params[] = $status;
}

if ($country !== '') {
    $where .= ' AND project_country = ?';
    $types .= 's';
    $params[] = $country;
}

if ($division !== '') {
    $where .= ' AND division = ?';
    $types .= 's';
    $params[] = $division;
}

$total = dbScalarInt($conn, "SELECT COUNT(*) AS total FROM project_info_table{$where}", $types, $params);
$rows = dbFetchAll(
    $conn,
    "SELECT id, code, project_title, tender_code, project_client, project_country, project_city,
            division, project_status, progress
     FROM project_info_table{$where}
     ORDER BY created_at DESC, id DESC
     LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

$data = array_map(static function (array $row): array {
    $code = trim((string) ($row['tender_code'] ?? ''));
    $title = trim((string) ($row['project_title'] ?? 'Untitled Project'));
    $label = $code !== '' ? $code . ' — ' . $title : $title;

    return optionRow((int) $row['id'], $label, [
        'id' => (int) $row['id'],
        'code' => $row['code'],
        'project_title' => $row['project_title'],
        'tender_code' => $row['tender_code'],
        'project_client' => $row['project_client'],
        'project_country' => $row['project_country'],
        'project_city' => $row['project_city'],
        'division' => $row['division'],
        'project_status' => $row['project_status'],
        'progress' => $row['progress'],
    ]);
}, $rows);

lookupResponse('Project lookup retrieved successfully.', $data, $limit, $offset, $total);
