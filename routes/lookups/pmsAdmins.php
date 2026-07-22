<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/lookup.php';

requireMethod('GET');
$authUser = authenticateUser();

$q = lookupSearchTerm();
$limit = lookupLimit(500, 500);
$offset = lookupOffset();

$where = " WHERE status = 'active' AND (role = 'pms_admin' OR is_pms_admin = 1)";
$types = '';
$params = [];

if ($q !== '') {
    $where .= ' AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)';
    $like = likeTerm($q);
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}

$total = dbScalarInt($conn, "SELECT COUNT(*) AS total FROM users{$where}", $types, $params);
$rows = dbFetchAll(
    $conn,
    "SELECT id, first_name, last_name, email, role, is_pms_admin
     FROM users{$where}
     ORDER BY first_name ASC, last_name ASC
     LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

$data = array_map(static function (array $row): array {
    $fullName = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
    $label = $fullName !== '' ? $fullName : 'PMS Admin #' . (int) $row['id'];

    return optionRow((int) $row['id'], $label, [
        'id' => (int) $row['id'],
        'first_name' => $row['first_name'],
        'last_name' => $row['last_name'],
        'email' => $row['email'],
        'role' => $row['role'],
        'is_pms_admin' => (int) $row['is_pms_admin'] === 1,
    ]);
}, $rows);

lookupResponse('PMS Admin lookup retrieved successfully.', $data, $limit, $offset, $total);
