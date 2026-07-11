<?php
declare(strict_types=1);

require_once __DIR__ . '/dbHelpers.php';

function appSettingsTableExists(mysqli $conn): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    try {
        $available = dbFetchOne($conn, "SHOW TABLES LIKE 'app_settings'") !== null;
    } catch (Throwable) {
        $available = false;
    }

    return $available;
}

function appSettingDefinitions(): array
{
    return [
        'workspace_name' => ['type' => 'string', 'default' => 'Dynabase'],
        'organisation_name' => ['type' => 'string', 'default' => 'Lambert Electromec Limited'],
        'support_email' => ['type' => 'string', 'default' => ''],
        'support_phone' => ['type' => 'string', 'default' => ''],
        'website_url' => ['type' => 'string', 'default' => ''],
        'office_address' => ['type' => 'string', 'default' => ''],
        'timezone' => ['type' => 'string', 'default' => 'Africa/Lagos'],
        'date_format' => ['type' => 'string', 'default' => 'd M Y'],
        'week_starts_on' => ['type' => 'string', 'default' => 'monday'],
        'default_reporting_period' => ['type' => 'string', 'default' => 'all'],
        'default_page_size' => ['type' => 'integer', 'default' => 10],
        'notifications_enabled' => ['type' => 'boolean', 'default' => true],
        'notification_relationship_enabled' => ['type' => 'boolean', 'default' => true],
        'notification_gift_list_enabled' => ['type' => 'boolean', 'default' => true],
        'notification_opportunity_enabled' => ['type' => 'boolean', 'default' => true],
        'notification_document_enabled' => ['type' => 'boolean', 'default' => true],
        'notification_survey_enabled' => ['type' => 'boolean', 'default' => true],
        'notification_access_enabled' => ['type' => 'boolean', 'default' => true],
    ];
}

function decodeAppSettingValue(mixed $value, string $type): mixed
{
    return match ($type) {
        'boolean' => in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true),
        'integer' => (int) $value,
        'json' => is_array($value) ? $value : (json_decode((string) $value, true) ?: []),
        default => (string) ($value ?? ''),
    };
}

function encodeAppSettingValue(mixed $value, string $type): string
{
    return match ($type) {
        'boolean' => $value ? '1' : '0',
        'integer' => (string) ((int) $value),
        'json' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        default => trim((string) $value),
    };
}

function loadAppSettings(mysqli $conn): array
{
    $definitions = appSettingDefinitions();
    $settings = [];

    foreach ($definitions as $key => $definition) {
        $settings[$key] = $definition['default'];
    }

    if (!appSettingsTableExists($conn)) {
        return $settings;
    }

    $rows = dbFetchAll($conn, 'SELECT setting_key, setting_value, setting_type FROM app_settings');
    foreach ($rows as $row) {
        $key = (string) ($row['setting_key'] ?? '');
        if ($key === '' || !isset($definitions[$key])) {
            continue;
        }

        $type = (string) ($row['setting_type'] ?? $definitions[$key]['type']);
        $settings[$key] = decodeAppSettingValue($row['setting_value'] ?? null, $type);
    }

    return $settings;
}

function appSettingValue(mysqli $conn, string $key, mixed $fallback = null): mixed
{
    $definitions = appSettingDefinitions();
    if (!isset($definitions[$key])) {
        return $fallback;
    }

    $settings = loadAppSettings($conn);
    return array_key_exists($key, $settings) ? $settings[$key] : $fallback;
}

function appSettingBool(mysqli $conn, string $key, bool $fallback = true): bool
{
    return (bool) appSettingValue($conn, $key, $fallback);
}

function saveAppSettings(mysqli $conn, array $values, int $actorId): void
{
    if (!appSettingsTableExists($conn)) {
        throw new RuntimeException('The settings database migration has not been applied yet.', 503);
    }

    $definitions = appSettingDefinitions();
    $stmt = $conn->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, setting_type, updated_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            setting_type = VALUES(setting_type),
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP'
    );

    foreach ($values as $key => $value) {
        if (!isset($definitions[$key])) {
            continue;
        }

        $type = (string) $definitions[$key]['type'];
        $encodedValue = encodeAppSettingValue($value, $type);
        $stmt->bind_param('sssi', $key, $encodedValue, $type, $actorId);
        $stmt->execute();
    }

    $stmt->close();
}

function appSettingsLastUpdate(mysqli $conn): ?array
{
    if (!appSettingsTableExists($conn)) {
        return null;
    }

    return dbFetchOne(
        $conn,
        "SELECT s.updated_at, s.updated_by,
                NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), '') AS updated_by_name
         FROM app_settings s
         LEFT JOIN users u ON u.id = s.updated_by
         ORDER BY s.updated_at DESC, s.id DESC
         LIMIT 1"
    );
}
