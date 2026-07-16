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
    throw new RuntimeException('Please select a valid user.', 422);
}

$stmt = $conn->prepare(
    'SELECT id, first_name, last_name, email, role, status, parent_pms_admin_id, invited_by, created_by, updated_by, last_login_at, created_at
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
if (!canResetUserPassword($actor, $target)) {
    throw new RuntimeException('User not found.', 404);
}

$temporaryPassword = 'Dynabase@' . random_int(100000, 999999) . substr(bin2hex(random_bytes(2)), 0, 4);
$passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
$actorId = (int) $actor['id'];

$updateStmt = $conn->prepare(
    'UPDATE users
     SET password_hash = ?, must_change_password = 1, temporary_password_set_at = CURRENT_TIMESTAMP,
         password_changed_at = NULL, updated_by = ?
     WHERE id = ?'
);
$updateStmt->bind_param('sii', $passwordHash, $actorId, $userId);
$updateStmt->execute();
$updateStmt->close();

revokeAllUserSessions($conn, $userId);
$mailResult = sendTemporaryPasswordEmail((string) $target['email'], $temporaryPassword);

writeAuditLog($conn, $actor, 'users.reset_password', 'user', $userId, [
    'target_email' => $target['email'],
    'mail_result' => $mailResult === true ? 'sent' : 'not_sent',
]);

jsonResponse([
    'status' => 'Success',
    'message' => $mailResult === true
        ? 'Password reset successfully. The temporary password has been emailed to the user.'
        : 'Password reset successfully. Mail was not sent automatically, so copy the temporary password below.',
    'data' => [
        'email' => $target['email'],
        'temporary_password' => $mailResult === true ? null : $temporaryPassword,
        'mail_note' => $mailResult === true ? null : $mailResult,
    ],
]);
