<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/giftListEmails.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$actor = authenticateUser();
giftEmailRequireSender($conn, $actor);
validateCsrfToken();
$data = readJsonBody();
$year = validateGiftYear($data['gift_year'] ?? date('Y'));
$kind = trim((string) ($data['type'] ?? ''));
if (!in_array($kind, ['starter', 'reminder', 'custom'], true)) {
    throw new RuntimeException('Choose a valid email type.', 422);
}
$subject = trim((string) ($data['subject'] ?? ''));
$message = trim((string) ($data['message'] ?? ''));
if ($kind === 'custom' && ($subject === '' || $message === '' || strlen($subject) > 160 || strlen($message) > 10000)) {
    throw new RuntimeException('Provide a subject (up to 160 characters) and a message (up to 10,000 characters).', 422);
}
$submitted = $data['recipient_user_ids'] ?? null;
if (!is_array($submitted) || !$submitted || count($submitted) > 20) {
    throw new RuntimeException('Select 1–20 recipients per send.', 422);
}
$ids = [];
foreach ($submitted as $id) {
    if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id <= 0) {
        throw new RuntimeException('Invalid recipient selection.', 422);
    }
    $ids[(int) $id] = (int) $id;
}
$ids = array_values($ids);
$recipients = giftEmailUserRows($conn, $year, $ids);
if (count($recipients) !== count($ids)) {
    throw new RuntimeException('One or more selected recipients are no longer active.', 422);
}
foreach ($recipients as $row) {
    if (!dynabaseIsDeliverableEmail($row['email'])) {
        throw new RuntimeException('A selected user does not have a valid email address.', 422);
    }
    // Reminders only go to members of a PMS team without a saved annual list.
    if ($kind === 'reminder' && ((int) ($row['pms_owner_id'] ?? 0) === 0 || (int) ($row['selected_recipients'] ?? 0) > 0)) {
        throw new RuntimeException('Reminders can only be sent to PMS teams that have not prepared their list.', 422);
    }
}

// Avoid accidental repeat starter/reminder messages while letting custom announcements be resent.
if ($kind !== 'custom') {
    $recent = dbFetchAll($conn,
        'SELECT d.recipient_user_id FROM gift_list_email_deliveries d
         JOIN gift_list_email_batches b ON b.id = d.batch_id
         WHERE b.email_type = ? AND b.gift_year = ? AND d.delivery_status IN (\'sent\',\'logged\')
           AND d.created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
           AND d.recipient_user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
        'si' . str_repeat('i', count($ids)), array_merge([$kind, $year], $ids)
    );
    if ($recent) {
        throw new RuntimeException('Some recipients were emailed in the last five minutes. Check the sending history before retrying.', 409);
    }
}

$actorId = (int) $actor['id'];
$batchSubject = giftEmailSubject($kind, $year, $subject);
$stmt = dbExecute($conn,
    'INSERT INTO gift_list_email_batches (email_type, gift_year, subject, message, created_by_id, recipient_count)
     VALUES (?, ?, ?, ?, ?, ?)',
    'sissii', [$kind, $year, $batchSubject, $kind === 'custom' ? $message : null, $actorId, count($recipients)]
);
$batchId = (int) $stmt->insert_id;
$stmt->close();
$sent = 0;
$failed = 0;
$logged = 0;
foreach ($recipients as $recipient) {
    $emailData = giftEmailContent($kind, $recipient, $year, $subject, $message);
    try {
        $result = sendDynabaseMail([
            'to' => [['email' => (string) $recipient['email'], 'name' => trim($recipient['first_name'] . ' ' . $recipient['last_name'])]],
            'subject' => $emailData['subject'],
            'html' => $emailData['html'],
            'text' => $emailData['text'],
        ]);
    } catch (Throwable $exception) {
        dynabaseLogMailEvent('Gift List email send failed.', ['error' => $exception->getMessage()]);
        $result = 'Email delivery failed.';
    }
    $status = $result === true ? (dynabaseMailTransport() === 'log' ? 'logged' : 'sent') : 'failed';
    $error = $result === true ? null : 'Unable to send. Check the configured email transport/logs.';
    if ($status === 'sent') ++$sent;
    elseif ($status === 'logged') ++$logged;
    else ++$failed;
    $targetId = (int) $recipient['id'];
    $targetEmail = (string) $recipient['email'];
    $insert = dbExecute($conn,
        'INSERT INTO gift_list_email_deliveries
         (batch_id, recipient_user_id, recipient_email, delivery_status, error_message)
         VALUES (?, ?, ?, ?, ?)',
        'iisss', [$batchId, $targetId, $targetEmail, $status, $error]
    );
    $insert->close();
}
writeAuditLog($conn, $actor, 'gift_list_emails.send', 'gift_list_email_batch', $batchId, [
    'type' => $kind, 'year' => $year, 'recipient_count' => count($recipients),
    'sent' => $sent, 'failed' => $failed, 'logged' => $logged,
]);
jsonResponse([
    'status' => 'Success',
    'message' => $failed === 0 ? 'Email batch processed.' : 'Some emails could not be sent. Review the sending history.',
    'data' => ['batch_id' => $batchId, 'sent' => $sent, 'failed' => $failed, 'logged_not_delivered' => $logged],
]);
