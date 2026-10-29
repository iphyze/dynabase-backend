<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/settings.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/mailProviderSettings.php';

requireMethod('POST');
$actor = authenticateUser();
requireRole($actor, [DYNABASE_ROLE_SUPER_ADMIN]);
$data = readJsonBody();
$primary = strtolower(trim((string) ($data['primary'] ?? '')));
$enabled = $data['failover_enabled'] ?? false;

if (!in_array($primary, ['smtp', 'graph'], true)) {
    throw new RuntimeException('Choose SMTP or Microsoft Graph as the primary email provider.', 422);
}
if (!is_bool($enabled)) {
    throw new RuntimeException('Automatic failover must be enabled or disabled.', 422);
}

$actorId = (int) $actor['id'];
$conn->begin_transaction();
try {
    saveAppSettings($conn, [
        'mail_primary_provider' => $primary,
        'mail_failover_enabled' => $enabled,
    ], $actorId);
    writeAuditLog($conn, $actor, 'settings.mail_provider_updated', 'workspace_settings', 'mail_provider', [
        'primary' => $primary,
        'backup' => $primary === 'graph' ? 'smtp' : 'graph',
        'failover_enabled' => $enabled,
    ]);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

header('Cache-Control: no-store');
jsonResponse([
    'status' => 'Success',
    'message' => 'Email provider preference saved.',
    'data' => [
        'primary' => $primary,
        'backup' => $primary === 'graph' ? 'smtp' : 'graph',
        'failover_enabled' => $enabled,
        'configured' => dynabaseMailProviderReadiness(),
    ],
]);
