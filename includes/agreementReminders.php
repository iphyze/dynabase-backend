<?php
declare(strict_types=1);

require_once __DIR__ . '/agreements.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/audit.php';

function agreementReminderProfileUrl(int $agreementId): string
{
    return rtrim(envString('FRONTEND_URL', 'http://localhost:5173'), '/') . '/agreement-register/' . $agreementId;
}

function agreementReminderTemplateData(array $record): array
{
    return [
        'document_ref_no' => cleanString($record['document_ref_no'] ?? ''),
        'document_ref_type' => cleanString($record['document_ref_type'] ?? ''),
        'client_company' => cleanString($record['client_company'] ?? ''),
        'project_subject' => cleanString($record['project_subject'] ?? ''),
        'status' => cleanString($record['status'] ?? ''),
        'effective_date' => cleanString($record['effective_date'] ?? ''),
        'expiry_date' => cleanString($record['expiry_date'] ?? ''),
        'reminder_date' => cleanString($record['reminder_date'] ?? ''),
        'purpose' => cleanString($record['purpose'] ?? ''),
        'agreement_url' => agreementReminderProfileUrl((int) ($record['id'] ?? 0)),
    ];
}

function agreementReminderEmails(array $record): array
{
    $emails = $record['reminder_emails'] ?? [];
    if (is_string($emails)) {
        $decoded = json_decode($emails, true);
        $emails = is_array($decoded) ? $decoded : [];
    }

    $valid = [];
    foreach ((array) $emails as $candidate) {
        $email = cleanEmail($candidate);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $valid[strtolower($email)] = $email;
        }
    }

    return array_values($valid);
}

function agreementReminderScheduleAlreadyDelivered(
    mysqli $conn,
    int $agreementId,
    string $reminderDate,
    string $recipientEmail
): bool {
    $row = dbFetchOne(
        $conn,
        "SELECT id
         FROM agreement_reminder_deliveries
         WHERE agreement_id = ? AND reminder_date = ? AND recipient_email = ?
           AND satisfies_schedule = 1 AND status = 'sent'
         LIMIT 1",
        'iss',
        [$agreementId, $reminderDate, strtolower($recipientEmail)]
    );

    return $row !== null;
}

