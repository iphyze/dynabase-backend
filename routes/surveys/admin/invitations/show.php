<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/surveys.php';
require_once __DIR__ . '/../../../../includes/surveyInvitationEmails.php';

requireMethod('GET');
$authUser = authenticateUser();
$id = requiredIntFromRequest();
$invitation = dbFetchOne($conn, invitationSelectSql() . ' WHERE i.id = ? LIMIT 1', 'i', [$id]);
if (!$invitation) throw new RuntimeException('Survey link not found.', 404);

// A link's immutable submission counter is distinct from the number of
// currently visible (non-deleted) survey responses.
$invitation = castSurveyInvitation($invitation);
$invitation['mail_summary'] = surveyInvitationEmailSummary($conn, $id);

// Check recoverability without revealing a bearer token in an ordinary detail response.
$secure = dbFetchOne($conn,
    'SELECT token_hash, token_ciphertext FROM client_survey_invitations WHERE id = ? LIMIT 1',
    'i', [$id]
);
$raw = surveyInvitationDecryptToken($secure['token_ciphertext'] ?? null);
$invitation['link_recoverable'] = $raw !== null && hash_equals((string) $secure['token_hash'], surveyHash($raw));
$invitation['link_recovery_note'] = $invitation['link_recoverable']
    ? null
    : 'This link predates secure token recovery, or its recovery key has changed. Create a replacement link to share a valid URL.';

header('Cache-Control: private, no-store, max-age=0');
jsonResponse([
    'status' => 'Success',
    'message' => 'Survey link details retrieved successfully.',
    'data' => $invitation,
]);
