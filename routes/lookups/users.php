<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/lookup.php';

requireMethod('GET');
$authUser = authenticateUser();

if (!userHasRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_PMS_ADMIN])) {
    throw new RuntimeException('You are not authorised to view user lookup.', 403);
}

$q = lookupSearchTerm();
$role = cleanString($_GET['role'] ?? '');
$status = cleanString($_GET['status'] ?? 'active');
$limit = lookupLimit(20, 50);
$offset = lookupOffset();

$where = ' WHERE 1 = 1';
$types = '';
$params = [];

if (userRole($authUser) === DYNABASE_ROLE_PMS_ADMIN) {
    $where .= ' AND parent_pms_admin_id = ? AND role = ?';
    $types .= 'is';
    array_push($params, (int) $authUser['id'], DYNABASE_ROLE_USER);
} elseif ($role !== '') {
    if (!in_array($role, DYNABASE_ROLES, true)) {
        throw new RuntimeException('Invalid role filter.', 422);
    }
    $where .= ' AND role = ?';
    $types .= 's';
    $params[] = $role;
}

if ($status !== '' && $status !== 'all') {
    if (!in_array($status, ['pending', 'active', 'inactive', 'deactivated'], true)) {
        throw new RuntimeException('Invalid status filter.', 422);
    }
    $where .= ' AND status = ?';
    $types .= 's';
    $params[] = $status;
}

if ($q !== '') {
    $where .= ' AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)';
    $like = likeTerm($q);
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}

$total = dbScalarInt($conn, "SELECT COUNT(*) AS total FROM users{$where}", $types, $params);
$rows = dbFetchAll(
    $conn,
    "SELECT id, first_name, last_name, email, role, status, parent_pms_admin_id
     FROM users{$where}
     ORDER BY first_name ASC, last_name ASC
     LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

$data = array_map(static function (array $row): array {
    $fullName = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
    $label = $fullName !== '' ? $fullName . ' (' . $row['email'] . ')' : (string) $row['email'];

    return optionRow((int) $row['id'], $label, [
        'id' => (int) $row['id'],
        'first_name' => $row['first_name'],
        'last_name' => $row['last_name'],
        'email' => $row['email'],
        'role' => $row['role'],
        'status' => $row['status'],
        'parent_pms_admin_id' => $row['parent_pms_admin_id'] !== null ? (int) $row['parent_pms_admin_id'] : null,
    ]);
}, $rows);

lookupResponse('User lookup retrieved successfully.', $data, $limit, $offset, $total);
