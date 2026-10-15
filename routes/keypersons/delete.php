<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Keyperson ID');
$keyperson = assertKeypersonAccessible($conn, $authUser, $id, true);

$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

// PMS Admins / PMS Users manage their own contact list: "deactivate" removes the person from that list only.
// The person stays available to every other PMS Admin who has them. Global administrators deactivate the record itself.
if (isPmsWorkspaceUser($authUser)) {
    $pmsAdminId = (int) (resolveOwnerPmsAdminId($authUser) ?? 0);
    if ($pmsAdminId <= 0) {
        throw new RuntimeException('Your account is not linked to a PMS Admin contact list.', 403);
    }
    $stmt = dbExecute($conn, 'DELETE FROM keyperson_pms_assignments WHERE keyperson_id = ? AND pms_admin_id = ?', 'ii', [$id, $pmsAdminId]);
    $removed = $stmt->affected_rows > 0;
    $stmt->close();

    writeAuditLog($conn, $authUser, 'keyperson.unlinked', 'keyperson', $id, [
        'key_person' => $keyperson['key_person'] ?? null,
        'client_id' => $keyperson['clients_id'] ?? null,
        'pms_admin_id' => $pmsAdminId,
        'removed' => $removed,
    ]);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Key person removed from your contact list. Other PMS Admins who work with this person keep them.',
        'data' => ['unlinked' => true],
    ]);
}

[$recordScopeSql, $recordTypes, $recordParams] = appendKeypersonScopedWhere(
    $authUser,
    'keypersons_table',
    'sii',
    [$actorEmail, $actorId, $id]
);
dbExecute(
    $conn,
    "UPDATE keypersons_table
     SET status = 'deactivated', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ?{$recordScopeSql}",
    $recordTypes,
    $recordParams
)->close();

writeAuditLog($conn, $authUser, 'keyperson.deactivated', 'keyperson', $id, [
    'key_person' => $keyperson['key_person'] ?? null,
    'client_id' => $keyperson['clients_id'] ?? null,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Keyperson deactivated successfully.',
    'data' => ['unlinked' => false],
]);
