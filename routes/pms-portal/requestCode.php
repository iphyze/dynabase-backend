<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireMethod('POST');
validateCsrfToken();
$data = readJsonBody();
$email = cleanEmail($data['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    throw new RuntimeException('Enter a valid email address.', 422);
}

pmsPortalRateLimit($conn, 'code', 15);
$genericMessage = 'If this email is authorized, a verification code will be sent.';
$user = pmsPortalUserByEmail($conn, $email);
if (!$user || (string) ($user['status'] ?? '') !== 'active'
    || ((int) ($user['pms_portal_only'] ?? 0) === 1 && pmsPortalOwnerId($conn, $user) === null)) {
    jsonResponse(['status' => 'Success', 'message' => $genericMessage]);
}

$userId = (int) $user['id'];
$recent = dbFetchOne($conn,
    'SELECT sent_at FROM pms_portal_login_codes WHERE user_id = ? AND sent_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) LIMIT 1',
    'i', [$userId]);
if ($recent) {
    jsonResponse(['status' => 'Success', 'message' => $genericMessage]);
}

if (!in_array(dynabaseMailTransport(), ['smtp', 'graph'], true) || !envBool('MAIL_ENABLED', false)) {
    throw new RuntimeException('Email verification is temporarily unavailable.', 503);
}

$code = (string) random_int(100000, 999999);
$hash = pmsPortalCodeHash($userId, $code);
$previous = dbFetchOne($conn,
    'SELECT code_hash, attempts, expires_at, consumed_at, sent_at FROM pms_portal_login_codes WHERE user_id = ? LIMIT 1',
    'i', [$userId]);

// A code is valid only after successful mail submission; never return the OTP in an API response.
dbExecute($conn,
    'INSERT INTO pms_portal_login_codes (user_id, code_hash, expires_at, sent_at)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())
     ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), attempts = 0,
                             expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE), consumed_at = NULL, sent_at = NOW()',
    'is', [$userId, $hash])->close();

$name = trim((string) $user['first_name']);
$escapedName = htmlspecialchars($name ?: 'there', ENT_QUOTES, 'UTF-8');
$escapedCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
$brandedHtml = dynabaseEmailLayout([
    'eyebrow' => 'SECURE WORKSPACE SIGN IN',
    'title' => 'Your verification code',
    'preheader' => 'Use your six-digit code to securely sign in to Dynabase.',
    'badge' => 'SECURE LOGIN',
    'intro_html' => '<p style="margin:0">Hello <strong style="color:#173047">' . $escapedName . '</strong>,</p>'
        . '<p style="margin:14px 0 0">We received a request to sign in to your PMS Gift Portal. Use this code to continue:</p>',
    'body_html' => '<div style="margin:22px 0 0;padding:22px 18px;text-align:center;border:1px solid #d9e8f1;border-radius:14px;background:#f4f9fc">'
        . '<p style="margin:0 0 12px;color:#687f94;font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase">One-time verification code</p>'
        . '<p style="margin:0;color:#173f66;font-family:Consolas,Monaco,monospace;font-size:34px;font-weight:800;letter-spacing:.2em;line-height:1.3">' . $escapedCode . '</p>'
        . '</div><p style="margin:18px 0 0;color:#526c82;font-size:13px">This code expires in <strong>10 minutes</strong>. Never share it with anyone.</p>',
    'footer_note' => 'If you did not request this code, you can safely ignore this email. Dynabase will never ask you to share your verification code.',
]);
$mailResult = sendDynabaseMail([
    'to' => [['email' => $email, 'name' => trim($user['first_name'] . ' ' . $user['last_name'])]],
    'subject' => 'Your Dynabase sign-in verification code',
    'html' => $brandedHtml,
    'text' => "Hello {$name},\n\nYour Dynabase sign-in verification code is {$code}.\n\n"
        . "This code expires in 10 minutes. Never share it with anyone.\n\n"
        . 'If you did not request this code, you can safely ignore this email.',
]);

if ($mailResult !== true) {
    // Do not accidentally erase an earlier code following a failed resend.
    if ($previous) {
        dbExecute($conn,
            'UPDATE pms_portal_login_codes SET code_hash = ?, attempts = ?, expires_at = ?, consumed_at = ?, sent_at = ?
             WHERE user_id = ? AND code_hash = ?',
            'sisssis', [$previous['code_hash'], $previous['attempts'], $previous['expires_at'], $previous['consumed_at'], $previous['sent_at'], $userId, $hash])->close();
    } else {
        dbExecute($conn, 'DELETE FROM pms_portal_login_codes WHERE user_id = ? AND code_hash = ?', 'is', [$userId, $hash])->close();
    }
    throw new RuntimeException('Unable to send your code. Please try again later.', 503);
}

header('Cache-Control: no-store');
jsonResponse(['status' => 'Success', 'message' => $genericMessage]);
