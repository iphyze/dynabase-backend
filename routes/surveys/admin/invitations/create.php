<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/surveys.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../../../includes/mailer.php';
require_once __DIR__ . '/../../../../includes/ownership.php';
require_once __DIR__ . '/../../../../includes/projects.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);
$payload = readJsonBody();

$requestedClientId = (int) ($payload['client_id'] ?? 0);
$requestedProjectId = (int) ($payload['project_id'] ?? 0);
$client = $requestedClientId > 0 ? assertClientAccessible($conn, $authUser, $requestedClientId) : null;
$project = $requestedProjectId > 0 ? dbFetchOne($conn, "SELECT * FROM project_info_table WHERE id = ? AND record_status = 'active' LIMIT 1", 'i', [$requestedProjectId]) : null;
if ($requestedProjectId > 0 && !$project) throw new RuntimeException('The selected project is not available.', 422);
$clientId = $client ? (int) $client['id'] : null;
$projectId = $project ? (int) $project['id'] : null;

$recipientName = optionalStringField($payload, 'recipient_name', 255);
$recipientEmail = optionalEmailField($payload, 'recipient_email', 'Recipient email');
$clientName = optionalStringField($payload, 'client_name', 255);
$projectTitle = optionalStringField($payload, 'project_title', 255);
if ($client) $clientName = cleanString($client['clients_name'] ?? $clientName);
if ($project) $projectTitle = cleanString($project['project_title'] ?? $projectTitle);

$expiresAtRaw = cleanString($payload['expires_at'] ?? '');
$expiresTimestamp = $expiresAtRaw !== '' ? strtotime($expiresAtRaw . (strlen($expiresAtRaw) <= 10 ? ' 23:59:59' : '')) : strtotime('+14 days');
if (!$expiresTimestamp || $expiresTimestamp <= time()) throw new RuntimeException('Expiry date must be in the future.', 422);
if ($expiresTimestamp > strtotime('+180 days')) throw new RuntimeException('Survey links may not remain active for more than 180 days.', 422);
$expiresAt = date('Y-m-d H:i:s', $expiresTimestamp);
$maxSubmissions = max(1, min((int) ($payload['max_submissions'] ?? 250), 5000));
if (!empty($payload['send_email']) && $recipientEmail === '') {
    throw new RuntimeException('Recipient email is required when the link should be emailed.', 422);
}

$ownerId = $client && !empty($client['owner_pms_admin_id']) ? (int) $client['owner_pms_admin_id'] : null;
$isGeneralLink = $clientId === null && $projectId === null && $clientName === '' && $projectTitle === '' && $recipientEmail === '';


$rawToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$tokenHash = surveyHash($rawToken);
$actorId = (int) $authUser['id'];
$stmt = $conn->prepare(
    'INSERT INTO client_survey_invitations
     (token_hash, client_id, project_id, recipient_name, recipient_email, client_name_snapshot,
      project_title_snapshot, owner_pms_admin_id, expires_at, max_submissions, status, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?)'
);
$stmt->bind_param(
    'siissssisii',
    $tokenHash,
    $clientId,
    $projectId,
    $recipientName,
    $recipientEmail,
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

$link = surveyPublicFrontendBaseUrl() . '/client-survey/' . rawurlencode($rawToken);
$mailResult = null;
if (!empty($payload['send_email']) && $recipientEmail !== '') {
    $mailResult = sendClientSurveyInvitationEmail([
        'recipient_name' => $recipientName,
        'recipient_email' => $recipientEmail,
        'company' => $clientName,
        'project_title' => $projectTitle,
        'expires_at' => $expiresAt,
        'link' => $link,
    ]);
}

writeAuditLog($conn, $authUser, 'client_surveys.invitation_created', 'client_survey_invitation', $invitationId, [
    'client_id' => $clientId ?: null,
    'project_id' => $projectId ?: null,
    'recipient_email' => $recipientEmail ?: null,
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
        'mail_sent' => $mailResult === true,
        'mail_message' => is_string($mailResult) ? $mailResult : null,
    ],
], 201);
