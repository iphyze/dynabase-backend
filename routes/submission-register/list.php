<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/submissionRegister.php';
require_once __DIR__ . '/../../includes/pagination.php';

requireMethod('GET');
$authUser = authenticateUser();
ensureSubmissionRegisterSchema($conn);
[$page, $limit, $offset] = paginationParams();

$sort = cleanString($_GET['sort'] ?? 'updated_at');
$order = strtolower(cleanString($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$sortMap = [
    'submission_reference' => 'sr.submission_reference',
    'project_company_name' => 'sr.project_company_name',
    'client_name' => 'sr.client_name',
    'category' => 'sr.category',
    'date_received' => 'sr.date_received',
    'date_submitted' => 'sr.date_submitted',
    'mode_of_submission' => 'sr.mode_of_submission',
    'status' => 'sr.status',
    'created_at' => 'sr.created_at',
    'updated_at' => 'sr.updated_at',
];
$orderBy = $sortMap[$sort] ?? $sortMap['updated_at'];
if (!isset($sortMap[$sort])) {
    $sort = 'updated_at';
}

[$where, $types, $params] = submissionRegisterListWhere($authUser, $_GET);

try {
    $total = dbScalarInt($conn, "SELECT COUNT(*) AS total FROM submission_registers sr{$where}", $types, $params);
    $summary = submissionRegisterSummary($conn, $where, $types, $params);
    $rows = dbFetchAll(
        $conn,
        submissionRegisterListSelectSql() . "{$where} ORDER BY {$orderBy} {$order}, sr.id DESC LIMIT ? OFFSET ?",
        $types . 'ii',
        array_merge($params, [$limit, $offset])
    );
    $rows = hydrateSubmissionRegisterListUpdates($conn, $rows);
} catch (mysqli_sql_exception $exception) {
    error_log(sprintf(
        '[Dynabase Submission Register] List query failed (MySQL %d): %s',
        (int) $exception->getCode(),
        $exception->getMessage()
    ));

    throw new RuntimeException(
        'Submission Register list query failed.',
        500,
        $exception
    );
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Submission register retrieved successfully.',
    'data' => [
        'items' => array_map('castSubmissionRegisterRecord', $rows),
        'pagination' => paginationMeta($page, $limit, $total),
        'summary' => $summary,
        'sorting' => ['sort' => $sort, 'order' => strtolower($order)],
    ],
]);
