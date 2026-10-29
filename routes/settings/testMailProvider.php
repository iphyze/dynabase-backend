<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$actor = authenticateUser();
requireRole($actor, [DYNABASE_ROLE_SUPER_ADMIN]);

$payload = readJsonBody();
$provider = strtolower(trim((string) ($payload['provider'] ?? '')));
if (!in_array($provider, ['graph', 'smtp'], true)) {
    throw new RuntimeException('Choose Microsoft Graph or SMTP to test.', 422);
}

if (!envBool('MAIL_ENABLED', false) || strtolower(envString('MAIL_DRIVER')) === 'log') {
    throw new RuntimeException('Real mail delivery is disabled in the server configuration.', 422);
}

$readiness = dynabaseMailProviderReadiness();
if (empty($readiness[$provider])) {
    throw new RuntimeException('The selected mail provider is not configured.', 422);
}

// Never accept an arbitrary address from the request: test only to the
// authenticated Super Admin's registered mailbox.
$recipient = trim((string) ($actor['email'] ?? ''));
if (!dynabaseIsDeliverableEmail($recipient)) {
    throw new RuntimeException('Your administrator account requires a valid email address.', 422);
}

// Protect the diagnostics endpoint from accidental repeat deliveries.
// The audit table already exists in Dynabase; no new migration is needed.
$actorId = (int) $actor['id'];
$rate = $conn->prepare(
    "SELECT COUNT(*) AS total FROM audit_logs
     WHERE actor_user_id = ? AND action = 'settings.mail_provider_test'
       AND created_at >= (NOW() - INTERVAL 5 MINUTE)"
);
$rate->bind_param('i', $actorId);
$rate->execute();
$recent = (int) ($rate->get_result()->fetch_assoc()['total'] ?? 0);
$rate->close();
if ($recent >= 5) {
    throw new RuntimeException('Too many test emails. Please try again in five minutes.', 429);
}

// Log the attempt before making any external call, so failures are also audited.
writeAuditLog($conn, $actor, 'settings.mail_provider_test', 'mail_provider', $provider, [
    'provider' => $provider,
    'result' => 'started',
]);

$label = $provider === 'graph' ? 'Microsoft Graph' : 'SMTP';
$timestamp = gmdate('Y-m-d H:i:s') . ' UTC';
$emailOptions = [
    'to' => [['email' => $recipient]],
    'cc' => [],
    'bcc' => [],
    'subject' => '[Dynabase] ' . $label . ' delivery test',
    'html' => '<div style="font:14px/1.6 Arial,sans-serif;max-width:560px;margin:auto">'
        . '<h2 style="color:#14674f">Dynabase email test</h2>'
        . '<p>This is a delivery test requested from Email Provider Settings.</p>'
        . '<p><strong>Provider:</strong> ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '<br><strong>Time:</strong> ' . htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>No action is required.</p></div>',
    'text' => "Dynabase delivery test\nProvider: {$label}\nTime: {$timestamp}\nNo action is required.",
];

// Test each service independently; intentional failover is excluded from this
// diagnostic so the administrator can tell which service actually responded.
$result = $provider === 'graph'
    ? dynabaseGraphSendDetailed($emailOptions)
    : dynabaseSmtpSendDetailed($emailOptions);

writeAuditLog($conn, $actor, 'settings.mail_provider_test_result', 'mail_provider', $provider, [
    'provider' => $provider,
    'result' => $result['status'],
]);

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'message' => match ($result['status']) {
        'accepted' => "{$label} accepted the test email. Please check your inbox.",
        'uncertain' => "{$label} response was uncertain. Check provider logs before retrying.",
        default => "{$label} could not submit the test email. Check the server mail logs.",
    },
    'data' => [
        'provider' => $provider,
        'result' => $result['status'],
        'recipient' => $recipient,
    ],
]);
