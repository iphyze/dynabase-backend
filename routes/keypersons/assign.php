<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/keypersons.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$token = cleanString($payload['assignment_token'] ?? '');
if ($token === '') {
    throw new RuntimeException('The contact assignment request is missing.', 422);
}

$assignment = verifyKeypersonAssignmentToken($authUser, $token);
$keypersonId = (int) $assignment['keyperson_id'];
$pmsAdminId = (int) $assignment['pms_admin_id'];
$fixedOwnerId = resolveOwnerPmsAdminId($authUser);
if ($fixedOwnerId !== null && $fixedOwnerId !== $pmsAdminId) {
    throw new RuntimeException('This contact assignment is not available to your PMS scope.', 403);
}

$keyperson = dbFetchOne($conn, 'SELECT * FROM keypersons_table WHERE id = ? LIMIT 1', 'i', [$keypersonId]);
if (!$keyperson) {
    throw new RuntimeException('The matching Key Person is no longer available.', 404);
}

$pmsAdmin = dbFetchOne(
    $conn,
    "SELECT id FROM users WHERE id = ? AND status = 'active' AND (role = 'pms_admin' OR is_pms_admin = 1) LIMIT 1",
    'i',
    [$pmsAdminId]
);
if (!$pmsAdmin) {
    throw new RuntimeException('The PMS contact list is no longer available.', 422);
}

$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];
$conn->begin_transaction();
try {
    $created = ensureKeypersonPmsAssignment($conn, $keypersonId, $pmsAdminId, $actorId);

    if ((string) ($keyperson['status'] ?? '') === 'deactivated') {
        if (!userHasPermission($conn, $authUser, 'keypersons.edit')) {
            throw new RuntimeException('This Key Person exists but is deactivated. Ask an authorised user to reactivate the record.', 409);
        }
        dbExecute(
            $conn,
            "UPDATE keypersons_table SET status = 'active', updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            'sii',
            [$actorEmail, $actorId, $keypersonId]
        )->close();
    }

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

writeAuditLog($conn, $authUser, 'keyperson.assigned', 'keyperson', $keypersonId, [
    'assignment_created' => $created,
]);

$keyperson = assertKeypersonAccessible($conn, $authUser, $keypersonId, true);
if (isPmsWorkspaceUser($authUser)) {
    unset($keyperson['owner_pms_admin_id']);
}

jsonResponse([
    'status' => 'Success',
    'message' => $created ? 'Key person added to your contact list.' : 'Key person is already in your contact list.',
    'data' => $keyperson,
]);
