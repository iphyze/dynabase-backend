<?php
declare(strict_types=1);

/**
 * Housekeeping: removes login sessions that have expired or been revoked, old login attempts and
 * expired client-survey form sessions. Nothing else in the app deletes these rows.
 *
 * Run daily from cron, e.g. (cPanel "Cron Jobs", once a day at 02:15):
 *   15 2 * * * /usr/local/bin/php /home/<account>/<backend>/cron/cleanupAuthRecords.php >/dev/null 2>&1
 *
 * Retention (days) can be tuned in .env:
 *   AUTH_SESSION_RETENTION_DAYS   default 7   (expired/revoked sessions kept this long for troubleshooting)
 *   LOGIN_ATTEMPT_RETENTION_DAYS  default 30  (rate limiting only looks back 60 minutes at most)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/connection.php';

$sessionDays = max(1, min((int) envString('AUTH_SESSION_RETENTION_DAYS', '7'), 365));
$attemptDays = max(1, min((int) envString('LOGIN_ATTEMPT_RETENTION_DAYS', '30'), 365));

/** Deletes in batches so a large backlog never locks the table for long. */
$purge = static function (mysqli $conn, string $sql, int $days): int {
    $total = 0;
    do {
        $stmt = $conn->prepare($sql . ' LIMIT 2000');
        $stmt->bind_param('i', $days);
        $stmt->execute();
        $deleted = (int) $stmt->affected_rows;
        $stmt->close();
        $total += $deleted;
    } while ($deleted === 2000);
    return $total;
};

$result = [
    'healthy' => true,
    'auth_sessions_deleted' => $purge(
        $conn,
        'DELETE FROM auth_sessions
         WHERE expires_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            OR (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(), INTERVAL ' . $sessionDays . ' DAY))',
        $sessionDays
    ),
    'login_attempts_deleted' => $purge(
        $conn,
        'DELETE FROM auth_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
        $attemptDays
    ),
    'survey_form_sessions_deleted' => $purge(
        $conn,
        'DELETE FROM client_survey_form_sessions WHERE expires_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
        $sessionDays
    ),
    'retention_days' => ['sessions' => $sessionDays, 'login_attempts' => $attemptDays],
    'ran_at' => date('c'),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
