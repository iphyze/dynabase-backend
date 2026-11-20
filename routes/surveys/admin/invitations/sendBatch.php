<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/surveyInvitationEmails.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$invitationId = requiredIntFromPayload($payload, 'id', 'Survey link ID');
$summary = surveyInvitationSendBatch($conn, $invitationId, $authUser, 1);
jsonResponse([
    'status' => 'Success',
    'message' => 'Invitation email batch processed. Accepted messages are not confirmed deliveries.',
    'data' => ['id' => $invitationId, 'mail_summary' => $summary],
]);
