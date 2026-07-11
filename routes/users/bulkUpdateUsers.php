<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');

$actor = authenticateUser();
$data = readJsonBody();
$userIds = $data['user_ids'] ?? [];
$action = cleanString($data['action'] ?? '');
$newRole = isset($data['role']) ? cleanString($data['role']) : '';
$parentPmsAdminId = isset($data['parent_pms_admin_id']) && $data['parent_pms_admin_id'] !== ''
    ? (int) $data['parent_pms_admin_id']
    : null;

if (!is_array($userIds)) {
    throw new RuntimeException('Please select users to update.', 422);
}

$userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
if (count($userIds) === 0) {
    throw new RuntimeException('Please select users to update.', 422);
}

if (!in_array($action, ['activate', 'deactivate', 'update_role'], true)) {
    throw new RuntimeException('Please choose a valid bulk action.', 422);
}

if ($action === 'update_role' && !in_array($newRole, DYNABASE_ROLES, true)) {
    throw new RuntimeException('Please choose a valid role.', 422);
}

$processed = 0;
$skipped = 0;
$actorId = (int) $actor['id'];
$conn->begin_transaction();
try {
    $selectStmt = $conn->prepare(
        'SELECT id, first_name, last_name, email, role, is_pms_admin, status, parent_pms_admin_id, invited_by, created_by, updated_by, last_login_at, created_at
         FROM users
         WHERE id = ?
         LIMIT 1'
    );

    foreach ($userIds as $userId) {
        $selectStmt->bind_param('i', $userId);
        $selectStmt->execute();
        $target = $selectStmt->get_result()->fetch_assoc();
        if (!$target) {
            $skipped++;
            continue;
        }

        $target = normalizeUserRow($target);
        if ($action === 'activate') {
            if (!canActivateUser($actor, $target)) {
                $skipped++;
                continue;
            }
            $stmt = $conn->prepare('UPDATE users SET status = "active", updated_by = ? WHERE id = ?');
            $stmt->bind_param('ii', $actorId, $userId);
            $stmt->execute();
            $stmt->close();
            writeAuditLog($conn, $actor, 'users.bulk_activate', 'user', $userId, ['target_email' => $target['email']]);
            $processed++;
            continue;
        }

        if ($action === 'deactivate') {
            if (!canDeactivateUser($actor, $target)) {
                $skipped++;
                continue;
            }
            $stmt = $conn->prepare('UPDATE users SET status = "deactivated", updated_by = ? WHERE id = ?');
            $stmt->bind_param('ii', $actorId, $userId);
            $stmt->execute();
            $stmt->close();
            revokeAllUserSessions($conn, $userId);
            writeAuditLog($conn, $actor, 'users.bulk_deactivate', 'user', $userId, ['target_email' => $target['email']]);
            $processed++;
            continue;
        }

        if ($action === 'update_role') {
            if (!canAssignUserRole($actor, $target, $newRole)) {
                $skipped++;
                continue;
            }

            $resolvedParentPmsAdminId = null;
            if ($newRole === DYNABASE_ROLE_USER) {
                if (userRole($actor) === DYNABASE_ROLE_PMS_ADMIN) {
                    $resolvedParentPmsAdminId = (int) $actor['id'];
                } else {
                    $resolvedParentPmsAdminId = $parentPmsAdminId ?: ((int) ($target['parent_pms_admin_id'] ?? 0) ?: null);
                    if ($resolvedParentPmsAdminId === null || $resolvedParentPmsAdminId <= 0) {
                        $skipped++;
                        continue;
                    }

                    $pmsStmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND status = 'active' AND (role = 'pms_admin' OR is_pms_admin = 1) LIMIT 1");
                    $pmsStmt->bind_param('i', $resolvedParentPmsAdminId);
                    $pmsStmt->execute();
                    $pmsAdmin = $pmsStmt->get_result()->fetch_assoc();
                    $pmsStmt->close();
                    if (!$pmsAdmin) {
                        $skipped++;
                        continue;
                    }
                }
            }

            $stmt = $conn->prepare('UPDATE users SET role = ?, is_pms_admin = ?, parent_pms_admin_id = ?, updated_by = ? WHERE id = ?');
            $bulkPmsCapability = $newRole === DYNABASE_ROLE_PMS_ADMIN ? 1 : 0;
            $stmt->bind_param('siiii', $newRole, $bulkPmsCapability, $resolvedParentPmsAdminId, $actorId, $userId);
            $stmt->execute();
            $stmt->close();
            writeAuditLog($conn, $actor, 'users.bulk_update_role', 'user', $userId, [
                'target_email' => $target['email'],
                'previous_role' => $target['role'],
                'new_role' => $newRole,
            ]);
            $processed++;
        }
    }

    $selectStmt->close();
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

jsonResponse([
    'status' => 'Success',
    'message' => "Bulk update completed. {$processed} user(s) updated" . ($skipped > 0 ? " and {$skipped} skipped." : '.'),
    'data' => [
        'processed' => $processed,
        'skipped' => $skipped,
    ],
]);
