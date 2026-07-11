<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/dbHelpers.php';
require_once __DIR__ . '/validation.php';

const CLIENT_SURVEY_RATINGS = ['Excellent', 'Good', 'Average', 'Poor'];
const CLIENT_SURVEY_RATING_SCORES = [
    'Excellent' => 100,
    'Good' => 75,
    'Average' => 50,
    'Poor' => 25,
];

function surveySecuritySecret(): string
{
    $secret = envString('SURVEY_SECURITY_SECRET', envString('JWT_SECRET', 'dynabase-survey-security'));
    return $secret !== '' ? $secret : 'dynabase-survey-security';
}

function surveyHash(string $value): string
{
    return hash_hmac('sha256', $value, surveySecuritySecret());
}

function surveyIpHash(): string
{
    return surveyHash((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function surveyUserAgentHash(): string
{
    return surveyHash(substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512));
}

function surveyPublicFrontendBaseUrl(): string
{
    $configured = rtrim(envString('PUBLIC_SURVEY_FRONTEND_URL'), '/');
    if ($configured !== '') {
        return $configured;
    }

    return rtrim(allowedFrontendOrigins()[0] ?? 'http://localhost:5173', '/');
}

function surveyInvitationStatus(array $invitation): string
{
    if (($invitation['status'] ?? '') === 'revoked') {
        return 'revoked';
    }
    if (($invitation['status'] ?? '') === 'completed') {
        return 'completed';
    }
    if (!empty($invitation['expires_at']) && strtotime((string) $invitation['expires_at']) < time()) {
        return 'expired';
    }
    if ((int) ($invitation['submission_count'] ?? 0) >= max(1, (int) ($invitation['max_submissions'] ?? 1))) {
        return 'completed';
    }
    return 'active';
}

function assertSurveyInvitationAvailable(mysqli $conn, string $rawToken, bool $lock = false): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{40,160}$/', $rawToken)) {
        throw new RuntimeException('This survey link is invalid.', 404);
    }

    $tokenHash = surveyHash($rawToken);
    $lockSql = $lock ? ' FOR UPDATE' : '';
    $invitation = dbFetchOne(
        $conn,
        "SELECT i.*, c.clients_name AS linked_client_name, c.owner_pms_admin_id AS linked_owner_pms_admin_id,
                p.project_title AS linked_project_title, p.tender_code AS linked_project_code
         FROM client_survey_invitations i
         LEFT JOIN clients_table c ON c.id = i.client_id
         LEFT JOIN project_info_table p ON p.id = i.project_id
         WHERE i.token_hash = ? LIMIT 1{$lockSql}",
        's',
        [$tokenHash]
    );

    if (!$invitation) {
        throw new RuntimeException('This survey link is invalid or no longer available.', 404);
    }

    $status = surveyInvitationStatus($invitation);
    if ($status !== 'active') {
        $messages = [
            'expired' => 'This survey link has expired. Please request a new link from Lambert Electromec.',
            'completed' => 'This survey has already been completed.',
            'revoked' => 'This survey link is no longer active.',
        ];
        throw new RuntimeException($messages[$status] ?? 'This survey link is unavailable.', 410);
    }

    $invitation['effective_status'] = $status;
    return $invitation;
}

function assertSurveyStartRateLimit(mysqli $conn, int $invitationId): void
{
    $ipHash = surveyIpHash();
    $perIp = dbScalarInt(
        $conn,
        "SELECT COUNT(*) AS total FROM client_survey_form_sessions
         WHERE ip_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        's',
        [$ipHash]
    );
    $maxStarts = max(5, min((int) envString('SURVEY_MAX_FORM_STARTS_PER_HOUR', '20'), 100));
    if ($perIp >= $maxStarts) {
        throw new RuntimeException('Too many survey attempts were started from this network. Please try again later.', 429);
    }

    $perInvite = dbScalarInt(
        $conn,
        "SELECT COUNT(*) AS total FROM client_survey_form_sessions
         WHERE invitation_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        'i',
        [$invitationId]
    );
    $maxPerInvite = max(10, min((int) envString('SURVEY_MAX_INVITE_STARTS_PER_HOUR', '40'), 200));
    if ($perInvite >= $maxPerInvite) {
        throw new RuntimeException('This survey link has received too many recent requests. Please try again later.', 429);
    }
}

