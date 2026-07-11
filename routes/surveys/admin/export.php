<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/request.php';
require_once __DIR__ . '/../../../includes/surveys.php';

requireMethod('GET');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);

$q = cleanString($_GET['q'] ?? $_GET['search'] ?? '');
$rating = cleanString($_GET['rating'] ?? '');
$status = cleanString($_GET['status'] ?? '');
$clientId = (int) ($_GET['client_id'] ?? 0);
$dateFrom = cleanString($_GET['date_from'] ?? '');
$dateTo = cleanString($_GET['date_to'] ?? '');
$where = ' WHERE s.deleted_at IS NULL';
$types = '';
$params = [];
if ($q !== '') {
    $where .= ' AND (s.company LIKE ? OR s.project_title LIKE ? OR s.filled_by LIKE ? OR s.email LIKE ? OR s.submission_reference LIKE ? OR s.location LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ssssss';
    $params = array_merge($params, array_fill(0, 6, $like));
}
if ($rating !== '') {
    $ranges = ['Excellent' => [87.5,100], 'Good' => [62.5,87.49], 'Average' => [37.5,62.49], 'Poor' => [0,37.49]];
    if (isset($ranges[$rating])) { $where .= ' AND s.overall_score BETWEEN ? AND ?'; $types .= 'dd'; $params[] = $ranges[$rating][0]; $params[] = $ranges[$rating][1]; }
}
if ($status !== '') { $where .= ' AND s.response_status = ?'; $types .= 's'; $params[] = $status; }
if ($clientId > 0) { $where .= ' AND s.client_id = ?'; $types .= 'i'; $params[] = $clientId; }
if ($dateFrom !== '') { $where .= ' AND DATE(s.createdAt) >= ?'; $types .= 's'; $params[] = $dateFrom; }
if ($dateTo !== '') { $where .= ' AND DATE(s.createdAt) <= ?'; $types .= 's'; $params[] = $dateTo; }

$rows = dbFetchAll($conn, surveySelectSql() . $where . ' ORDER BY s.createdAt DESC LIMIT 10000', $types, $params);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="dynabase-client-surveys-' . date('Y-m-d') . '.csv"');
header('X-Content-Type-Options: nosniff');
$output = fopen('php://output', 'wb');
fputcsv($output, ['Reference','Company','Project','Respondent','Position','Email','Phone','Location','Overall Score','Quality','Timeline','Expertise','Communication','Resolution','Cleanliness','Safety','Complaint Response','Electrical Services','Mechanical Services','Status','Submitted At']);
foreach ($rows as $row) {
    fputcsv($output, [$row['submission_reference'],$row['company'],$row['project_title'],$row['filled_by'],$row['position'],$row['email'],$row['phone_number'],$row['location'],$row['overall_score'],$row['quality'],$row['timeline'],$row['expertise'],$row['communication'],$row['resolution'],$row['cleaniness'],$row['safety'],$row['response'],$row['electrical_services'],$row['mechanical_services'],$row['response_status'],$row['createdAt']]);
}
fclose($output);
exit;
