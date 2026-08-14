<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/agreements.php';
require_once __DIR__ . '/../../includes/pagination.php';

requireMethod('GET');
$authUser = authenticateUser();
[$page, $limit, $offset] = paginationParams();

$q = cleanString($_GET['q'] ?? $_GET['search'] ?? '');
$type = cleanString($_GET['document_ref_type'] ?? '');
$status = cleanString($_GET['status'] ?? '');
$issuedBy = cleanString($_GET['issued_by'] ?? '');
$renewal = cleanString($_GET['renewal'] ?? '');
$department = cleanString($_GET['department'] ?? '');
$clientId = (int) ($_GET['client_id'] ?? 0);
$ownerRaw = cleanString($_GET['owner_pms_admin_id'] ?? '');
$ownerId = (int) $ownerRaw;
$reminderDue = cleanString($_GET['reminder_due'] ?? '');
$signaturePending = cleanString($_GET['signature_pending'] ?? '');
$sort = cleanString($_GET['sort'] ?? 'updated_at');
$order = strtolower(cleanString($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

if ($type !== '' && !in_array($type, DYNABASE_AGREEMENT_TYPES, true)) {
    throw new RuntimeException('Invalid document reference type filter.', 422);
}
if ($status !== '' && !in_array($status, DYNABASE_AGREEMENT_STATUSES, true)) {
    throw new RuntimeException('Invalid status filter.', 422);
}
if ($issuedBy !== '' && !in_array($issuedBy, DYNABASE_AGREEMENT_ISSUED_BY, true)) {
    throw new RuntimeException('Invalid Issued By filter.', 422);
}
if ($renewal !== '' && !in_array($renewal, DYNABASE_AGREEMENT_RENEWAL_OPTIONS, true)) {
    throw new RuntimeException('Invalid renewal filter.', 422);
}

$sortMap = [
    'document_ref_no' => 'a.document_ref_no',
    'client_company' => 'a.client_company',
    'date_issued' => 'a.date_issued',
    'effective_date' => 'a.effective_date',
    'expiry_date' => 'a.expiry_date',
    'reminder_date' => 'a.reminder_date',
    'status' => 'a.status',
    'created_at' => 'a.created_at',
    'updated_at' => 'a.updated_at',
];
$orderBy = $sortMap[$sort] ?? $sortMap['updated_at'];
if (!isset($sortMap[$sort])) {
    $sort = 'updated_at';
}

$where = " WHERE a.record_status = 'active'";
$types = '';
$params = [];

if ($q !== '') {
    $where .= ' AND (a.document_ref_no LIKE ? OR a.project_subject LIKE ? OR a.client_company LIKE ?
                   OR a.counterparty_contact_person LIKE ? OR a.purpose LIKE ? OR a.department LIKE ?
                   OR a.lambert_signatory LIKE ? OR a.client_signatory LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ssssssss';
    for ($index = 0; $index < 8; $index++) {
        $params[] = $like;
    }
}
if ($type !== '') {
    $where .= ' AND a.document_ref_type = ?';
    $types .= 's';
    $params[] = $type;
}
if ($status !== '') {
    $where .= ' AND a.status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($issuedBy !== '') {
    $where .= ' AND a.issued_by = ?';
    $types .= 's';
    $params[] = $issuedBy;
}
if ($renewal !== '') {
    $where .= ' AND a.renewal = ?';
    $types .= 's';
    $params[] = $renewal;
}
if ($department !== '') {
    $where .= ' AND a.department LIKE ?';
    $types .= 's';
    $params[] = '%' . $department . '%';
}
if ($clientId > 0) {
    $where .= ' AND a.client_id = ?';
    $types .= 'i';
    $params[] = $clientId;
}
if ($ownerRaw === 'global') {
    $where .= ' AND a.owner_pms_admin_id IS NULL';
} elseif ($ownerId > 0 && isGlobalDataUser($authUser)) {
    $where .= ' AND a.owner_pms_admin_id = ?';
    $types .= 'i';
    $params[] = $ownerId;
}
if ($reminderDue === '1' || strtolower($reminderDue) === 'yes') {
    $where .= ' AND a.reminder_sent_at IS NULL AND a.reminder_date <= CURRENT_DATE()';
}
if ($signaturePending === '1' || strtolower($signaturePending) === 'yes') {
    $where .= " AND a.status IN ('Awaiting Client Signature', 'Awaiting Lambert Signature')";
}

[$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'a');
$where .= $scopeSql;
$types .= $scopeTypes;
$params = array_merge($params, $scopeParams);

$total = dbScalarInt($conn, 'SELECT COUNT(*) AS total FROM agreement_registers a' . $where, $types, $params);
$summary = dbFetchOne(
    $conn,
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN a.document_ref_type = 'NDA' THEN 1 ELSE 0 END) AS nda,
            SUM(CASE WHEN a.document_ref_type = 'MOU' THEN 1 ELSE 0 END) AS mou,
            SUM(CASE WHEN a.status IN ('Awaiting Client Signature', 'Awaiting Lambert Signature') THEN 1 ELSE 0 END) AS awaiting_signature,
            SUM(CASE WHEN a.status = 'Active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN a.status = 'Expiring Soon' THEN 1 ELSE 0 END) AS expiring_soon,
            SUM(CASE WHEN a.status = 'Expired' THEN 1 ELSE 0 END) AS expired,
            SUM(CASE WHEN a.reminder_sent_at IS NULL AND a.reminder_date <= CURRENT_DATE() THEN 1 ELSE 0 END) AS reminders_due
     FROM agreement_registers a{$where}",
    $types,
    $params
) ?? [];

$rows = dbFetchAll(
    $conn,
    agreementSelectSql() . "{$where} ORDER BY {$orderBy} {$order}, a.id DESC LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);
$rows = array_map('castAgreementRecord', $rows);

jsonResponse([
    'status' => 'Success',
    'message' => 'Agreement Register retrieved successfully.',
    'data' => [
        'items' => $rows,
        'pagination' => paginationMeta($page, $limit, $total),
        'summary' => [
            'total' => (int) ($summary['total'] ?? 0),
            'nda' => (int) ($summary['nda'] ?? 0),
            'mou' => (int) ($summary['mou'] ?? 0),
            'awaiting_signature' => (int) ($summary['awaiting_signature'] ?? 0),
            'active' => (int) ($summary['active'] ?? 0),
            'expiring_soon' => (int) ($summary['expiring_soon'] ?? 0),
            'expired' => (int) ($summary['expired'] ?? 0),
            'reminders_due' => (int) ($summary['reminders_due'] ?? 0),
        ],
        'sorting' => ['sort' => $sort, 'order' => strtolower($order)],
    ],
]);