function createSurveyFormSession(mysqli $conn, int $invitationId): array
{
    assertSurveyStartRateLimit($conn, $invitationId);

    $rawToken = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
    $hash = surveyHash($rawToken);
    $ipHash = surveyIpHash();
    $userAgentHash = surveyUserAgentHash();
    $ttlMinutes = max(15, min((int) envString('SURVEY_FORM_SESSION_MINUTES', '120'), 360));
    $expiresAt = date('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

    $stmt = dbExecute(
        $conn,
        'INSERT INTO client_survey_form_sessions
         (invitation_id, session_token_hash, ip_hash, user_agent_hash, expires_at)
         VALUES (?, ?, ?, ?, ?)',
        'issss',
        [$invitationId, $hash, $ipHash, $userAgentHash, $expiresAt]
    );
    $sessionId = (int) $stmt->insert_id;
    $stmt->close();

    return [
        'id' => $sessionId,
        'token' => $rawToken,
        'expires_at' => $expiresAt,
    ];
}

function assertSurveySubmitRateLimit(mysqli $conn): void
{
    $ipHash = surveyIpHash();
    $used = dbScalarInt(
        $conn,
        "SELECT COUNT(*) AS total FROM client_survey_form_sessions
         WHERE ip_hash = ? AND used_at IS NOT NULL AND used_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        's',
        [$ipHash]
    );
    $maxSubmissions = max(2, min((int) envString('SURVEY_MAX_SUBMISSIONS_PER_HOUR', '5'), 25));
    if ($used >= $maxSubmissions) {
        throw new RuntimeException('Too many survey submissions were received from this network. Please try again later.', 429);
    }
}

function assertSurveyFormSession(mysqli $conn, int $invitationId, string $rawSessionToken): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{40,160}$/', $rawSessionToken)) {
        throw new RuntimeException('Your survey session is invalid. Please reopen the survey link.', 419);
    }

    $sessionHash = surveyHash($rawSessionToken);
    $ipHash = surveyIpHash();
    $session = dbFetchOne(
        $conn,
        "SELECT * FROM client_survey_form_sessions
         WHERE invitation_id = ? AND session_token_hash = ? AND ip_hash = ?
         LIMIT 1 FOR UPDATE",
        'iss',
        [$invitationId, $sessionHash, $ipHash]
    );

    if (!$session || !empty($session['used_at'])) {
        throw new RuntimeException('This survey session has already been used or is invalid.', 409);
    }
    if (strtotime((string) $session['expires_at']) < time()) {
        throw new RuntimeException('Your survey session has expired. Please reopen the survey link.', 419);
    }

    $minSeconds = max(3, min((int) envString('SURVEY_MIN_COMPLETION_SECONDS', '8'), 60));
    $elapsed = time() - strtotime((string) $session['created_at']);
    if ($elapsed < $minSeconds) {
        throw new RuntimeException('The survey was submitted too quickly. Please review your answers and try again.', 422);
    }

    return $session;
}

function verifySurveyTurnstile(array $payload): void
{
    if (!envBool('TURNSTILE_ENABLED', false)) {
        return;
    }

    $secret = envString('TURNSTILE_SECRET_KEY');
    $token = cleanString($payload['turnstile_token'] ?? '');
    if ($secret === '' || $token === '') {
        throw new RuntimeException('Please complete the anti-spam verification.', 422);
    }

    $postFields = http_build_query([
        'secret' => $secret,
        'response' => $token,
        'remoteip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);

    $responseBody = false;
    if (function_exists('curl_init')) {
        $curl = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $responseBody = curl_exec($curl);
        curl_close($curl);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $postFields,
                'timeout' => 8,
            ],
        ]);
        $responseBody = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
    }

    $result = is_string($responseBody) ? json_decode($responseBody, true) : null;
    if (!is_array($result) || empty($result['success'])) {
        throw new RuntimeException('Anti-spam verification failed. Please try again.', 422);
    }
}

function surveyRatingField(array $payload, string $field, string $label): string
{
    $value = cleanString($payload[$field] ?? '');
    if (!in_array($value, CLIENT_SURVEY_RATINGS, true)) {
        throw new RuntimeException("Please rate {$label}.", 422);
    }
    return $value;
}

function normalizePublicSurveyPayload(array $payload): array
{
    if (cleanString($payload['website'] ?? '') !== '' || cleanString($payload['company_url'] ?? '') !== '') {
        throw new RuntimeException('Unable to accept this submission.', 422);
    }

    $ratings = [
        'quality' => surveyRatingField($payload, 'quality', 'quality of construction work'),
        'timeline' => surveyRatingField($payload, 'timeline', 'timeline accuracy'),
        'expertise' => surveyRatingField($payload, 'expertise', 'experience and expertise'),
        'communication' => surveyRatingField($payload, 'communication', 'communication'),
        'resolution' => surveyRatingField($payload, 'resolution', 'problem resolution'),
        'cleaniness' => surveyRatingField($payload, 'cleaniness', 'jobsite cleanliness'),
        'safety' => surveyRatingField($payload, 'safety', 'jobsite safety'),
        'response' => surveyRatingField($payload, 'response', 'response to complaints'),
    ];

    $scores = array_map(static fn (string $rating): int => CLIENT_SURVEY_RATING_SCORES[$rating], array_values($ratings));
    $overallScore = round(array_sum($scores) / count($scores), 2);

    return array_merge($ratings, [
        'quality_comments' => optionalStringField($payload, 'quality_comments', 3000),
        'timeline_comments' => optionalStringField($payload, 'timeline_comments', 3000),
        'expertise_comments' => optionalStringField($payload, 'expertise_comments', 3000),
        'communication_comments' => optionalStringField($payload, 'communication_comments', 3000),
        'resolution_comments' => optionalStringField($payload, 'resolution_comments', 3000),
        'cleaniness_comments' => optionalStringField($payload, 'cleaniness_comments', 3000),
        'safety_comments' => optionalStringField($payload, 'safety_comments', 3000),
        'response_comments' => optionalStringField($payload, 'response_comments', 3000),
        'electrical_services' => requireStringField($payload, 'electrical_services', 'Electrical services feedback', 5000),
        'mechanical_services' => requireStringField($payload, 'mechanical_services', 'Mechanical services feedback', 5000),
        'filled_by' => requireStringField($payload, 'filled_by', 'Full name', 255),
        'position' => optionalStringField($payload, 'position', 255),
        'office_address' => optionalStringField($payload, 'office_address', 1000),
        'phone_number' => optionalStringField($payload, 'phone_number', 80),
        'fax_number' => optionalStringField($payload, 'fax_number', 80),
        'project_title' => requireStringField($payload, 'project_title', 'Project title', 255),
        'company' => requireStringField($payload, 'company', 'Company name', 255),
        'location' => requireStringField($payload, 'location', 'Project location', 1000),
        'email' => (function () use ($payload): string {
            $email = requireStringField($payload, 'email', 'Email address', 255);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email address.', 422);
            }
            return $email;
        })(),
        'overall_score' => $overallScore,
    ]);
}

