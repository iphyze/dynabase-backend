<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
validateCsrfToken();
header('Cache-Control: no-store');
$data = readJsonBody();
$token = trim((string)($data['token'] ?? ''));
$password = (string)($data['new_password'] ?? '');
$confirmation = (string)($data['confirm_password'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new RuntimeException('This reset link is invalid or has expired. Request another link.', 422);
if (strlen($password) < 10 || strlen($password) > 256) throw new RuntimeException('Choose a password between 10 and 256 characters.', 422);
if ($password !== $confirmation) throw new RuntimeException('New password and confirmation do not match.', 422);
if ($password === 'Lambert@2026') throw new RuntimeException('Please choose a personal password.', 422);
$hash = hash('sha256', $token);
$conn->begin_transaction();
try {
    $row = dbFetchOne($conn,
        "SELECT r.id, r.user_id FROM auth_password_reset_requests r JOIN users u ON u.id=r.user_id WHERE r.token_hash=? AND r.consumed_at IS NULL AND r.expires_at > NOW() AND u.status='active' AND u.pms_portal_only=0 LIMIT 1 FOR UPDATE",
        's', [$hash]);
    if (!$row) throw new RuntimeException('This reset link is invalid or has expired. Request another link.', 422);
    $userId = (int)$row['user_id'];
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    dbExecute($conn, 'UPDATE users SET password_hash=?, must_change_password=0, temporary_password_set_at=NULL, password_changed_at=NOW() WHERE id=?', 'si', [$newHash, $userId])->close();
    revokeAllUserSessions($conn, $userId);
    dbExecute($conn, 'UPDATE auth_password_reset_requests SET consumed_at=NOW() WHERE user_id=? AND consumed_at IS NULL', 'i', [$userId])->close();
    $conn->commit();
} catch (Throwable $e) { $conn->rollback(); throw $e; }
writeAuditLog($conn, null, 'auth.password_reset', 'user', $userId);
jsonResponse(['status' => 'Success', 'message' => 'Your password has been updated. Please sign in with your new password.']);
