<?php
declare(strict_types=1);

require_once __DIR__ . '/surveys.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/audit.php';

/** Validate and deduplicate pasted addresses before any invitation is created. */
function surveyInvitationNormalizeEmails(mixed $raw, string $legacyEmail = ''): array
{
    if (!is_string($raw) && $raw !== null) {
        throw new RuntimeException('Recipient email addresses must be supplied as text.', 422);
    }
    $raw = (string) $raw;
    if (strlen($raw) > 32000) {
        throw new RuntimeException('The recipient list is too long (maximum 32,000 characters).', 422);
    }
    $items = preg_split('/[,\r\n]+/', $legacyEmail !== '' ? $legacyEmail . ',' . $raw : $raw) ?: [];
    $addresses = [];
    $invalid = [];
    foreach ($items as $item) {
        $address = strtolower(trim($item));
        if ($address === '') continue;
        if (strlen($address) > 254 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            $invalid[] = $address;
            continue;
        }
        $addresses[$address] = $address;
        if (count($addresses) > 500) {
            throw new RuntimeException('A survey link supports up to 500 unique email recipients.', 422);
        }
    }
    if ($invalid !== []) {
        throw new RuntimeException('Invalid recipient email address' . (count($invalid) === 1 ? '' : 'es') . ': ' . implode(', ', array_slice($invalid, 0, 3)) . (count($invalid) > 3 ? ' (and others)' : ''), 422);
    }
    return array_values($addresses);
}

/** AES-256-GCM, domain-separated from document/agreement token protection. */
function surveyInvitationEncryptToken(string $rawToken): string
{
    $key = hash_hmac('sha256', 'dynabase-survey-invitation-token-v1', jwtSecret(), true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($rawToken, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'dynabase-survey-invitation-v1');
    if (!is_string($cipher) || strlen($tag) !== 16) {
        throw new RuntimeException('Unable to protect the survey link token.', 500);
    }
    return rtrim(strtr(base64_encode($iv . $tag . $cipher), '+/', '-_'), '=');
}

function surveyInvitationDecryptToken(mixed $encoded): ?string
{
    $encoded = trim((string) $encoded);
    if ($encoded === '') return null;
    $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
    $bytes = base64_decode(strtr($encoded, '-_', '+/'), true);
    if (!is_string($bytes) || strlen($bytes) <= 28) return null;
    $key = hash_hmac('sha256', 'dynabase-survey-invitation-token-v1', jwtSecret(), true);
    $raw = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), 'dynabase-survey-invitation-v1');
    return is_string($raw) && preg_match('/^[A-Za-z0-9_-]{40,160}$/', $raw) ? $raw : null;
}

function surveyInvitationEmailSummary(mysqli $conn, int $invitationId): array
{
    $summary = ['total' => 0, 'not_requested' => 0, 'queued' => 0, 'processing' => 0, 'accepted' => 0, 'failed' => 0, 'uncertain' => 0];
    $rows = dbFetchAll($conn, 'SELECT mail_status, COUNT(*) AS total FROM client_survey_invitation_recipients WHERE invitation_id = ? GROUP BY mail_status', 'i', [$invitationId]);
    foreach ($rows as $row) {
        $status = (string) $row['mail_status'];
        if (array_key_exists($status, $summary)) {
            $summary[$status] = (int) $row['total'];
            $summary['total'] += (int) $row['total'];
        }
    }
    return $summary;
}

/** Persist the intended recipients before starting the sending process. */
function surveyInvitationStoreRecipients(mysqli $conn, int $invitationId, array $emails, string $personalName, bool $sendRequested): void
{
    foreach ($emails as $email) {
        $stmt = dbExecute($conn,
            'INSERT INTO client_survey_invitation_recipients (invitation_id, recipient_email, recipient_name, mail_status) VALUES (?, ?, ?, ?)',
            'isss', [$invitationId, $email, $personalName, $sendRequested ? 'queued' : 'not_requested']
        );
        $stmt->close();
    }
}

