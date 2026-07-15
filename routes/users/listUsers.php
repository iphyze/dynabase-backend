<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/permissions.php';

requireMethod('GET');

$actor = authenticateUser();
if (!canViewUserList($actor)) {
    throw new RuntimeException('You are not allowed to view user lists.', 403);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = max(5, min(100, (int) ($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;
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
$order = strtolower((string) ($_GET['order'] ?? 'desc'));
$order = $order === 'asc' ? 'ASC' : 'DESC';
$sortSql = $allowedSorts[$sort] ?? $allowedSorts['created_at'];

$where = 'WHERE 1 = 1';
$params = [];
$types = '';

if (userRole($actor) === DYNABASE_ROLE_PMS_ADMIN) {
    $where .= ' AND u.parent_pms_admin_id = ? AND u.role = "pms_user"';
    $params[] = (int) $actor['id'];
    $types .= 'i';
}

if (userRole($actor) === DYNABASE_ROLE_ADMIN) {
    $where .= ' AND u.role IN ("admin", "pms_admin", "pms_user", "user")';
}

if ($search !== '') {
    $where .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)';
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $types .= 'sss';
}

$countSql = "SELECT COUNT(*) AS total FROM users u {$where}";
$countStmt = $conn->prepare($countSql);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.is_pms_admin, u.status, u.parent_pms_admin_id,
               u.invited_by, u.created_by, u.updated_by, u.created_at, u.updated_at, u.last_login_at,
               p.first_name AS parent_first_name, p.last_name AS parent_last_name, p.email AS parent_email
        FROM users u
        LEFT JOIN users p ON p.id = u.parent_pms_admin_id
        {$where}
        ORDER BY {$sortSql} {$order}, u.id DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$queryParams = $params;
$queryTypes = $types;
$queryParams[] = $limit;
$queryParams[] = $offset;
$queryTypes .= 'ii';
$stmt->bind_param($queryTypes, ...$queryParams);
$stmt->execute();
$result = $stmt->get_result();
$users = [];
while ($row = $result->fetch_assoc()) {
    $row = normalizeUserRow($row);
    $parentName = trim((string) ($row['parent_first_name'] ?? '') . ' ' . (string) ($row['parent_last_name'] ?? ''));
    $users[] = userPublicPayload($row) + [
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'] ?? null,
        'last_login_at' => $row['last_login_at'],
        'parent_pms_admin_name' => $parentName !== '' ? $parentName : ($row['parent_email'] ?? null),
        'parent_pms_admin_email' => $row['parent_email'] ?? null,
        'can_activate' => userHasPermission($conn, $actor, 'users.status') && canActivateUser($actor, $row),
        'can_deactivate' => userHasPermission($conn, $actor, 'users.status') && canDeactivateUser($actor, $row),
        'can_update' => userHasPermission($conn, $actor, 'users.edit') && canUpdateUserProfile($actor, $row),
        'can_reset_password' => userHasPermission($conn, $actor, 'users.reset') && canResetUserPassword($actor, $row),
        'allowed_roles' => userHasPermission($conn, $actor, 'users.edit') ? allowedManagedRoles($actor) : [],
        'permissions' => userEffectivePermissions($conn, $row),
    ];
}
$stmt->close();

jsonResponse([
    'status' => 'Success',
    'data' => [
        'items' => $users,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int) ceil($total / $limit),
        ],
        'sorting' => [
            'sort' => $sort,
            'order' => strtolower($order),
        ],
    ]
]);
