<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/permissions.php';

use Respect\Validation\Validator as v;

requireMethod('POST');

$actor = authenticateUser();
$data = readJsonBody();
$userId = (int) ($data['user_id'] ?? $data['id'] ?? 0);
$firstName = cleanString($data['first_name'] ?? '');
$lastName = cleanString($data['last_name'] ?? '');
$role = isset($data['role']) ? cleanString($data['role']) : null;
$requestedPmsCapability = ($data['is_pms_admin'] ?? false) === true || (int) ($data['is_pms_admin'] ?? 0) === 1;
$status = isset($data['status']) ? cleanString($data['status']) : null;
$submittedPermissionKeys = $data['permission_keys'] ?? null;

$parentPmsAdminId = isset($data['parent_pms_admin_id']) && $data['parent_pms_admin_id'] !== ''
    ? (int) $data['parent_pms_admin_id']
    : null;

if ($userId <= 0) {
    throw new RuntimeException('Please select a valid user.', 422);
}

if ($firstName === '' || $lastName === '') {
    throw new RuntimeException('First name and last name are required.', 422);
}

if (!v::length(2, 100)->validate($firstName) || !v::length(2, 100)->validate($lastName)) {
    throw new RuntimeException('First name and last name must be between 2 and 100 characters.', 422);
}

$stmt = $conn->prepare(
    'SELECT id, first_name, last_name, email, role, is_pms_admin, status, parent_pms_admin_id, invited_by, created_by, updated_by, last_login_at, created_at
     FROM users
     WHERE id = ?
     LIMIT 1'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$target = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$target) {
    throw new RuntimeException('User not found.', 404);
}

$target = normalizeUserRow($target);
if (!canUpdateUserProfile($actor, $target)) {
    throw new RuntimeException('User not found.', 404);
}

$nextRole = $role ?: (string) $target['role'];
$nextIsPmsAdmin = $nextRole === DYNABASE_ROLE_PMS_ADMIN
    ? 1
    : ($nextRole === DYNABASE_ROLE_ADMIN && $requestedPmsCapability ? 1 : 0);
if (!in_array($nextRole, DYNABASE_ROLES, true)) {
    throw new RuntimeException('Please choose a valid role.', 422);
}

$actorRole = userRole($actor);
if ($nextRole !== (string) $target['role'] && !canAssignUserRole($actor, $target, $nextRole)) {
    throw new RuntimeException('You are not allowed to assign this role.', 403);
}

if ((int) $actor['id'] === $userId) {
    $nextRole = (string) $target['role'];
    $status = (string) $target['status'];
}

$nextStatus = $status ?: (string) $target['status'];
if (!in_array($nextStatus, ['pending', 'active', 'inactive', 'deactivated'], true)) {
    throw new RuntimeException('Please choose a valid status.', 422);
}

if ($nextStatus !== (string) $target['status']) {
    requirePermission($conn, $actor, 'users.status', 'You do not have permission to change user account status.');
    if (!canManageUserStatus($actor, $target)) {
        throw new RuntimeException('User not found.', 404);
    }
}

$resolvedParentPmsAdminId = null;
if ($nextRole === DYNABASE_ROLE_PMS_USER) {
    if ($actorRole === DYNABASE_ROLE_PMS_ADMIN) {
        $resolvedParentPmsAdminId = (int) $actor['id'];
    } else {
        $resolvedParentPmsAdminId = $parentPmsAdminId ?: ((int) ($target['parent_pms_admin_id'] ?? 0) ?: null);
        if ($resolvedParentPmsAdminId === null || $resolvedParentPmsAdminId <= 0) {
            throw new RuntimeException('Please assign this user to a PMS Admin.', 422);
        }

        $pmsStmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND status = 'active' AND (role = 'pms_admin' OR is_pms_admin = 1) LIMIT 1");
        $pmsStmt->bind_param('i', $resolvedParentPmsAdminId);
        $pmsStmt->execute();
        $pmsAdmin = $pmsStmt->get_result()->fetch_assoc();
        $pmsStmt->close();

        if (!$pmsAdmin) {
            throw new RuntimeException('The selected PMS Admin is not available.', 422);
        }
    }
}

$permissionInput = is_array($submittedPermissionKeys)
    ? $submittedPermissionKeys
    : userEffectivePermissions($conn, $target);
$resolvedPermissionKeys = resolveSubmittedPermissions($conn, $actor, $nextRole, $permissionInput);

$actorId = (int) $actor['id'];
$updateStmt = $conn->prepare(
    'UPDATE users
     SET first_name = ?, last_name = ?, role = ?, is_pms_admin = ?, status = ?, parent_pms_admin_id = ?, updated_by = ?
     WHERE id = ?'
);
$updateStmt->bind_param('sssisiii', $firstName, $lastName, $nextRole, $nextIsPmsAdmin, $nextStatus, $resolvedParentPmsAdminId, $actorId, $userId);
$updateStmt->execute();
$updateStmt->close();

replaceUserPermissions($conn, $userId, $resolvedPermissionKeys, $actorId, $nextRole);

writeAuditLog($conn, $actor, 'users.update', 'user', $userId, [
    'target_email' => $target['email'],
    'previous_role' => $target['role'],
    'new_role' => $nextRole,
    'is_pms_admin' => $nextIsPmsAdmin === 1,
    'previous_status' => $target['status'],
    'new_status' => $nextStatus,
    'permissions' => $resolvedPermissionKeys,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'User updated successfully.'
]);
