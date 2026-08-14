<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/agreements.php';

$result = syncAgreementLifecycleStatuses($conn);

echo json_encode([
    'healthy' => true,
    'checked' => $result['checked'],
    'updated' => $result['updated'],
    'expiring_soon_days' => agreementExpiringSoonDays($conn),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
