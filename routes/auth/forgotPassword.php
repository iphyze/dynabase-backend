<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireMethod('POST');
validateCsrfToken();
header('Cache-Control: no-store');
$payload = readJsonBody();
$email = cleanEmail($payload['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    throw new RuntimeException('Enter a valid email address.', 422);
}
// Configuration failures must not expose whether the address exists.
if (!envBool('MAIL_ENABLED', false)) {
    throw new RuntimeException('Password recovery email is currently unavailable. Please contact an administrator.', 503);
}
$generic = 'If an active Dynabase account uses this email, a password reset link will be sent.';
$emailHash = hash('sha256', $email);
$ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// Throttle both an individual address and the requesting IP. Unknown addresses
// also count, so response behavior cannot be used for account enumeration.
$emailCount = dbScalarInt($conn,
    'SELECT COUNT(*) AS total FROM auth_password_reset_requests WHERE email_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)',
    's', [$emailHash]);
$ipCount = dbScalarInt($conn,
    'SELECT COUNT(*) AS total FROM auth_password_reset_requests WHERE ip_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)',
    's', [$ipHash]);
if ($emailCount >= 3 || $ipCount >= 10) {
    jsonResponse(['status' => 'Success', 'message' => $generic]);
}
$user = dbFetchOne($conn,
    "SELECT id, first_name, last_name, email FROM users WHERE email = ? AND status = 'active' AND pms_portal_only = 0 LIMIT 1",
    's', [$email]);
$token = $user ? bin2hex(random_bytes(32)) : null;
$tokenHash = $token !== null ? hash('sha256', $token) : null;
$userId = $user ? (int)$user['id'] : null;
$stmt = dbExecute($conn,
    'INSERT INTO auth_password_reset_requests (user_id,email_hash,ip_hash,token_hash,expires_at) VALUES (?,?,?,?,IF(? IS NULL, NULL, DATE_ADD(NOW(), INTERVAL 20 MINUTE)))',
    'issss', [$userId, $emailHash, $ipHash, $tokenHash, $tokenHash]);
$requestId = (int)$stmt->insert_id;
$stmt->close();
if ($user && $token !== null) {
    $base = rtrim(envString('FRONTEND_URL', 'http://localhost:5173'), '/');
    $url = $base . '/reset-password?token=' . rawurlencode($token);
    $name = trim((string)$user['first_name']) ?: 'there';
    $escapedName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $html = dynabaseEmailLayout([
        'eyebrow' => 'ACCOUNT SECURITY',
        'title' => 'Reset your password',
        'preheader' => 'A one-time Dynabase password recovery link.',
        'badge' => 'PASSWORD RECOVERY',
        'intro_html' => '<p style="margin:0">Hello <strong>' . $escapedName . '</strong>,</p><p style="margin:14px 0 0">We received a request to reset your Dynabase password.</p>',
        'body_html' => '<p style="margin:20px 0"><a href="' . $escapedUrl . '" style="display:inline-block;padding:13px 20px;border-radius:10px;background:#2A5BC2;color:#ffffff;text-decoration:none;font-weight:700">Reset password</a></p><p style="margin:0;color:#526c82;font-size:13px">This single-use link expires in 20 minutes. You can ignore this email if you did not request the change.</p>',
        'footer_note' => 'Dynabase will never ask you to share this link or your password.',
    ]);
    $sent = false;
    try {
        $sent = sendDynabaseMail([
            'to' => [['email'=>$email, 'name'=>trim((string)$user['first_name'].' '.(string)$user['last_name'])]],
            'subject' => 'Reset your Dynabase password',
            'html' => $html,
            'text' => "Hello {$name},\n\nReset your Dynabase password using this one-time link:\n{$url}\n\nThis link expires in 20 minutes. If you did not request it, ignore this email.",
        ]) === true;
    } catch (Throwable $e) { error_log('[Dynabase password recovery mail] ' . $e->getMessage()); }
    if (!$sent) {
        // Never leave usable reset tokens for messages that were not delivered.
        dbExecute($conn, 'UPDATE auth_password_reset_requests SET token_hash=NULL, expires_at=NULL, consumed_at=NOW() WHERE id=?', 'i', [$requestId])->close();
        error_log('[Dynabase password recovery] Could not dispatch reset link for request '.$requestId);
    }
}
jsonResponse(['status' => 'Success', 'message' => $generic]);
