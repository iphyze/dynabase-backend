<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireMethod('POST');

$actor = authenticateUser();
$data = readJsonBody();
$userId = (int) ($data['user_id'] ?? 0);

if ($userId <= 0) {
    throw new RuntimeException('Please choose a valid pending user.', 422);
}

$actorId = (int) $actor['id'];
$plainToken = bin2hex(random_bytes(32));
$tokenHash = invitationTokenHash($plainToken);
$expiresInDays = max(1, (int) envString('INVITATION_EXPIRES_DAYS', '7'));
$expiresAt = date('Y-m-d H:i:s', time() + ($expiresInDays * 86400));

$conn->begin_transaction();
try {
    $userStmt = $conn->prepare(
        'SELECT id, first_name, last_name, email, role, is_pms_admin, status,
                parent_pms_admin_id, invited_by, created_by, updated_by, invited_at
         FROM users
         WHERE id = ?
         LIMIT 1
         FOR UPDATE'
    );
    $userStmt->bind_param('i', $userId);
    $userStmt->execute();
    $target = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    if (!$target) {
        throw new RuntimeException('The selected user was not found.', 404);
    }

    $target = normalizeUserRow($target);

    if ((string) ($target['status'] ?? '') !== 'pending') {
        throw new RuntimeException('An invitation can only be resent to a user whose account is still pending.', 409);
    }

    if (!canResendUserInvitation($actor, $target)) {
        throw new RuntimeException('You are not allowed to resend this user invitation.', 403);
    }

    $email = cleanEmail($target['email'] ?? '');
    if ($email === '') {
        throw new RuntimeException('The pending user does not have a valid email address.', 409);
    }

    $role = (string) $target['role'];
    $isPmsAdmin = (int) ($target['is_pms_admin'] ?? 0) === 1;
    $parentPmsAdminId = !empty($target['parent_pms_admin_id'])
        ? (int) $target['parent_pms_admin_id']
        : null;

    $cancelStmt = $conn->prepare(
        'UPDATE user_invitations
         SET status = "cancelled"
         WHERE email = ? AND status = "pending"'
    );
    $cancelStmt->bind_param('s', $email);
    $cancelStmt->execute();
    $cancelledInvitationCount = $cancelStmt->affected_rows;
    $cancelStmt->close();

    $inviteStmt = $conn->prepare(
        'INSERT INTO user_invitations
            (email, role, is_pms_admin, token_hash, parent_pms_admin_id, invited_by, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $pmsFlag = $isPmsAdmin ? 1 : 0;
    $inviteStmt->bind_param(
        'ssisiis',
        $email,
        $role,
        $pmsFlag,
        $tokenHash,
        $parentPmsAdminId,
        $actorId,
        $expiresAt
    );
    $inviteStmt->execute();
    $invitationId = (int) $inviteStmt->insert_id;
    $inviteStmt->close();

    $updateUserStmt = $conn->prepare(
        'UPDATE users
         SET invited_by = ?, updated_by = ?, invited_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );
    $updateUserStmt->bind_param('iii', $actorId, $actorId, $userId);
    $updateUserStmt->execute();
    $updateUserStmt->close();

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

$frontendUrl = rtrim(envString('FRONTEND_URL', 'http://localhost:5173'), '/');
$inviteUrl = $frontendUrl . '/accept-invitation?token=' . urlencode($plainToken);
$mailResult = sendInvitationEmail($email, $inviteUrl, $role);

writeAuditLog($conn, $actor, 'users.invitation_resent', 'user_invitation', $invitationId, [
    'user_id' => $userId,
    'email' => $email,
    'role' => $role,
    'parent_pms_admin_id' => $parentPmsAdminId,
    'is_pms_admin' => $isPmsAdmin,
    'expires_at' => $expiresAt,
    'cancelled_pending_invitations' => $cancelledInvitationCount,
    'mail_result' => $mailResult === true ? 'sent' : 'not_sent',
]);

jsonResponse([
    'status' => 'Success',
    'message' => $mailResult === true
        ? 'A fresh invitation link has been sent successfully.'
        : 'A fresh invitation link was created, but the email could not be sent automatically.',
    'data' => [
        'user_id' => $userId,
        'email' => $email,
        'invite_url' => $inviteUrl,
        'expires_at' => $expiresAt,
        'mail_sent' => $mailResult === true,
        'mail_note' => $mailResult === true ? null : $mailResult,
    ],
]);
