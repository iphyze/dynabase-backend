<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/reports.php';

requireMethod('GET');
$authUser = authenticateUser();
requireRole(
    $authUser,
    [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN],
    'Reports are only available to Super Admins and Admins.'
);

$giftYear = dynabaseReportYearFilter($_GET['year'] ?? 'all');

jsonResponse([
    'status' => 'Success',
    'message' => 'Report overview retrieved successfully.',
    'data' => dynabaseReportOverview($conn, $giftYear),
]);
