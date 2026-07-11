<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$ids = $payload['ids'] ?? [];
$action = optionalStringField($payload, 'action');

if (!is_array($ids)) {
    throw new RuntimeException('Please select at least one client.', 422);
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
if ($ids === []) {
    throw new RuntimeException('Please select at least one client.', 422);
}

if (!in_array($action, ['activate', 'deactivate', 'update_category', 'update_owner'], true)) {
    throw new RuntimeException('Please choose a valid bulk action.', 422);
}

$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];
$updated = 0;

$conn->begin_transaction();
try {
    foreach ($ids as $id) {
        $client = assertClientAccessible($conn, $authUser, $id, true);

        if ($action === 'activate' || $action === 'deactivate') {
            $status = $action === 'activate' ? 'active' : 'deactivated';
            dbExecute(
                $conn,
                'UPDATE clients_table SET status = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                'ssii',
                [$status, $actorEmail, $actorId, $id]
            )->close();

            if ($action === 'deactivate') {
                dbExecute(
                    $conn,
                    "UPDATE keypersons_table SET status = 'deactivated', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE clients_id = ?",
                    'sii',
                    [$actorEmail, $actorId, $id]
                )->close();
            }
        }

        if ($action === 'update_category') {
            $category = requireStringField($payload, 'clients_category', 'Client category');
            dbExecute(
                $conn,
                'UPDATE clients_table SET clients_category = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                'ssii',
                [$category, $actorEmail, $actorId, $id]
            )->close();
            dbExecute(
                $conn,
                'UPDATE keypersons_table SET clients_category = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE clients_id = ?',
                'ssii',
                [$category, $actorEmail, $actorId, $id]
            )->close();
            dbExecute(
                $conn,
                'UPDATE log_table SET clients_category = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE clients_id = ?',
                'ssii',
                [$category, $actorEmail, $actorId, $id]
            )->close();
        }

        if ($action === 'update_owner') {
            if (!isGlobalDataUser($authUser)) {
                throw new RuntimeException('Only Super Admin and Admin can reassign client PMS ownership.', 403);
            }
            $ownerPmsAdminId = isset($payload['owner_pms_admin_id']) ? (int) $payload['owner_pms_admin_id'] : null;
            $ownerPmsAdminId = resolveAssignableOwnerPmsAdminId($conn, $authUser, $ownerPmsAdminId);

            dbExecute(
                $conn,
                'UPDATE clients_table SET owner_pms_admin_id = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                'isii',
                [$ownerPmsAdminId, $actorEmail, $actorId, $id]
            )->close();
            dbExecute(
                $conn,
                'UPDATE keypersons_table SET owner_pms_admin_id = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE clients_id = ?',
                'isii',
                [$ownerPmsAdminId, $actorEmail, $actorId, $id]
            )->close();
            dbExecute(
                $conn,
                'UPDATE log_table SET owner_pms_admin_id = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE clients_id = ?',
                'isii',
                [$ownerPmsAdminId, $actorEmail, $actorId, $id]
            )->close();
        }

        $updated++;
        writeAuditLog($conn, $authUser, 'client.bulk_' . $action, 'client', $id, [
            'clients_name' => $client['clients_name'] ?? null,
            'action' => $action,
        ]);
    }

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

jsonResponse([
    'status' => 'Success',
    'message' => $updated . ' client record(s) updated successfully.',
    'data' => [
        'updated' => $updated,
    ],
]);
