<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$actor = authenticateUser(); // Requires normal Dynabase login, not a portal session.
validateCsrfToken();
$data = readJsonBody();
$email = cleanEmail($data['email'] ?? '');
$firstName = cleanString($data['first_name'] ?? '');
$lastName = cleanString($data['last_name'] ?? '');
$role = cleanString($data['role'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190
    || strlen($firstName) < 2 || strlen($lastName) < 2
    || strlen($firstName) > 100 || strlen($lastName) > 100
    || !in_array($role, ['pms_admin', 'pms_user'], true)) {
    throw new RuntimeException('Provide valid first name, last name, email and PMS role.', 422);
}

$actorRole = userRole($actor);
if ($actorRole !== DYNABASE_ROLE_SUPER_ADMIN
    && !($actorRole === DYNABASE_ROLE_PMS_ADMIN && $role === DYNABASE_ROLE_PMS_USER)) {
    throw new RuntimeException('You cannot authorize this PMS portal user.', 403);
}

if (pmsPortalUserByEmail($conn, $email)) {
    throw new RuntimeException('This email already belongs to a Dynabase user. Eligible PMS accounts can already use the portal.', 409);
}

$parentId = null;
if ($role === DYNABASE_ROLE_PMS_USER) {
    $parentId = $actorRole === DYNABASE_ROLE_PMS_ADMIN
        ? (int) $actor['id']
        : (int) ($data['parent_pms_admin_id'] ?? 0);

    $parent = $parentId > 0 ? dbFetchOne($conn,
        "SELECT id FROM users WHERE id = ? AND status = 'active'
         AND (role = 'pms_admin' OR (role = 'admin' AND is_pms_admin = 1)) LIMIT 1",
        'i', [$parentId]) : null;
    if (!$parent) {
        throw new RuntimeException('Choose an active PMS Admin for this PMS User.', 422);
    }
}

$actorId = (int) $actor['id'];
$pmsFlag = $role === DYNABASE_ROLE_PMS_ADMIN ? 1 : 0;
$conn->begin_transaction();
try {
    $stmt = dbExecute($conn,
        "INSERT INTO users
         (first_name, last_name, email, password_hash, role, is_pms_admin, pms_portal_only,
          status, parent_pms_admin_id, invited_by, created_by, updated_by)
         VALUES (?, ?, ?, NULL, ?, ?, 1, 'active', ?, ?, ?, ?)",
        'ssssiiiii',
        [$firstName, $lastName, $email, $role, $pmsFlag, $parentId, $actorId, $actorId, $actorId]);
    $newUserId = (int) $stmt->insert_id;
    $stmt->close();

    writeAuditLog($conn, $actor, 'pms_portal.authorize', 'user', $newUserId, [
        'role' => $role,
        'parent_pms_admin_id' => $parentId,
    ]);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

jsonResponse([
    'status' => 'Success',
    'message' => 'PMS portal access authorized. The user can request an email code.',
    'data' => ['id' => $newUserId, 'email' => $email, 'role' => $role, 'parent_pms_admin_id' => $parentId],
], 201);
