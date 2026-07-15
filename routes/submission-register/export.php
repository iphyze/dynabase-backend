<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/submissionRegister.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('GET');
$authUser = authenticateUser();
ensureSubmissionRegisterSchema($conn);
[$where, $types, $params] = submissionRegisterListWhere($authUser, $_GET);
$rows = dbFetchAll(
    $conn,
    submissionRegisterSelectSql() . "{$where} ORDER BY sr.date_submitted DESC, sr.updated_at DESC, sr.id DESC LIMIT 20000",
    $types,
    $params
);

$filename = 'dynabase-submission-register-' . date('Y-m-d') . '.csv';
writeAuditLog($conn, $authUser, 'submission_register.exported', 'submission_register_report', 'csv', [
    'filename' => $filename,
    'record_count' => count($rows),
]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
$output = fopen('php://output', 'wb');
fputcsv($output, [
    'Reference', 'Project / Company', 'Tender Code', 'Client', 'Category', 'Date Received', 'Date Submitted',
    'Mode of Submission', 'Hard Copy Contact Name', 'Hard Copy Contact Email', 'Hard Copy Contact Phone',
    'Hard Copy Address', 'Email Recipient', 'Status', 'PMS Owner', 'Updates', 'Latest Update',
    'Created By', 'Created At', 'Updated By', 'Updated At'
]);
foreach ($rows as $row) {
    fputcsv($output, [
        $row['submission_reference'],
        $row['project_company_name'],
        $row['project_tender_code'] ?: $row['linked_project_tender_code'],
        $row['client_name'],
        $row['category'],
        $row['date_received'],
        $row['date_submitted'],
        $row['mode_of_submission'],
        $row['hard_copy_contact_name'],
        $row['hard_copy_contact_email'],
        $row['hard_copy_contact_phone'],
        $row['hard_copy_contact_address'],
        $row['email_recipient'],
        $row['status'],
        $row['owner_pms_admin_name'] ?: ($row['owner_pms_admin_email'] ?: 'Global / unassigned'),
        (int) ($row['updates_count'] ?? 0),
        $row['latest_update_message'] ?? '',
        $row['created_by_name'] ?: $row['created_by'],
        $row['created_at'],
        $row['updated_by_name'] ?: $row['updated_by'],
        $row['updated_at'],
    ]);
}
fclose($output);
exit;
