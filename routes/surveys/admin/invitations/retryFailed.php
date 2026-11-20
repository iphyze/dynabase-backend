<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/surveyInvitationEmails.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$invitationId = requiredIntFromPayload($payload, 'id', 'Survey link ID');
if (($payload['confirm'] ?? null) !== true) {
    throw new RuntimeException('Confirm retrying only definitely failed invitation emails.', 422);
}
$invitation = dbFetchOne($conn, 'SELECT * FROM client_survey_invitations WHERE id = ? LIMIT 1', 'i', [$invitationId]);
if (!$invitation) throw new RuntimeException('Survey link not found.', 404);
if (surveyInvitationStatus($invitation) !== 'active') {
    throw new RuntimeException('Expired, completed or revoked links cannot send invitations.', 422);
}
if (surveyInvitationDecryptToken($invitation['token_ciphertext'] ?? null) === null) {
    throw new RuntimeException('The original link is not available for secure resending.', 422);
}
// Never retry accepted, uncertain or in-progress messages. Two manual retries maximum.
$stmt = dbExecute($conn,
    "UPDATE client_survey_invitation_recipients SET mail_status = 'queued', last_error = NULL WHERE invitation_id = ? AND mail_status = 'failed' AND attempt_count < 3",
    'i', [$invitationId]
);
$requeued = $stmt->affected_rows;
$stmt->close();
writeAuditLog($conn, $authUser, 'client_surveys.invitation_email_retry_requested', 'client_survey_invitation', $invitationId, [
    'requeued' => $requeued,
]);
$summary = $requeued > 0
    ? surveyInvitationSendBatch($conn, $invitationId, $authUser, 1)
    : surveyInvitationEmailSummary($conn, $invitationId);
jsonResponse([
    'status' => 'Success',
    'message' => $requeued > 0 ? 'Failed emails queued for a safe retry.' : 'No safe-to-retry failed emails remain.',
    'data' => ['id' => $invitationId, 'requeued' => $requeued, 'mail_summary' => $summary],
]);
