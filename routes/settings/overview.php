<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/settings.php';
require_once __DIR__ . '/../../includes/notifications.php';

requireMethod('GET');

$actor = authenticateUser();
requireRole($actor, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);

$settings = loadAppSettings($conn);
$lastUpdate = appSettingsLastUpdate($conn);
$uploadsPath = dirname(__DIR__, 2) . '/uploads';
$mailEnabled = envBool('MAIL_ENABLED', false);
$smtpConfigured = trim(envString('SMTP_HOST')) !== ''
    && trim(envString('SMTP_FROM_EMAIL')) !== '';
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
        'status' => ($mailEnabled && $smtpConfigured) ? 'ready' : 'attention',
        'detail' => ($mailEnabled && $smtpConfigured)
            ? 'SMTP delivery is enabled and configured.'
            : 'SMTP delivery requires environment configuration.',
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
