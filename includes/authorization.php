<?php
declare(strict_types=1);

require_once __DIR__ . '/authMiddleware.php';

const DYNABASE_ROLE_SUPER_ADMIN = 'super_admin';
const DYNABASE_ROLE_ADMIN = 'admin';
const DYNABASE_ROLE_PMS_ADMIN = 'pms_admin';
const DYNABASE_ROLE_PMS_USER = 'pms_user';
const DYNABASE_ROLE_USER = 'user';

const DYNABASE_ROLES = [
    DYNABASE_ROLE_SUPER_ADMIN,
    DYNABASE_ROLE_ADMIN,
    DYNABASE_ROLE_PMS_ADMIN,
    DYNABASE_ROLE_PMS_USER,
    DYNABASE_ROLE_USER,
];

function userRole(array $user): string
{
    return trim((string) ($user['role'] ?? ''));
}

function userHasRole(array $user, array $roles): bool
{
    return in_array(userRole($user), $roles, true);
}

function userActsAsPmsAdmin(array $user): bool
{
    return userRole($user) === DYNABASE_ROLE_PMS_ADMIN
        || (int) ($user['is_pms_admin'] ?? 0) === 1;
}

function isPmsWorkspaceUser(array $user): bool
{
    return userHasRole($user, [DYNABASE_ROLE_PMS_ADMIN, DYNABASE_ROLE_PMS_USER]);
}

function requireRole(array $user, array $roles, string $message = 'You are not authorised to perform this action.'): void
{
    if (!userHasRole($user, $roles)) {
        throw new RuntimeException($message, 403);
    }
}

function canInviteRole(array $actor, string $targetRole): bool
{
    $actorRole = userRole($actor);

    return match ($actorRole) {
        DYNABASE_ROLE_SUPER_ADMIN => in_array($targetRole, DYNABASE_ROLES, true),
        DYNABASE_ROLE_PMS_ADMIN => $targetRole === DYNABASE_ROLE_PMS_USER,
        default => false,
    };
}

function resolveParentPmsAdminIdForInvite(mysqli $conn, array $actor, string $targetRole, ?int $requestedParentId): ?int
{
    $actorRole = userRole($actor);

    if ($targetRole !== DYNABASE_ROLE_PMS_USER) {
        return null;
    }

    if ($actorRole === DYNABASE_ROLE_PMS_ADMIN) {
        return (int) $actor['id'];
    }

    if ($requestedParentId === null || $requestedParentId <= 0) {
        throw new RuntimeException('Please assign this user to a PMS Admin.', 422);
    }

    $stmt = $conn->prepare(
        "SELECT id FROM users WHERE id = ? AND status = 'active' AND (role = 'pms_admin' OR is_pms_admin = 1) LIMIT 1"
    );
    $stmt->bind_param('i', $requestedParentId);
    $stmt->execute();
    $pmsAdmin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pmsAdmin) {
        throw new RuntimeException('The selected PMS Admin is not available.', 422);
    }

    return $requestedParentId;
}

function resolveOwnerPmsAdminId(array $authUser): ?int
{
    if (userRole($authUser) === DYNABASE_ROLE_PMS_ADMIN) {
        return (int) $authUser['id'];
    }

    if (userRole($authUser) === DYNABASE_ROLE_PMS_USER) {
        return isset($authUser['parent_pms_admin_id']) && $authUser['parent_pms_admin_id']
            ? (int) $authUser['parent_pms_admin_id']
            : null;
    }

    return null;
}

function canViewUserList(array $actor): bool
{
    return userHasRole($actor, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_PMS_ADMIN]);
}

function canManageUserStatus(array $actor, array $target): bool
{
    $actorRole = userRole($actor);
    $targetRole = userRole($target);

    if ((int) $actor['id'] === (int) $target['id']) {
        return false;
    }

    if ($actorRole === DYNABASE_ROLE_SUPER_ADMIN) {
        return true;
    }

    if ($actorRole === DYNABASE_ROLE_PMS_ADMIN) {
        return $targetRole === DYNABASE_ROLE_PMS_USER
            && (int) ($target['parent_pms_admin_id'] ?? 0) === (int) $actor['id'];
    }

    return false;
}

