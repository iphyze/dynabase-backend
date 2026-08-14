<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/reports.php';

requireMethod('GET');
$authUser = authenticateUser();
$filters = dynabaseReportAgreementFilters($_GET);

jsonResponse([
    'status' => 'Success',
    'message' => 'Agreement Register reporting retrieved successfully.',
    'data' => dynabaseReportAgreementOverview($conn, $authUser, $filters),
]);
