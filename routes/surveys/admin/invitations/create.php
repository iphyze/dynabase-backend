<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/surveys.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../../../includes/surveyInvitationEmails.php';
require_once __DIR__ . '/../../../../includes/ownership.php';
require_once __DIR__ . '/../../../../includes/projects.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();

$scope = cleanString($payload['scope'] ?? '');
if ($scope !== '' && !in_array($scope, ['general', 'targeted'], true)) {
    throw new RuntimeException('Invalid survey link type.', 422);
}
$requestedClientId = (int) ($payload['client_id'] ?? 0);
$requestedProjectId = (int) ($payload['project_id'] ?? 0);
$client = $requestedClientId > 0 ? assertClientAccessible($conn, $authUser, $requestedClientId) : null;
$project = $requestedProjectId > 0 ? dbFetchOne($conn, "SELECT * FROM project_info_table WHERE id = ? AND record_status = 'active' LIMIT 1", 'i', [$requestedProjectId]) : null;
if ($requestedProjectId > 0 && !$project) throw new RuntimeException('The selected project is not available.', 422);
$clientId = $client ? (int) $client['id'] : null;
$projectId = $project ? (int) $project['id'] : null;

$recipientName = optionalStringField($payload, 'recipient_name', 255);
$recipientEmail = optionalEmailField($payload, 'recipient_email', 'Recipient email');
$sendRequested = !empty($payload['send_email']);
$recipientEmails = surveyInvitationNormalizeEmails($payload['recipient_emails'] ?? '', $recipientEmail);
if ($sendRequested && !$recipientEmails) {
    throw new RuntimeException('Enter at least one valid email recipient before requesting email invitations.', 422);
}
// One shared targeted link must never prefill one person's identity for multiple people.
$personalizedRecipient = count($recipientEmails) <= 1;
$scope = $scope !== '' ? $scope : ($requestedClientId || $requestedProjectId || $recipientName !== '' || $recipientEmail !== '' || cleanString($payload['client_name'] ?? '') !== '' || cleanString($payload['project_title'] ?? '') !== '' ? 'targeted' : 'general');
$storedRecipientName = $scope === 'targeted' && $personalizedRecipient ? $recipientName : '';
$storedRecipientEmail = $scope === 'targeted' && $personalizedRecipient ? ($recipientEmails[0] ?? '') : '';
$clientName = optionalStringField($payload, 'client_name', 255);
$projectTitle = optionalStringField($payload, 'project_title', 255);
if ($client) $clientName = cleanString($client['clients_name'] ?? $clientName);
if ($project) $projectTitle = cleanString($project['project_title'] ?? $projectTitle);
if ($scope === 'general' && ($clientId !== null || $projectId !== null || $clientName !== '' || $projectTitle !== '')) {
    throw new RuntimeException('Reusable public links cannot include targeted client or project details.', 422);
}

$expiresAtRaw = cleanString($payload['expires_at'] ?? '');
$expiresTimestamp = $expiresAtRaw !== '' ? strtotime($expiresAtRaw . (strlen($expiresAtRaw) <= 10 ? ' 23:59:59' : '')) : strtotime('+14 days');
if (!$expiresTimestamp || $expiresTimestamp <= time()) throw new RuntimeException('Expiry date must be in the future.', 422);
if ($expiresTimestamp > strtotime('+180 days')) throw new RuntimeException('Survey links may not remain active for more than 180 days.', 422);
$expiresAt = date('Y-m-d H:i:s', $expiresTimestamp);
$maxSubmissions = max(1, min((int) ($payload['max_submissions'] ?? 250), 5000));
if ($sendRequested && $maxSubmissions < count($recipientEmails)) {
    throw new RuntimeException('Maximum submissions must be at least the number of invited recipients.', 422);
}

$ownerId = $client && !empty($client['owner_pms_admin_id']) ? (int) $client['owner_pms_admin_id'] : null;
$isGeneralLink = $scope === 'general';


$rawToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$tokenHash = surveyHash($rawToken);
$tokenCiphertext = surveyInvitationEncryptToken($rawToken);
$actorId = (int) $authUser['id'];
$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        'INSERT INTO client_survey_invitations
         (token_hash, token_ciphertext, link_scope, client_id, project_id, recipient_name, recipient_email, client_name_snapshot,
          project_title_snapshot, owner_pms_admin_id, expires_at, max_submissions, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?)'
    );
    $stmt->bind_param(
        'sssiissssisii',
        $tokenHash,
        $tokenCiphertext,
        $scope,
        $clientId,
        $projectId,
        $storedRecipientName,
        $storedRecipientEmail,
        $clientName,
        $projectTitle,
        $ownerId,
        $expiresAt,
        $maxSubmissions,
        $actorId
    );
    $stmt->execute();
    $invitationId = (int) $stmt->insert_id;
    $stmt->close();

    surveyInvitationStoreRecipients($conn, $invitationId, $recipientEmails, $storedRecipientName, $sendRequested);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

$link = surveyPublicFrontendBaseUrl() . '/client-survey/' . rawurlencode($rawToken);
$mailMessage = null;
$mailSummary = [
    'total' => count($recipientEmails), 'not_requested' => $sendRequested ? 0 : count($recipientEmails),
    'queued' => $sendRequested ? count($recipientEmails) : 0,
    'processing' => 0, 'accepted' => 0, 'failed' => 0, 'uncertain' => 0,
];
try {
    if ($sendRequested) {
        // One email per HTTP request prevents slow mail servers exhausting the PHP execution limit.
        $mailSummary = surveyInvitationSendBatch($conn, $invitationId, $authUser, 1);
    } else {
        $mailSummary = surveyInvitationEmailSummary($conn, $invitationId);
    }
} catch (Throwable $exception) {
    // A transport/storage failure must not undo a successfully generated public link.
    $mailMessage = 'The link was created but the email batch could not finish. Check invitation status before continuing.';
    error_log('[Dynabase Survey Invitations] Initial batch failed: ' . $exception->getMessage());
    try { $mailSummary = surveyInvitationEmailSummary($conn, $invitationId); } catch (Throwable $ignored) { /* Preserve the link. */ }
}

writeAuditLog($conn, $authUser, 'client_surveys.invitation_created', 'client_survey_invitation', $invitationId, [
    'client_id' => $clientId ?: null,
    'project_id' => $projectId ?: null,
    'recipient_count' => count($recipientEmails),
    'send_requested' => $sendRequested,
    'expires_at' => $expiresAt,
    'max_submissions' => $maxSubmissions,
]);

jsonResponse([
    'status' => 'Success',
    'message' => $isGeneralLink ? 'Reusable client survey link created successfully.' : 'Secure client survey link created successfully.',
    'data' => [
        'id' => $invitationId,
        'link' => $link,
        'expires_at' => $expiresAt,
        'mail_sent' => $sendRequested && $mailSummary['total'] > 0 && $mailSummary['accepted'] === $mailSummary['total'],
        'mail_summary' => $mailSummary,
        'mail_message' => $mailMessage,
    ],
], 201);
