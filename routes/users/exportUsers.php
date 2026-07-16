<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';

requireMethod('GET');

$actor = authenticateUser();
requireRole(
    $actor,
    [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_PMS_ADMIN],
    'Only permitted users can download users.'
);

$search = trim((string) ($_GET['search'] ?? ''));

$allowedSorts = [
    'name' => 'u.first_name',
    'email' => 'u.email',
    'role' => 'u.role',
    'status' => 'u.status',
    'last_login_at' => 'u.last_login_at',
    'created_at' => 'u.created_at',
];
$sort = (string) ($_GET['sort'] ?? 'created_at');
$order = strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$sortSql = $allowedSorts[$sort] ?? $allowedSorts['created_at'];

$where = 'WHERE 1 = 1';
$params = [];
$types = '';

if (userRole($actor) === DYNABASE_ROLE_PMS_ADMIN) {
    $where .= ' AND u.parent_pms_admin_id = ? AND u.role = "pms_user"';
    $params[] = (int) $actor['id'];
    $types .= 'i';
}

if ($search !== '') {
    $where .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)';
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $types .= 'sss';
}

$sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.is_pms_admin, u.status,
               u.parent_pms_admin_id, u.created_at, u.updated_at, u.last_login_at,
               p.first_name AS parent_first_name, p.last_name AS parent_last_name, p.email AS parent_email
        FROM users u
        LEFT JOIN users p ON p.id = u.parent_pms_admin_id
        {$where}
        ORDER BY {$sortSql} {$order}, u.id DESC
        LIMIT 10000";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$filename = 'dynabase-users-' . date('Y-m-d-His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');

fputcsv($out, [
    'ID',
    'First Name',
    'Last Name',
    'Full Name',
    'Email',
    'Role',
    'PMS Ownership Capability',
    'Status',
    'PMS Admin Owner',
    'PMS Admin Email',
    'Created At',
    'Updated At',
    'Last Login',
]);

while ($row = $result->fetch_assoc()) {
    $fullName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
    $parentName = trim((string) ($row['parent_first_name'] ?? '') . ' ' . (string) ($row['parent_last_name'] ?? ''));

    fputcsv($out, [
        $row['id'] ?? '',
        $row['first_name'] ?? '',
        $row['last_name'] ?? '',
        $fullName,
        $row['email'] ?? '',
        ucwords(str_replace('_', ' ', (string) ($row['role'] ?? ''))),
        (int) ($row['is_pms_admin'] ?? 0) === 1 ? 'Yes' : 'No',
        ucwords((string) ($row['status'] ?? '')),
        $parentName !== '' ? $parentName : ($row['parent_email'] ?? ''),
        $row['parent_email'] ?? '',
        $row['created_at'] ?? '',
        $row['updated_at'] ?? '',
        $row['last_login_at'] ?? '',
    ]);
}

$stmt->close();
fclose($out);
exit;
