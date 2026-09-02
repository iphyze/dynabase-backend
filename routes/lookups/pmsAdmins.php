<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/lookup.php';
require_once __DIR__ . '/../../includes/permissions.php';

requireMethod('GET');
$authUser = authenticateUser();

$q = lookupSearchTerm();
$limit = lookupLimit(500, 500);
$offset = lookupOffset();
$role = userRole($authUser);

$where = " WHERE status = 'active' AND (role = 'pms_admin' OR is_pms_admin = 1)";
$types = '';
$params = [];

$canViewFullDirectory = userHasRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);
$restrictedPmsAdminId = null;

if (!$canViewFullDirectory) {
    if (userActsAsPmsAdmin($authUser)) {
        $restrictedPmsAdminId = (int) ($authUser['id'] ?? 0);
    } elseif ($role === DYNABASE_ROLE_PMS_USER) {
        $restrictedPmsAdminId = (int) ($authUser['parent_pms_admin_id'] ?? 0);
    } elseif ($role === DYNABASE_ROLE_USER) {
        // A normal user only receives the directory when an explicit workflow
        // permission requires selecting a PMS ownership/contact assignment.
        $canViewFullDirectory = userHasPermission($conn, $authUser, 'clients.create')
            || userHasPermission($conn, $authUser, 'clients.edit')
            || userHasPermission($conn, $authUser, 'keypersons.create')
            || userHasPermission($conn, $authUser, 'keypersons.edit');
    }
}

if (!$canViewFullDirectory && ($restrictedPmsAdminId === null || $restrictedPmsAdminId <= 0)) {
    lookupResponse('PMS Admin lookup retrieved successfully.', [], $limit, $offset, 0);
}

if (!$canViewFullDirectory) {
    $where .= ' AND id = ?';
    $types .= 'i';
    $params[] = $restrictedPmsAdminId;
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
