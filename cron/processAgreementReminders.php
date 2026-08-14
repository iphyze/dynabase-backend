<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/agreementReminders.php';

$result = processDueAgreementReminders($conn);

$healthy = (int) $result['failed'] === 0;
echo json_encode([
    'healthy' => $healthy,
    'checked' => $result['checked'],
    'completed' => $result['completed'],
    'failed' => $result['failed'],
    'emails_sent' => $result['sent'],
    'already_delivered' => $result['skipped'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($healthy ? 0 : 1);
