<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/surveys.php';

requireMethod('POST');
$authUser = authenticateUser();
requirePermission($conn, $authUser, 'client_surveys.delete');
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Survey link ID');

// A second, typed confirmation protects against accidental bulk destruction.
if (($payload['confirm'] ?? null) !== true || ($payload['confirmation_text'] ?? null) !== 'DELETE') {
    throw new RuntimeException('Type DELETE to confirm permanent removal of the link and its responses.', 422);
}
foreach (['expected_responses', 'expected_recipients'] as $field) {
    if (!isset($payload[$field]) || filter_var($payload[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
        throw new RuntimeException('Refresh this survey link and confirm the latest response and recipient counts.', 422);
    }
}
$expectedResponses = (int) $payload['expected_responses'];
$expectedRecipients = (int) $payload['expected_recipients'];

$conn->begin_transaction();
try {
    // Public submissions lock this invitation before inserting a response.
    // This lock serializes deletion with submissions and prevents post-count races.
    $invitation = dbFetchOne($conn,
        'SELECT id, link_scope, recipient_name, client_name_snapshot, project_title_snapshot, status
         FROM client_survey_invitations WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$id]);
    if (!$invitation) throw new RuntimeException('Survey link not found. It may already have been deleted.', 404);

    $activeResponses = dbScalarInt($conn,
        'SELECT COUNT(*) AS total FROM clients_survey_form WHERE invitation_id = ? AND deleted_at IS NULL', 'i', [$id]);
    $allResponses = dbScalarInt($conn,
        'SELECT COUNT(*) AS total FROM clients_survey_form WHERE invitation_id = ?', 'i', [$id]);
    // Lock recipient rows so no concurrent sender can newly claim the queued email.
    $recipients = dbFetchAll($conn,
        'SELECT id, mail_status FROM client_survey_invitation_recipients WHERE invitation_id = ? FOR UPDATE',
        'i', [$id]);
    $recipientCount = count($recipients);
    if ($activeResponses !== $expectedResponses || $recipientCount !== $expectedRecipients) {
        throw new RuntimeException('The response or recipient count has changed. Refresh the link and confirm again.', 409);
    }
    foreach ($recipients as $recipient) {
        if ($recipient['mail_status'] === 'processing') {
            throw new RuntimeException('An invitation email is currently being sent. Retry deletion after sending finishes.', 409);
        }
    }
    $sessionCount = dbScalarInt($conn,
        'SELECT COUNT(*) AS total FROM client_survey_form_sessions WHERE invitation_id = ?', 'i', [$id]);

    // Session rows can refer to response IDs. Delete them before either parent.
    foreach ([
        ['DELETE FROM client_survey_form_sessions WHERE invitation_id = ?', 'i'],
        ['DELETE FROM clients_survey_form WHERE invitation_id = ?', 'i'],
        ['DELETE FROM client_survey_invitation_recipients WHERE invitation_id = ?', 'i'],
        ['DELETE FROM client_survey_invitations WHERE id = ?', 'i'],
    ] as [$sql, $types]) {
        $stmt = dbExecute($conn, $sql, $types, [$id]);
        $stmt->close();
    }

    // Audit insert is inside the transaction. Unlike the best-effort audit helper,
    // failure here aborts the destructive operation rather than losing its audit trail.
    $metadata = json_encode([
        'link_scope' => $invitation['link_scope'],
        'client' => $invitation['client_name_snapshot'],
        'project' => $invitation['project_title_snapshot'],
        'active_responses_removed' => $activeResponses,
        'total_responses_removed' => $allResponses,
        'sessions_removed' => $sessionCount,
        'recipients_removed' => $recipientCount,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $stmt = dbExecute($conn,
        'INSERT INTO audit_logs (actor_user_id, actor_role, action, entity_type, entity_id, metadata, ip_hash)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        'issssss', [
            (int) $authUser['id'], (string) ($authUser['role'] ?? ''),
            'client_surveys.invitation_deleted_with_responses', 'client_survey_invitation',
            (string) $id, $metadata, hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')),
        ]);
    $stmt->close();
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Survey link, responses, sessions and email recipient records deleted successfully.',
    'data' => [
        'id' => $id, 'responses_removed' => $allResponses,
        'visible_responses_removed' => $activeResponses,
        'sessions_removed' => $sessionCount, 'recipients_removed' => $recipientCount,
    ],
]);
