<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/surveyInvitationEmails.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Survey link ID');
$recipientId = requiredIntFromPayload($payload, 'recipient_id', 'Recipient');
if (($payload['confirm'] ?? null) !== true) {
    throw new RuntimeException('Explicit confirmation is required before sending another invitation.', 422);
}
$invitation = dbFetchOne($conn, 'SELECT * FROM client_survey_invitations WHERE id = ? LIMIT 1', 'i', [$id]);
if (!$invitation) throw new RuntimeException('Survey link not found.', 404);
if (surveyInvitationStatus($invitation) !== 'active') {
    throw new RuntimeException('Only an active survey link can be resent.', 422);
}
$raw = surveyInvitationDecryptToken($invitation['token_ciphertext'] ?? null);
if ($raw === null || !hash_equals((string) $invitation['token_hash'], surveyHash($raw))) {
    throw new RuntimeException('The original link cannot be recovered securely. Create a replacement link.', 409);
}
// An explicit resend is limited to one recipient and must never requeue an
// uncertain/processing message. Accepted messages have a 24-hour cooldown.
// The conditional UPDATE is atomic, so double-clicks/concurrent admins cannot
// enqueue duplicate messages. Audit records preserve prior send attempts.
$stmt = dbExecute($conn,
    "UPDATE client_survey_invitation_recipients
     SET mail_status = 'queued', last_error = NULL
     WHERE id = ? AND invitation_id = ? AND attempt_count < 5
       AND (mail_status = 'not_requested'
            OR (mail_status = 'accepted' AND
                (last_attempted_at IS NULL OR last_attempted_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR))))",
    'ii', [$recipientId, $id]
);
$queued = $stmt->affected_rows === 1;
$stmt->close();
if (!$queued) {
    throw new RuntimeException('This recipient is not eligible for a manual resend. Check email status, the 24-hour cooldown, or the retry limit.', 409);
}
writeAuditLog($conn, $authUser, 'client_surveys.invitation_manual_resend_requested', 'client_survey_invitation', $id, [
    'recipient_id' => $recipientId,
]);
$warning = null;
try {
    $summary = surveyInvitationSendBatch($conn, $id, $authUser, 1, $recipientId);
} catch (Throwable $error) {
    error_log('[Dynabase Survey Invitations] Manual resend interrupted: ' . $error->getMessage());
    $warning = 'The invitation was queued but could not be fully processed. Check its status before any further action.';
    $summary = surveyInvitationEmailSummary($conn, $id);
}
jsonResponse([
    'status' => 'Success',
    'message' => $warning ?? 'Manual invitation send processed. Provider acceptance does not confirm inbox delivery.',
    'data' => ['id' => $id, 'recipient_id' => $recipientId, 'mail_summary' => $summary, 'warning' => $warning],
]);
