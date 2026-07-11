<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/request.php';
require_once __DIR__ . '/../../../includes/surveys.php';
require_once __DIR__ . '/../../../includes/audit.php';

requireMethod('GET');
$token = cleanString($_GET['token'] ?? '');
$invitation = assertSurveyInvitationAvailable($conn, $token);
$session = createSurveyFormSession($conn, (int) $invitation['id']);

$stmt = dbExecute(
    $conn,
    'UPDATE client_survey_invitations SET last_accessed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
    'i',
    [(int) $invitation['id']]
);
$stmt->close();

jsonResponse([
    'status' => 'Success',
    'message' => 'Client survey is ready.',
    'data' => [
        'survey' => [
            'recipient_name' => $invitation['recipient_name'] ?: null,
            'recipient_email' => $invitation['recipient_email'] ?: null,
            'company' => $invitation['linked_client_name'] ?: $invitation['client_name_snapshot'],
            'project_title' => $invitation['linked_project_title'] ?: $invitation['project_title_snapshot'],
            'project_code' => $invitation['linked_project_code'] ?: null,
            'expires_at' => $invitation['expires_at'],
        ],
        'session' => [
            'token' => $session['token'],
            'expires_at' => $session['expires_at'],
        ],
        'turnstile' => [
            'enabled' => envBool('TURNSTILE_ENABLED', false),
            'site_key' => envString('TURNSTILE_SITE_KEY'),
        ],
    ],
]);