function surveyScoreLabel(float $score): string
{
    if ($score >= 87.5) return 'Excellent';
    if ($score >= 62.5) return 'Good';
    if ($score >= 37.5) return 'Average';
    return 'Poor';
}

function surveySelectSql(): string
{
    return "SELECT s.*, c.clients_name AS linked_client_name, c.clients_category,
            p.project_title AS linked_project_title, p.tender_code AS linked_project_code,
            i.recipient_name AS invited_recipient_name, i.recipient_email AS invited_recipient_email,
            NULLIF(TRIM(CONCAT(COALESCE(owner.first_name, ''), ' ', COALESCE(owner.last_name, ''))), '') AS owner_pms_admin_name,
            NULLIF(TRIM(CONCAT(COALESCE(reviewer.first_name, ''), ' ', COALESCE(reviewer.last_name, ''))), '') AS reviewed_by_name
     FROM clients_survey_form s
     LEFT JOIN clients_table c ON c.id = s.client_id
     LEFT JOIN project_info_table p ON p.id = s.project_id
     LEFT JOIN client_survey_invitations i ON i.id = s.invitation_id
     LEFT JOIN users owner ON owner.id = s.owner_pms_admin_id
     LEFT JOIN users reviewer ON reviewer.id = s.reviewed_by";
}

function castSurveyRecord(array $record): array
{
    foreach (['id', 'invitation_id', 'client_id', 'project_id', 'owner_pms_admin_id', 'reviewed_by', 'deleted_by'] as $field) {
        $record[$field] = isset($record[$field]) && $record[$field] !== null ? (int) $record[$field] : null;
    }
    $record['overall_score'] = isset($record['overall_score']) ? (float) $record['overall_score'] : 0.0;
    $record['score_label'] = surveyScoreLabel((float) $record['overall_score']);
    return $record;
}

function assertSurveyAccessible(mysqli $conn, array $authUser, int $id): array
{
    $record = dbFetchOne(
        $conn,
        surveySelectSql() . ' WHERE s.id = ? AND s.deleted_at IS NULL LIMIT 1',
        'i',
        [$id]
    );
    if (!$record) {
        throw new RuntimeException('Client survey response not found.', 404);
    }
    return castSurveyRecord($record);
}

function invitationSelectSql(): string
{
    return "SELECT i.id, i.client_id, i.project_id, i.recipient_name, i.recipient_email,
            i.client_name_snapshot, i.project_title_snapshot, i.owner_pms_admin_id,
            i.expires_at, i.max_submissions, i.submission_count, i.status, i.last_accessed_at,
            i.created_by, i.created_at, i.updated_at,
            c.clients_name AS linked_client_name, p.project_title AS linked_project_title,
            p.tender_code AS linked_project_code,
            NULLIF(TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))), '') AS created_by_name,
            NULLIF(TRIM(CONCAT(COALESCE(owner.first_name, ''), ' ', COALESCE(owner.last_name, ''))), '') AS owner_pms_admin_name
     FROM client_survey_invitations i
     LEFT JOIN clients_table c ON c.id = i.client_id
     LEFT JOIN project_info_table p ON p.id = i.project_id
     LEFT JOIN users creator ON creator.id = i.created_by
     LEFT JOIN users owner ON owner.id = i.owner_pms_admin_id";
}

function castSurveyInvitation(array $row): array
{
    foreach (['id', 'client_id', 'project_id', 'owner_pms_admin_id', 'created_by', 'max_submissions', 'submission_count'] as $field) {
        $row[$field] = isset($row[$field]) && $row[$field] !== null ? (int) $row[$field] : null;
    }
    $row['effective_status'] = surveyInvitationStatus($row);
    return $row;
}
