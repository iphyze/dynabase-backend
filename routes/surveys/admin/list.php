<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/request.php';
require_once __DIR__ . '/../../../includes/surveys.php';
require_once __DIR__ . '/../../../includes/pagination.php';

requireMethod('GET');
$authUser = authenticateUser();
[$page, $limit, $offset] = paginationParams();

$q = cleanString($_GET['q'] ?? $_GET['search'] ?? '');
$rating = cleanString($_GET['rating'] ?? '');
$status = cleanString($_GET['status'] ?? '');
$clientId = (int) ($_GET['client_id'] ?? 0);
$dateFrom = cleanString($_GET['date_from'] ?? '');
$dateTo = cleanString($_GET['date_to'] ?? '');
$sort = cleanString($_GET['sort'] ?? 'createdAt');
$order = strtolower(cleanString($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

$sortMap = [
    'company' => 's.company',
    'project_title' => 's.project_title',
    'filled_by' => 's.filled_by',
    'overall_score' => 's.overall_score',
    'createdAt' => 's.createdAt',
    'updatedAt' => 's.updatedAt',
];
$orderBy = $sortMap[$sort] ?? $sortMap['createdAt'];
if (!isset($sortMap[$sort])) $sort = 'createdAt';

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
    $ranges = [
        'Excellent' => [87.5, 100.0],
        'Good' => [62.5, 87.49],
        'Average' => [37.5, 62.49],
        'Poor' => [0.0, 37.49],
    ];
    if (isset($ranges[$rating])) {
        $where .= ' AND s.overall_score BETWEEN ? AND ?';
        $types .= 'dd';
        $params[] = $ranges[$rating][0];
        $params[] = $ranges[$rating][1];
    }
}
if ($status !== '') {
    $where .= ' AND s.response_status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($clientId > 0) {
    $where .= ' AND s.client_id = ?';
    $types .= 'i';
    $params[] = $clientId;
}
if ($dateFrom !== '') {
    $where .= ' AND DATE(s.createdAt) >= ?';
    $types .= 's';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where .= ' AND DATE(s.createdAt) <= ?';
    $types .= 's';
    $params[] = $dateTo;
}

$total = dbScalarInt($conn, 'SELECT COUNT(*) AS total FROM clients_survey_form s' . $where, $types, $params);
$summary = dbFetchOne(
    $conn,
    "SELECT COUNT(*) AS total,
            ROUND(AVG(s.overall_score), 1) AS average_score,
            SUM(CASE WHEN s.overall_score >= 87.5 THEN 1 ELSE 0 END) AS excellent_count,
            COUNT(DISTINCT NULLIF(TRIM(s.project_title), '')) AS project_count
     FROM clients_survey_form s{$where}",
    $types,
    $params
) ?? [];
$items = dbFetchAll(
    $conn,
    surveySelectSql() . "{$where} ORDER BY {$orderBy} {$order}, s.id DESC LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

jsonResponse([
    'status' => 'Success',
    'message' => 'Client survey responses retrieved successfully.',
    'data' => [
        'items' => array_map('castSurveyRecord', $items),
        'pagination' => paginationMeta($page, $limit, $total),
        'summary' => [
            'total' => (int) ($summary['total'] ?? 0),
            'average_score' => (float) ($summary['average_score'] ?? 0),
            'excellent_count' => (int) ($summary['excellent_count'] ?? 0),
            'project_count' => (int) ($summary['project_count'] ?? 0),
        ],
        'sorting' => ['sort' => $sort, 'order' => strtolower($order)],
    ],
]);
