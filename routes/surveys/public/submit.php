<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/request.php';
require_once __DIR__ . '/../../../includes/surveys.php';
require_once __DIR__ . '/../../../includes/audit.php';
require_once __DIR__ . '/../../../includes/mailer.php';

requireMethod('POST');
enforceTrustedOrigin();
$payload = readJsonBody();
$rawToken = cleanString($payload['token'] ?? '');
$sessionToken = cleanString($payload['session_token'] ?? '');

$transactionCommitted = false;
$conn->begin_transaction();
try {
    assertSurveySubmitRateLimit($conn);
    $invitation = assertSurveyInvitationAvailable($conn, $rawToken, true);
    $session = assertSurveyFormSession($conn, (int) $invitation['id'], $sessionToken);
    verifySurveyTurnstile($payload);
    $data = normalizePublicSurveyPayload($payload);

    $invitationId = (int) $invitation['id'];
    $clientId = !empty($invitation['client_id']) ? (int) $invitation['client_id'] : null;
    $projectId = !empty($invitation['project_id']) ? (int) $invitation['project_id'] : null;
    $ownerId = !empty($invitation['linked_owner_pms_admin_id'])
        ? (int) $invitation['linked_owner_pms_admin_id']
        : (!empty($invitation['owner_pms_admin_id']) ? (int) $invitation['owner_pms_admin_id'] : null);
    $reference = 'CSR-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
    $ipHash = surveyIpHash();
    $userAgentHash = surveyUserAgentHash();

    if (($data['survey_version'] ?? 1) === 2) {
        // The new form writes its complete, validated answers to the v2 JSON column.
        // Legacy NOT NULL fields receive empty strings, never fabricated ratings.
        // The v1 insert below is left intact for already-open survey links/forms.
        $fields = [
            'invitation_id' => $invitationId,
            'client_id' => $clientId,
            'project_id' => $projectId,
            'owner_pms_admin_id' => $ownerId,
            'submission_reference' => $reference,
            'overall_score' => $data['overall_score'],
            'survey_version' => 2,
            'stakeholder_category' => $data['stakeholder_category'],
            'nature_of_engagement' => $data['nature_of_engagement'],
            'survey_rated_count' => $data['survey_rated_count'],
            'survey_answers' => $data['survey_answers'],
        ];
        foreach ([
            'quality', 'quality_comments', 'timeline', 'timeline_comments',
            'expertise', 'expertise_comments', 'communication', 'communication_comments',
            'resolution', 'resolution_comments', 'cleaniness', 'cleaniness_comments',
            'safety', 'safety_comments', 'response', 'response_comments',
            'electrical_services', 'mechanical_services',
        ] as $legacyField) {
            $fields[$legacyField] = '';
        }
        $fields += [
            'filled_by' => $data['filled_by'],
            'position' => $data['position'],
            'office_address' => $data['office_address'],
            'phone_number' => $data['phone_number'],
            'fax_number' => '',
            'project_title' => $data['project_title'],
            'company' => $data['company'],
            'location' => $data['location'],
            'email' => $data['email'],
            'submission_ip_hash' => $ipHash,
            'submission_user_agent_hash' => $userAgentHash,
        ];
        $columns = array_keys($fields);
        $identifiers = '`' . implode('`, `', $columns) . '`';
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO clients_survey_form ({$identifiers}, `response_status`, `createdAt`, `updatedAt`)
                VALUES ({$placeholders}, 'submitted', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
        $types = '';
        foreach ($fields as $field => $value) {
            $types .= is_float($value) ? 'd' : (is_int($value) || $value === null && in_array($field, [
                'invitation_id', 'client_id', 'project_id', 'owner_pms_admin_id'
            ], true) ? 'i' : 's');
        }
        $stmt = dbExecute($conn, $sql, $types, array_values($fields));
        $surveyId = (int) $stmt->insert_id;
        $stmt->close();
    } else {
    $stmt = $conn->prepare(
        'INSERT INTO clients_survey_form
        (invitation_id, client_id, project_id, owner_pms_admin_id, submission_reference, overall_score,
         quality, quality_comments, timeline, timeline_comments, expertise, expertise_comments,
         communication, communication_comments, resolution, resolution_comments,
         cleaniness, cleaniness_comments, safety, safety_comments, response, response_comments,
         electrical_services, mechanical_services, filled_by, position, office_address, phone_number,
         fax_number, project_title, company, location, email, submission_ip_hash, submission_user_agent_hash,
         response_status, createdAt, updatedAt)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'submitted\', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );

    $stmt->bind_param(
        'iiiisdsssssssssssssssssssssssssssss',
        $invitationId,
        $clientId,
        $projectId,
        $ownerId,
        $reference,
        $data['overall_score'],
        $data['quality'],
        $data['quality_comments'],
        $data['timeline'],
        $data['timeline_comments'],
        $data['expertise'],
        $data['expertise_comments'],
        $data['communication'],
        $data['communication_comments'],
        $data['resolution'],
        $data['resolution_comments'],
        $data['cleaniness'],
        $data['cleaniness_comments'],
        $data['safety'],
        $data['safety_comments'],
        $data['response'],
        $data['response_comments'],
        $data['electrical_services'],
        $data['mechanical_services'],
        $data['filled_by'],
        $data['position'],
        $data['office_address'],
        $data['phone_number'],
        $data['fax_number'],
        $data['project_title'],
        $data['company'],
        $data['location'],
        $data['email'],
        $ipHash,
        $userAgentHash
    );
    $stmt->execute();
    $surveyId = (int) $stmt->insert_id;
    $stmt->close();
    }

    $sessionUpdate = dbExecute(
        $conn,
        'UPDATE client_survey_form_sessions SET used_at = CURRENT_TIMESTAMP, survey_response_id = ? WHERE id = ? AND used_at IS NULL',
        'ii',
        [$surveyId, (int) $session['id']]
    );
    $sessionUpdate->close();

    $newCount = (int) $invitation['submission_count'] + 1;
    $newStatus = $newCount >= max(1, (int) $invitation['max_submissions']) ? 'completed' : 'active';
    $inviteUpdate = dbExecute(
        $conn,
        'UPDATE client_survey_invitations
         SET submission_count = ?, status = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?',
        'isi',
        [$newCount, $newStatus, (int) $invitation['id']]
    );
    $inviteUpdate->close();

    $conn->commit();
    $transactionCommitted = true;

    // Audit and email are deliberately non-blocking after the database commit.
    // A mail-server problem must never make a successfully stored client response appear to have failed.
    writeAuditLog($conn, null, 'client_surveys.submitted', 'client_survey', $surveyId, [
        'reference' => $reference,
        'invitation_id' => $invitationId,
        'client_id' => $clientId,
        'project_id' => $projectId,
        'overall_score' => $data['overall_score'],
        'response_url' => rtrim(envString('FRONTEND_URL', 'http://localhost:5173'), '/') . '/client-surveys/' . $surveyId,
    ]);

    $mailResult = sendClientSurveyNotifications([
        'reference' => $reference,
        'filled_by' => $data['filled_by'],
        'email' => $data['email'],
        'company' => $data['company'],
        'project_title' => $data['project_title'],
        'position' => $data['position'],
        'phone_number' => $data['phone_number'],
        'location' => $data['location'],
        'overall_score' => $data['overall_score'],
        'response_url' => rtrim(envString('FRONTEND_URL', 'http://localhost:5173'), '/') . '/client-surveys/' . $surveyId,
    ]);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Thank you. Your feedback has been submitted successfully.',
        'data' => [
            'reference' => $reference,
            'mail_sent' => $mailResult === true,
        ],
    ], 201);
} catch (Throwable $exception) {
    if (!$transactionCommitted) {
        $conn->rollback();
    }
    throw $exception;
}
