<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/mailer.php';

use Respect\Validation\Validator as v;

requireMethod('POST');

$actor = authenticateUser();
$data = readJsonBody();

$email = cleanEmail($data['email'] ?? '');
$role = cleanString($data['role'] ?? '');
$isPmsAdmin = ($data['is_pms_admin'] ?? false) === true || (int) ($data['is_pms_admin'] ?? 0) === 1;
if ($role === DYNABASE_ROLE_PMS_ADMIN) {
    $isPmsAdmin = true;
} elseif ($role !== DYNABASE_ROLE_ADMIN) {
    $isPmsAdmin = false;
}

$parentPmsAdminId = isset($data['parent_pms_admin_id']) && $data['parent_pms_admin_id'] !== ''
    ? (int) $data['parent_pms_admin_id']
    : null;

if (!v::email()->validate($email)) {
    throw new RuntimeException('Please provide a valid email address.', 422);
}

if (!in_array($role, DYNABASE_ROLES, true)) {
    throw new RuntimeException('Please choose a valid role.', 422);
}

if (!canInviteRole($actor, $role)) {
    throw new RuntimeException('You are not allowed to invite this role.', 403);
}

$resolvedParentPmsAdminId = resolveParentPmsAdminIdForInvite($conn, $actor, $role, $parentPmsAdminId);

$existingStmt = $conn->prepare('SELECT id, status, role, is_pms_admin FROM users WHERE email = ? LIMIT 1');
$existingStmt->bind_param('s', $email);
$existingStmt->execute();
$existingUser = $existingStmt->get_result()->fetch_assoc();
$existingStmt->close();

if ($existingUser && in_array((string) $existingUser['status'], ['active', 'inactive'], true)) {
    throw new RuntimeException('A user with this email already exists.', 409);
}

$conn->begin_transaction();
try {
    if ($existingUser) {
        $userId = (int) $existingUser['id'];
        $stmt = $conn->prepare(
            'UPDATE users
             SET role = ?, is_pms_admin = ?, status = "pending", parent_pms_admin_id = ?, invited_by = ?, updated_by = ?, invited_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $actorId = (int) $actor['id'];
        $pmsFlag = $isPmsAdmin ? 1 : 0;
        $stmt->bind_param('siiiii', $role, $pmsFlag, $resolvedParentPmsAdminId, $actorId, $actorId, $userId);
        $stmt->execute();
        $stmt->close();
    } else {
        $emptyFirstName = 'Pending';
        $emptyLastName = 'User';
        $actorId = (int) $actor['id'];
        $stmt = $conn->prepare(
            'INSERT INTO users (first_name, last_name, email, role, is_pms_admin, status, parent_pms_admin_id, invited_by, created_by, invited_at)
             VALUES (?, ?, ?, ?, ?, "pending", ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $pmsFlag = $isPmsAdmin ? 1 : 0;
        $stmt->bind_param('ssssiiii', $emptyFirstName, $emptyLastName, $email, $role, $pmsFlag, $resolvedParentPmsAdminId, $actorId, $actorId);
        $stmt->execute();
        $userId = $stmt->insert_id;
        $stmt->close();
    }

    $cancelStmt = $conn->prepare('UPDATE user_invitations SET status = "cancelled" WHERE email = ? AND status = "pending"');
    $cancelStmt->bind_param('s', $email);
    $cancelStmt->execute();
    $cancelStmt->close();

    $plainToken = bin2hex(random_bytes(32));
    $tokenHash = invitationTokenHash($plainToken);
    $expiresAt = date('Y-m-d H:i:s', time() + (max(1, (int) envString('INVITATION_EXPIRES_DAYS', '7')) * 86400));

    $inviteStmt = $conn->prepare(
        'INSERT INTO user_invitations (email, role, is_pms_admin, token_hash, parent_pms_admin_id, invited_by, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $pmsFlag = $isPmsAdmin ? 1 : 0;
    $inviteStmt->bind_param('ssisiis', $email, $role, $pmsFlag, $tokenHash, $resolvedParentPmsAdminId, $actorId, $expiresAt);
    $inviteStmt->execute();
    $invitationId = $inviteStmt->insert_id;
    $inviteStmt->close();

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

$frontendUrl = rtrim(envString('FRONTEND_URL', 'http://localhost:5173'), '/');
$inviteUrl = $frontendUrl . '/accept-invitation?token=' . urlencode($plainToken);
$mailResult = sendInvitationEmail($email, $inviteUrl, $role);

writeAuditLog($conn, $actor, 'users.invite', 'user_invitation', $invitationId, [
    'email' => $email,
    'role' => $role,
    'parent_pms_admin_id' => $resolvedParentPmsAdminId,
    'is_pms_admin' => $isPmsAdmin,
    'mail_result' => $mailResult === true ? 'sent' : 'not_sent',
]);

jsonResponse([
    'status' => 'Success',
    'message' => $mailResult === true
        ? 'Invitation sent successfully.'
        : 'Invitation created. Mail was not sent automatically, so use the invite link below.',
    'data' => [
        'invite_url' => $inviteUrl,
        'email' => $email,
        'role' => $role,
        'parent_pms_admin_id' => $resolvedParentPmsAdminId,
        'is_pms_admin' => $isPmsAdmin,
        'mail_note' => $mailResult === true ? null : $mailResult,
    ]
], 201);
