<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/audit.php';

use Respect\Validation\Validator as v;

requireMethod('POST');

enforceTrustedOrigin();

$data = readJsonBody();
$token = cleanString($data['token'] ?? '');
$firstName = cleanString($data['first_name'] ?? '');
$lastName = cleanString($data['last_name'] ?? '');
$password = (string) ($data['password'] ?? '');
$confirmPassword = (string) ($data['confirm_password'] ?? '');

if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
    throw new RuntimeException('Invalid invitation token.', 422);
}

if ($firstName === '' || $lastName === '') {
    throw new RuntimeException('Please provide your first name and last name.', 422);
}

if (strlen($password) < 10) {
    throw new RuntimeException('Password must be at least 10 characters.', 422);
}

if ($password !== $confirmPassword) {
    throw new RuntimeException('Passwords do not match.', 422);
}

$tokenHash = invitationTokenHash($token);

$stmt = $conn->prepare(
    'SELECT id, email, role, is_pms_admin, parent_pms_admin_id, invited_by, status, expires_at
     FROM user_invitations
     WHERE token_hash = ?
     LIMIT 1'
);
$stmt->bind_param('s', $tokenHash);
$stmt->execute();
$invitation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invitation || (string) $invitation['status'] !== 'pending') {
    throw new RuntimeException('This invitation is not available.', 404);
}

if (strtotime((string) $invitation['expires_at']) < time()) {
    $expireStmt = $conn->prepare('UPDATE user_invitations SET status = "expired" WHERE id = ?');
    $invitationId = (int) $invitation['id'];
    $expireStmt->bind_param('i', $invitationId);
    $expireStmt->execute();
    $expireStmt->close();
    throw new RuntimeException('This invitation has expired.', 410);
}

$email = strtolower((string) $invitation['email']);
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$role = (string) $invitation['role'];
$isPmsAdmin = (int) ($invitation['is_pms_admin'] ?? 0);
$parentPmsAdminId = $invitation['parent_pms_admin_id'] !== null ? (int) $invitation['parent_pms_admin_id'] : null;
$invitedBy = (int) $invitation['invited_by'];

$conn->begin_transaction();
try {
    $userStmt = $conn->prepare('SELECT id, status FROM users WHERE email = ? LIMIT 1');
    $userStmt->bind_param('s', $email);
    $userStmt->execute();
    $user = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    if ($user) {
        $userId = (int) $user['id'];
        $updateStmt = $conn->prepare(
            'UPDATE users
             SET first_name = ?, last_name = ?, password_hash = ?, must_change_password = 0,
                 password_changed_at = CURRENT_TIMESTAMP, temporary_password_set_at = NULL,
                 role = ?, is_pms_admin = ?, status = "active", parent_pms_admin_id = ?, invited_by = ?,
                 updated_by = ?, email_verified_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $updateStmt->bind_param('ssssiiiii', $firstName, $lastName, $passwordHash, $role, $isPmsAdmin, $parentPmsAdminId, $invitedBy, $invitedBy, $userId);
        $updateStmt->execute();
        $updateStmt->close();
    } else {
        $insertStmt = $conn->prepare(
            'INSERT INTO users (first_name, last_name, email, password_hash, must_change_password, password_changed_at, role, is_pms_admin, status, parent_pms_admin_id, invited_by, created_by, email_verified_at)
             VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP, ?, ?, "active", ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $insertStmt->bind_param('sssssiiii', $firstName, $lastName, $email, $passwordHash, $role, $isPmsAdmin, $parentPmsAdminId, $invitedBy, $invitedBy);
        $insertStmt->execute();
        $userId = $insertStmt->insert_id;
        $insertStmt->close();
    }

    $invitationId = (int) $invitation['id'];
    $acceptStmt = $conn->prepare('UPDATE user_invitations SET status = "accepted", accepted_at = CURRENT_TIMESTAMP WHERE id = ?');
    $acceptStmt->bind_param('i', $invitationId);
    $acceptStmt->execute();
    $acceptStmt->close();

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

writeAuditLog($conn, ['id' => $userId, 'role' => $role], 'users.accept_invitation', 'user', $userId, [
    'email' => $email,
    'role' => $role,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Invitation accepted successfully. You can now log in.'
]);
