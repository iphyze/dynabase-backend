<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/permissions.php';

requireMethod('GET');

$actor = authenticateUser();
$userId = (int) ($_GET['id'] ?? 0);

if ($userId <= 0) {
    throw new RuntimeException('Please select a valid user.', 422);
}

$stmt = $conn->prepare(
    'SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.is_pms_admin, u.status, u.parent_pms_admin_id,
            u.invited_by, u.created_by, u.updated_by, u.created_at, u.updated_at, u.last_login_at,
            u.must_change_password, u.password_changed_at,
            p.first_name AS parent_first_name, p.last_name AS parent_last_name, p.email AS parent_email
     FROM users u
     LEFT JOIN users p ON p.id = u.parent_pms_admin_id
     WHERE u.id = ?
     LIMIT 1'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    throw new RuntimeException('User not found.', 404);
}

$row = normalizeUserRow($row);
if (!canUpdateUserProfile($actor, $row)) {
    throw new RuntimeException('You are not allowed to update this user.', 403);
}

$parentName = trim((string) ($row['parent_first_name'] ?? '') . ' ' . (string) ($row['parent_last_name'] ?? ''));

jsonResponse([
    'status' => 'Success',
    'data' => userPublicPayload($row) + [
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'last_login_at' => $row['last_login_at'] ?? null,
        'parent_pms_admin_name' => $parentName !== '' ? $parentName : ($row['parent_email'] ?? null),
        'parent_pms_admin_email' => $row['parent_email'] ?? null,
        'can_update' => true,
        'allowed_roles' => allowedManagedRoles($actor),
        'permissions' => userEffectivePermissions($conn, $row),
    ],
]);