function recordAgreementReminderDelivery(
    mysqli $conn,
    array $record,
    string $recipientEmail,
    string $deliveryMode,
    bool $satisfiesSchedule,
    string $status,
    ?string $errorMessage,
    ?array $actor
): void {
    $actorEmail = $actor ? actorEmail($actor) : 'system@dynabase.local';
    $actorId = $actor ? (int) ($actor['id'] ?? 0) : 0;
    $actorIdValue = $actorId > 0 ? $actorId : null;

    $stmt = dbExecute(
        $conn,
        'INSERT INTO agreement_reminder_deliveries
            (agreement_id, reminder_date, recipient_email, delivery_mode, satisfies_schedule,
             status, error_message, triggered_by, triggered_by_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'isssisssi',
        [
            (int) $record['id'],
            (string) $record['reminder_date'],
            strtolower($recipientEmail),
            $deliveryMode,
            $satisfiesSchedule ? 1 : 0,
            $status,
            $errorMessage,
            $actorEmail,
            $actorIdValue,
        ]
    );
    $stmt->close();
}

function agreementReminderScheduleComplete(mysqli $conn, array $record, array $recipients): bool
{
    if ($recipients === []) {
        return false;
    }

    foreach ($recipients as $email) {
        if (!agreementReminderScheduleAlreadyDelivered(
            $conn,
            (int) $record['id'],
            (string) $record['reminder_date'],
            $email
        )) {
            return false;
        }
    }

    return true;
}

function updateAgreementReminderState(
    mysqli $conn,
    int $agreementId,
    string $status,
    ?string $errorMessage,
    bool $markSent
): void {
    if ($markSent) {
        $stmt = dbExecute(
            $conn,
            "UPDATE agreement_registers
             SET reminder_sent_at = COALESCE(reminder_sent_at, CURRENT_TIMESTAMP),
                 reminder_last_status = 'sent', reminder_last_error = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND record_status = 'active'",
            'i',
            [$agreementId]
        );
    } else {
        $stmt = dbExecute(
            $conn,
            "UPDATE agreement_registers
             SET reminder_last_status = ?, reminder_last_error = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND record_status = 'active' AND reminder_sent_at IS NULL",
            'ssi',
            [$status, $errorMessage, $agreementId]
        );
    }
    $stmt->close();
}

function sendAgreementReminder(
    mysqli $conn,
    array $record,
    string $deliveryMode = 'manual',
    ?array $actor = null
): array {
    if (!in_array($deliveryMode, ['automatic', 'manual'], true)) {
        throw new RuntimeException('Invalid reminder delivery mode.', 422);
    }

    $agreementId = (int) ($record['id'] ?? 0);
    if ($agreementId <= 0) {
        throw new RuntimeException('Agreement record is invalid.', 422);
    }

    $recipients = agreementReminderEmails($record);
    if ($recipients === []) {
        throw new RuntimeException('No valid reminder recipients are configured.', 422);
    }

    $todayRow = dbFetchOne($conn, 'SELECT CURRENT_DATE() AS today');
    $today = cleanString($todayRow['today'] ?? date('Y-m-d'));
    $reminderDate = cleanString($record['reminder_date'] ?? '');
    $satisfiesSchedule = $reminderDate !== '' && $today >= $reminderDate;
    $templateData = agreementReminderTemplateData($record);

    $sent = 0;
    $failed = 0;
    $skipped = 0;
    $errors = [];

    foreach ($recipients as $email) {
        if (
            $deliveryMode === 'automatic'
            && agreementReminderScheduleAlreadyDelivered($conn, $agreementId, $reminderDate, $email)
        ) {
            $skipped++;
            continue;
        }

        $result = sendDynabaseTemplateEmail(
            'agreement_reminder',
            $templateData,
            [['email' => $email]]
        );

        $ok = $result === true;
        $error = $ok ? null : trim((string) $result);
        recordAgreementReminderDelivery(
            $conn,
            $record,
            $email,
            $deliveryMode,
            $satisfiesSchedule,
            $ok ? 'sent' : 'failed',
            $error,
            $actor
        );

        if ($ok) {
            $sent++;
        } else {
            $failed++;
            if ($error !== '') {
                $errors[] = $email . ': ' . $error;
            }
        }
    }

    $scheduleComplete = $satisfiesSchedule && agreementReminderScheduleComplete($conn, $record, $recipients);
    $lastError = $errors === [] ? null : substr(implode(' | ', $errors), 0, 1000);

    if ($scheduleComplete) {
        updateAgreementReminderState($conn, $agreementId, 'sent', null, true);
    } elseif ($deliveryMode === 'automatic' || $satisfiesSchedule) {
        updateAgreementReminderState($conn, $agreementId, $failed > 0 ? 'failed' : 'pending', $lastError, false);
    }

    writeAuditLog($conn, $actor, 'agreement_register.reminder_' . ($failed > 0 ? 'failed' : 'sent'), 'agreement_register', $agreementId, [
        'document_ref_no' => $record['document_ref_no'] ?? null,
        'delivery_mode' => $deliveryMode,
        'reminder_date' => $reminderDate,
        'recipient_count' => count($recipients),
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped,
        'schedule_complete' => $scheduleComplete,
    ]);

    return [
        'recipient_count' => count($recipients),
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped,
        'schedule_complete' => $scheduleComplete,
        'error' => $lastError,
    ];
}

function processDueAgreementReminders(mysqli $conn, ?array $scopeUser = null): array
{
    $where = " WHERE a.record_status = 'active' AND a.reminder_sent_at IS NULL AND a.reminder_date <= CURRENT_DATE()
               AND a.status NOT IN ('Renewed', 'Terminated', 'Archived')";
    $types = '';
    $params = [];

    if ($scopeUser !== null) {
        [$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($scopeUser, 'a');
        $where .= $scopeSql;
        $types .= $scopeTypes;
        $params = array_merge($params, $scopeParams);
    }

    $rows = dbFetchAll(
        $conn,
        agreementSelectSql() . $where . ' ORDER BY a.reminder_date ASC, a.id ASC',
        $types,
        $params
    );

    $checked = count($rows);
    $completed = 0;
    $failed = 0;
    $sent = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        $record = castAgreementRecord($row);
        try {
            $result = sendAgreementReminder($conn, $record, 'automatic');
            $sent += (int) $result['sent'];
            $skipped += (int) $result['skipped'];
            if ($result['schedule_complete']) {
                $completed++;
            }
            if ((int) $result['failed'] > 0) {
                $failed++;
            }
        } catch (Throwable $exception) {
            $failed++;
            $message = substr($exception->getMessage(), 0, 1000);
            updateAgreementReminderState($conn, (int) $record['id'], 'failed', $message, false);
            writeAuditLog($conn, null, 'agreement_register.reminder_failed', 'agreement_register', (int) $record['id'], [
                'document_ref_no' => $record['document_ref_no'] ?? null,
                'delivery_mode' => 'automatic',
                'error' => $message,
            ]);
        }
    }

    return [
        'checked' => $checked,
        'completed' => $completed,
        'failed' => $failed,
        'sent' => $sent,
        'skipped' => $skipped,
    ];
}

function agreementReminderDeliveryHistory(mysqli $conn, array $authUser, int $agreementId, int $limit = 20): array
{
    assertAgreementAccessible($conn, $authUser, $agreementId);
    $limit = max(1, min(100, $limit));

    $rows = dbFetchAll(
        $conn,
        "SELECT ard.id, ard.agreement_id, ard.reminder_date, ard.recipient_email,
                ard.delivery_mode, ard.satisfies_schedule, ard.status, ard.error_message,
                ard.triggered_by, ard.triggered_by_id, ard.created_at,
                NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), '') AS triggered_by_name
         FROM agreement_reminder_deliveries ard
         LEFT JOIN users u ON u.id = ard.triggered_by_id
         WHERE ard.agreement_id = ?
         ORDER BY ard.created_at DESC, ard.id DESC
         LIMIT ?",
        'ii',
        [$agreementId, $limit]
    );

    return array_map(static function (array $row): array {
        $row['id'] = (int) $row['id'];
        $row['agreement_id'] = (int) $row['agreement_id'];
        $row['triggered_by_id'] = $row['triggered_by_id'] !== null ? (int) $row['triggered_by_id'] : null;
        $row['satisfies_schedule'] = (int) $row['satisfies_schedule'] === 1;
        return $row;
    }, $rows);
}
