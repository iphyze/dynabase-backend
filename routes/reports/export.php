<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/reports.php';

requireMethod('GET');
$authUser = authenticateUser();
requireRole(
    $authUser,
    [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN],
    'Reports are only available to Super Admins and Admins.'
);

$type = strtolower(cleanString($_GET['type'] ?? ''));
if (!in_array($type, dynabaseReportKnownTypes(), true)) {
    throw new RuntimeException('Please choose a valid report type.', 422);
}

dynabaseReportRequireExportAccess($conn, $authUser, $type);

$giftYear = dynabaseReportYearFilter($_GET['year'] ?? 'all');
$sheets = dynabaseReportWorkbook($conn, $type, $giftYear, $authUser);
if ($sheets === []) {
    throw new RuntimeException('This report is not available because its module has not been configured yet.', 409);
}

$filename = dynabaseReportFilename($type, $giftYear);
writeAuditLog($conn, $authUser, 'report.exported', 'report', $type, [
    'report_type' => $type,
    'selected_year' => $giftYear > 0 ? $giftYear : 'all',
    'filename' => $filename,
    'worksheet_count' => count($sheets),
    'module_view_permission' => dynabaseReportAccessRules()[$type]['view'] ?? null,
    'module_export_permission' => dynabaseReportAccessRules()[$type]['export'] ?? null,
]);

dynabaseOutputXlsx($filename, $sheets, [
    'title' => ucwords(str_replace('-', ' ', $type)) . ' Report',
    'subject' => 'Dynabase project intelligence report',
    'creator' => trim((string) (($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? ''))) ?: 'Dynabase',
]);
