<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/submissionRegister.php';

requireMethod('GET');
$authUser = authenticateUser();
ensureSubmissionRegisterSchema($conn);
[$where, $types, $params] = submissionRegisterListWhere($authUser, $_GET);
$summary = submissionRegisterSummary($conn, $where, $types, $params);

$categoryRows = dbFetchAll(
    $conn,
    "SELECT sr.category AS label, COUNT(*) AS total FROM submission_registers sr{$where} GROUP BY sr.category ORDER BY total DESC, sr.category ASC",
    $types,
    $params
);
$statusRows = dbFetchAll(
    $conn,
    "SELECT sr.status AS label, COUNT(*) AS total FROM submission_registers sr{$where} GROUP BY sr.status ORDER BY total DESC, sr.status ASC",
    $types,
    $params
);
$modeRows = dbFetchAll(
    $conn,
    "SELECT sr.mode_of_submission AS label, COUNT(*) AS total FROM submission_registers sr{$where} GROUP BY sr.mode_of_submission ORDER BY total DESC, sr.mode_of_submission ASC",
    $types,
    $params
);
$monthlyRows = dbFetchAll(
    $conn,
    "SELECT DATE_FORMAT(sr.date_submitted, '%Y-%m') AS period, COUNT(*) AS total,
            SUM(CASE WHEN sr.status = 'Completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN sr.status = 'Ongoing' THEN 1 ELSE 0 END) AS ongoing
     FROM submission_registers sr{$where}
     GROUP BY DATE_FORMAT(sr.date_submitted, '%Y-%m')
     ORDER BY period DESC
     LIMIT 12",
    $types,
    $params
);
$recentRows = dbFetchAll(
    $conn,
    submissionRegisterSelectSql() . "{$where} ORDER BY sr.updated_at DESC, sr.id DESC LIMIT 8",
    $types,
    $params
);

$normaliseMetricRows = static fn (array $rows): array => array_map(static fn (array $row): array => [
    'label' => (string) ($row['label'] ?? ''),
    'total' => (int) ($row['total'] ?? 0),
], $rows);

jsonResponse([
    'status' => 'Success',
    'message' => 'Submission register reporting overview retrieved successfully.',
    'data' => [
        'summary' => $summary,
        'category_breakdown' => $normaliseMetricRows($categoryRows),
        'status_breakdown' => $normaliseMetricRows($statusRows),
        'mode_breakdown' => $normaliseMetricRows($modeRows),
        'monthly_trend' => array_reverse(array_map(static fn (array $row): array => [
            'period' => (string) ($row['period'] ?? ''),
            'total' => (int) ($row['total'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'ongoing' => (int) ($row['ongoing'] ?? 0),
        ], $monthlyRows)),
        'recent_items' => array_map('castSubmissionRegisterRecord', $recentRows),
    ],
]);