function canDeactivateUser(array $actor, array $target): bool
{
    return canManageUserStatus($actor, $target);
}

function canActivateUser(array $actor, array $target): bool
{
    return canManageUserStatus($actor, $target);
}

function canResetUserPassword(array $actor, array $target): bool
{
    return canManageUserStatus($actor, $target);
}

function canResendUserInvitation(array $actor, array $target): bool
{
    if ((string) ($target['status'] ?? '') !== 'pending') {
        return false;
    }

    if ((int) ($actor['id'] ?? 0) === (int) ($target['id'] ?? 0)) {
        return false;
    }

    if (!canInviteRole($actor, userRole($target))) {
        return false;
    }

    if (userRole($actor) === DYNABASE_ROLE_PMS_ADMIN) {
        return userRole($target) === DYNABASE_ROLE_PMS_USER
            && (int) ($target['parent_pms_admin_id'] ?? 0) === (int) $actor['id'];
    }

    return userRole($actor) === DYNABASE_ROLE_SUPER_ADMIN;
}

function canUpdateUserProfile(array $actor, array $target): bool
{
    $actorRole = userRole($actor);
    $targetRole = userRole($target);

    if ($actorRole === DYNABASE_ROLE_SUPER_ADMIN) {
        return true;
    }

    if ($actorRole === DYNABASE_ROLE_PMS_ADMIN) {
        return $targetRole === DYNABASE_ROLE_PMS_USER
            && (int) ($target['parent_pms_admin_id'] ?? 0) === (int) $actor['id'];
    }

    return (int) $actor['id'] === (int) $target['id'];
}

function allowedManagedRoles(array $actor): array
{
    return match (userRole($actor)) {
        DYNABASE_ROLE_SUPER_ADMIN => DYNABASE_ROLES,
        DYNABASE_ROLE_PMS_ADMIN => [DYNABASE_ROLE_PMS_USER],
        default => [],
    };
}

function canAssignUserRole(array $actor, array $target, string $newRole): bool
{
    if ((int) $actor['id'] === (int) $target['id']) {
        return false;
    }

    if (!in_array($newRole, allowedManagedRoles($actor), true)) {
        return false;
    }

    return canUpdateUserProfile($actor, $target);
}

function buildPmsOwnershipWhereClause(array $authUser, string $tableAlias = ''): array
{
    $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';
    $role = userRole($authUser);

    if ($role === DYNABASE_ROLE_SUPER_ADMIN || $role === DYNABASE_ROLE_ADMIN || $role === DYNABASE_ROLE_USER) {
        return ['', []];
    }

    if ($role === DYNABASE_ROLE_PMS_ADMIN) {
        return [" AND {$prefix}owner_pms_admin_id = ?", [(int) $authUser['id']]];
    }

    if ($role === DYNABASE_ROLE_PMS_USER) {
        $ownerPmsAdminId = (int) ($authUser['parent_pms_admin_id'] ?? 0);
        if ($ownerPmsAdminId <= 0) {
            return [' AND 1 = 0', []];
        }
        return [" AND {$prefix}owner_pms_admin_id = ?", [$ownerPmsAdminId]];
    }

    return [' AND 1 = 0', []];
}

function userPublicPayload(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'full_name' => trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
        'email' => $user['email'],
        'role' => $user['role'],
        'is_pms_admin' => (int) ($user['is_pms_admin'] ?? 0) === 1,
        'status' => $user['status'],
        'must_change_password' => (int) ($user['must_change_password'] ?? 0) === 1 || ($user['must_change_password'] ?? false) === true,
        'password_changed_at' => $user['password_changed_at'] ?? null,
        'parent_pms_admin_id' => isset($user['parent_pms_admin_id']) && $user['parent_pms_admin_id'] !== null
            ? (int) $user['parent_pms_admin_id']
            : null,
    ];
}