/**
 * Sends a bounded group so large recipient lists do not hold a single PHP request open.
 * Atomic queued->processing claims prevent two workers emailing the same address.
 * Processing/uncertain records are never requeued automatically: the message may have been accepted.
 */
function surveyInvitationSendBatch(mysqli $conn, int $invitationId, array $authUser, int $limit = 5, ?int $onlyRecipientId = null): array
{
    $invitation = dbFetchOne($conn, 'SELECT * FROM client_survey_invitations WHERE id = ? LIMIT 1', 'i', [$invitationId]);
    if (!$invitation) throw new RuntimeException('The survey link no longer exists.', 404);
    if (surveyInvitationStatus($invitation) !== 'active') {
        throw new RuntimeException('Invitations can only be sent for active survey links.', 422);
    }
    $rawToken = surveyInvitationDecryptToken($invitation['token_ciphertext'] ?? null);
    if ($rawToken === null || !hash_equals((string) $invitation['token_hash'], surveyHash($rawToken))) {
        throw new RuntimeException('This survey link cannot be recovered securely. Create a replacement link instead.', 422);
    }
    $link = surveyPublicFrontendBaseUrl() . '/client-survey/' . rawurlencode($rawToken);
    $limit = max(1, min($limit, 5));
    $recipientClause = $onlyRecipientId !== null ? ' AND id = ?' : '';
    $rows = dbFetchAll($conn,
        "SELECT id, recipient_email, recipient_name FROM client_survey_invitation_recipients WHERE invitation_id = ? AND mail_status = 'queued'{$recipientClause} ORDER BY id ASC LIMIT ?",
        $onlyRecipientId !== null ? 'iii' : 'ii',
        $onlyRecipientId !== null ? [$invitationId, $onlyRecipientId, $limit] : [$invitationId, $limit]
    );
    foreach ($rows as $row) {
        $recipientId = (int) $row['id'];
        $claimed = dbExecute($conn,
            "UPDATE client_survey_invitation_recipients SET mail_status = 'processing', attempt_count = attempt_count + 1, last_attempted_at = NOW(), last_error = NULL WHERE id = ? AND invitation_id = ? AND mail_status = 'queued'",
            'ii', [$recipientId, $invitationId]
        );
        $wasClaimed = $claimed->affected_rows === 1;
        $claimed->close();
        if (!$wasClaimed) continue;

        $mailStatus = 'uncertain';
        $error = null;
        try {
            $result = sendClientSurveyInvitationEmail([
                'recipient_name' => (string) $row['recipient_name'],
                'recipient_email' => (string) $row['recipient_email'],
                'company' => (string) $invitation['client_name_snapshot'],
                'project_title' => (string) $invitation['project_title_snapshot'],
                'expires_at' => (string) $invitation['expires_at'],
                'link' => $link,
            ]);
            if ($result === true) {
                $mailStatus = 'accepted';
            } else {
                $error = substr((string) $result, 0, 500);
                $mailStatus = str_starts_with((string) $result, 'Email status is uncertain') ? 'uncertain' : 'failed';
            }
        } catch (Throwable $errorThrown) {
            // Do not guess whether a thrown transport error occurred before or after submission.
            $error = 'Unexpected mail transport exception; verify the provider before retrying.';
            error_log('[Dynabase Survey Invitations] ' . $errorThrown->getMessage());
        }
        $updated = dbExecute($conn,
            "UPDATE client_survey_invitation_recipients SET mail_status = ?, last_error = ?, accepted_at = IF(? = 'accepted', NOW(), NULL) WHERE id = ? AND invitation_id = ? AND mail_status = 'processing'",
            'sssii', [$mailStatus, $error, $mailStatus, $recipientId, $invitationId]
        );
        $updated->close();
        writeAuditLog($conn, $authUser, 'client_surveys.invitation_email_attempted', 'client_survey_invitation', $invitationId, [
            'recipient_id' => $recipientId, 'mail_status' => $mailStatus,
        ]);
    }
    return surveyInvitationEmailSummary($conn, $invitationId);
}
