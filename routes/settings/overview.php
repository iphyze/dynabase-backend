<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/settings.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/mailProviderSettings.php';

requireMethod('GET');

$actor = authenticateUser();

$settings = loadAppSettings($conn);
$lastUpdate = appSettingsLastUpdate($conn);
$uploadsPath = dirname(__DIR__, 2) . '/uploads';
$mailEnabled = envBool('MAIL_ENABLED', false);
$mailProviders = dynabaseMailProviderSettings($conn);
$mailReadiness = dynabaseMailProviderReadiness();
$smtpConfigured = $mailReadiness['smtp'];
$mailPrimary = $mailProviders['primary'];
$mailReady = $mailEnabled && ($mailReadiness[$mailPrimary] ?? false);
$turnstileConfigured = trim(envString('TURNSTILE_SECRET_KEY')) !== '';
$notificationsReady = notificationsTableExists($conn);
$auditReady = dbFetchOne($conn, "SHOW TABLES LIKE 'audit_logs'") !== null;
$uploadsWritable = is_dir($uploadsPath) && is_writable($uploadsPath);

$healthChecks = [
    ['key' => 'database', 'label' => 'Database', 'status' => 'ready', 'detail' => 'MySQL connection is active.'],
    [
        'key' => 'notifications',
        'label' => 'Notifications',
        'status' => $notificationsReady ? 'ready' : 'attention',
        'detail' => $notificationsReady ? 'Persistent in-app notifications are available.' : 'Notifications migration is not available.',
    ],
    [
        'key' => 'audit',
        'label' => 'Audit trail',
        'status' => $auditReady ? 'ready' : 'attention',
        'detail' => $auditReady ? 'Workspace activity is being recorded.' : 'Audit logging table is unavailable.',
    ],
    [
        'key' => 'mail',
        'label' => 'Email delivery',
        'status' => $mailReady ? 'ready' : 'attention',
        'detail' => $mailReady
            ? strtoupper($mailPrimary) . ' email delivery is configured.'
            : 'The selected email provider requires configuration or email is disabled.',
    ],
    [
        'key' => 'uploads',
        'label' => 'Document storage',
        'status' => $uploadsWritable ? 'ready' : 'attention',
        'detail' => $uploadsWritable ? 'The uploads directory is writable.' : 'The uploads directory is not writable.',
    ],
    [
        'key' => 'survey_protection',
        'label' => 'Survey protection',
        'status' => $turnstileConfigured ? 'ready' : 'optional',
        'detail' => $turnstileConfigured
            ? 'Cloudflare Turnstile is configured.'
            : 'Turnstile is optional and currently not configured.',
    ],
];

$readyCount = count(array_filter($healthChecks, static fn (array $item): bool => $item['status'] === 'ready'));

jsonResponse([
    'status' => 'Success',
    'data' => [
        'settings' => $settings,
        'meta' => [
            'migration_ready' => appSettingsTableExists($conn),
            'updated_at' => $lastUpdate['updated_at'] ?? null,
            'updated_by' => $lastUpdate['updated_by'] ?? null,
            'updated_by_name' => $lastUpdate['updated_by_name'] ?? null,
        ],
        'system' => [
            'app_environment' => envString('APP_ENV', 'production'),
            'php_version' => PHP_VERSION,
            'mail_enabled' => $mailEnabled,
            'smtp_configured' => $smtpConfigured,
            'graph_configured' => $mailReadiness['graph'],
            'mail_provider' => $mailPrimary,
            'mail_failover_enabled' => $mailProviders['failover_enabled'],
            'turnstile_configured' => $turnstileConfigured,
            'notifications_ready' => $notificationsReady,
            'audit_ready' => $auditReady,
            'uploads_writable' => $uploadsWritable,
            'health' => [
                'ready' => $readyCount,
                'total' => count($healthChecks),
                'checks' => $healthChecks,
            ],
        ],
        'options' => [
            'timezones' => [
                ['value' => 'Africa/Lagos', 'label' => 'West Africa Time · Lagos'],
                ['value' => 'UTC', 'label' => 'Coordinated Universal Time'],
                ['value' => 'Europe/London', 'label' => 'United Kingdom · London'],
                ['value' => 'Europe/Paris', 'label' => 'Central Europe · Paris'],
                ['value' => 'America/New_York', 'label' => 'Eastern Time · New York'],
            ],
            'date_formats' => [
                ['value' => 'd M Y', 'label' => '24 Jun 2026'],
                ['value' => 'd/m/Y', 'label' => '24/06/2026'],
                ['value' => 'm/d/Y', 'label' => '06/24/2026'],
                ['value' => 'Y-m-d', 'label' => '2026-06-24'],
            ],
            'page_sizes' => [10, 20, 25, 50],
            'reporting_periods' => [
                ['value' => 'all', 'label' => 'All available years'],
                ['value' => 'current_year', 'label' => 'Current year'],
            ],
            'week_starts' => [
                ['value' => 'monday', 'label' => 'Monday'],
                ['value' => 'sunday', 'label' => 'Sunday'],
            ],
        ],
    ],
]);
