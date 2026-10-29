<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Read the Super Admin's mail transport preference from the existing app_settings
 * table. Mail configuration/secrets remain exclusively in the server environment.
 *
 * If the database is unavailable (for example, an early bootstrap or cron job),
 * preserve the original MAIL_DRIVER behaviour and do not enable automatic failover.
 */
function dynabaseMailProviderSettings(?mysqli $database = null): array
{
    static $cached = null;

    $driver = strtolower(trim(envString('MAIL_DRIVER', 'smtp')));
    $driver = in_array($driver, ['graph', 'ms_graph', 'microsoft_graph'], true)
        ? 'graph'
        : ($driver === 'log' ? 'log' : 'smtp');
    $defaults = [
        'primary' => $driver,
        'backup' => $driver === 'graph' ? 'smtp' : 'graph',
        'failover_enabled' => false,
        'source' => 'environment',
    ];

    // Log mode is an explicit testing override and must never send real mail.
    if ($driver === 'log') {
        return $defaults;
    }

    if ($cached !== null) {
        return $cached;
    }

    $db = $database;
    $ownsConnection = false;
    try {
        if (!$db instanceof mysqli && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            $db = $GLOBALS['conn'];
        }
        if (!$db instanceof mysqli) {
            $db = mysqli_init();
            if (!$db instanceof mysqli) {
                throw new RuntimeException('Database connection not available.');
            }
            $ownsConnection = true;
            $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
            $db->real_connect(
                envString('DB_HOST', 'localhost'),
                envString('DB_USER', 'root'),
                envString('DB_PASSWORD', ''),
                envString('DB_NAME', 'dynabase'),
                (int) envString('DB_PORT', '3306')
            );
            $db->set_charset('utf8mb4');
        }

        $query = $db->prepare(
            "SELECT setting_key, setting_value FROM app_settings
             WHERE setting_key IN ('mail_primary_provider', 'mail_failover_enabled')"
        );
        $query->execute();
        $result = $query->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $query->close();

        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }
        $primary = strtolower(trim($values['mail_primary_provider'] ?? ''));
        if (in_array($primary, ['smtp', 'graph'], true)) {
            $defaults['primary'] = $primary;
            $defaults['backup'] = $primary === 'graph' ? 'smtp' : 'graph';
            $defaults['source'] = 'database';
            $defaults['failover_enabled'] = in_array(
                strtolower(trim($values['mail_failover_enabled'] ?? '0')),
                ['1', 'yes', 'true', 'on'],
                true
            );
        }
    } catch (Throwable $exception) {
        // Do not surface connection details or block existing email functionality.
        error_log('[Dynabase Mail] Provider preference lookup unavailable; using configured defaults.');
    } finally {
        if ($ownsConnection && $db instanceof mysqli) {
            try {
                $db->close();
            } catch (Throwable) {
                // Nothing further to clean up.
            }
        }
    }

    $cached = $defaults;
    return $cached;
}

function dynabaseMailProviderReadiness(): array
{
    $smtpSender = envString('SMTP_FROM_EMAIL', envString('MAIL_FROM_ADDRESS', envString('SMTP_USERNAME', envString('SMTP_USER'))));

    return [
        'smtp' => trim(envString('SMTP_HOST')) !== ''
            && filter_var($smtpSender, FILTER_VALIDATE_EMAIL) !== false
            && (!envBool('SMTP_AUTH', true) || (
                trim(envString('SMTP_USERNAME', envString('SMTP_USER'))) !== ''
                && trim(envString('SMTP_PASSWORD', envString('SMTP_PASS'))) !== ''
            )),
        'graph' => trim(envString('GRAPH_TENANT_ID')) !== ''
            && trim(envString('GRAPH_CLIENT_ID')) !== ''
            && trim(envString('GRAPH_CLIENT_SECRET')) !== ''
            && filter_var(envString('GRAPH_SENDER'), FILTER_VALIDATE_EMAIL) !== false,
    ];
}
