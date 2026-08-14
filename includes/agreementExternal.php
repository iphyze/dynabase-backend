<?php
declare(strict_types=1);

require_once __DIR__ . '/agreements.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/audit.php';

const DYNABASE_AGREEMENT_EXTERNAL_ACCESS_MODES = ['open', 'controlled'];
const DYNABASE_AGREEMENT_EXTERNAL_STATUSES = ['active', 'revoked'];

function agreementExternalEditableFieldDefinitions(): array
{
    return [
        'project_subject' => ['label' => 'Project / Subject', 'type' => 'text', 'required' => false, 'max' => 500],
        'client_company' => ['label' => 'Client / Company', 'type' => 'text', 'required' => true, 'max' => 255],
        'counterparty_contact_person' => ['label' => 'Counter Party Contact Person', 'type' => 'text', 'required' => true, 'max' => 255],
        'date_received' => ['label' => 'Date Received', 'type' => 'date', 'required' => false],
        'client_signatory' => ['label' => 'Client Signatory', 'type' => 'text', 'required' => false, 'max' => 255],
        'effective_date' => ['label' => 'Effective Date', 'type' => 'date', 'required' => false],
        'expiry_date' => ['label' => 'Expiry Date', 'type' => 'date', 'required' => false],
        'duration' => ['label' => 'Duration', 'type' => 'text', 'required' => true, 'max' => 255],
        'renewal' => ['label' => 'Renewal', 'type' => 'select', 'required' => true, 'options' => DYNABASE_AGREEMENT_RENEWAL_OPTIONS],
        'purpose' => ['label' => 'Purpose', 'type' => 'textarea', 'required' => true, 'max' => 5000],
        'department' => ['label' => 'Department', 'type' => 'text', 'required' => true, 'max' => 255],
        'remark' => ['label' => 'Remark', 'type' => 'textarea', 'required' => false, 'max' => 5000],
    ];
}

function agreementExternalEditableFieldOptions(): array
{
    $options = [];
    foreach (agreementExternalEditableFieldDefinitions() as $key => $definition) {
        $options[] = ['key' => $key] + $definition;
    }
    return $options;
}

function normaliseAgreementExternalEditableFields(mixed $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [$value];
    }
    if (!is_array($value)) {
        throw new RuntimeException('Choose at least one client-editable field.', 422);
    }

    $allowed = agreementExternalEditableFieldDefinitions();
    $fields = [];
    foreach ($value as $candidate) {
        $key = cleanString($candidate);
        if ($key === '') {
            continue;
        }
        if (!isset($allowed[$key])) {
            throw new RuntimeException('An unsupported client-editable field was selected.', 422);
        }
        $fields[$key] = true;
    }

    $fields = array_keys($fields);
    if ($fields === []) {
        throw new RuntimeException('Choose at least one client-editable field.', 422);
    }
    return $fields;
}

function agreementExternalSecret(): string
{
    return hash_hmac('sha256', 'dynabase-agreement-external-workspace-v1', jwtSecret(), true);
}

function agreementExternalHash(string $value): string
{
    return hash_hmac('sha256', $value, agreementExternalSecret());
}

function agreementExternalIpHash(): string
{
    return agreementExternalHash((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function agreementExternalUserAgentHash(): string
{
    return agreementExternalHash(substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512));
}

function agreementExternalRandomToken(int $bytes = 48): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function agreementExternalEncryptToken(string $rawToken): string
{
    $key = hash_hmac('sha256', 'dynabase-agreement-external-token-encryption-v1', jwtSecret(), true);
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $rawToken,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'dynabase-agreement-external-token-v1'
    );
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('Unable to protect the agreement workspace token.', 500);
    }
    return rtrim(strtr(base64_encode($iv . $tag . $ciphertext), '+/', '-_'), '=');
}

function agreementExternalDecryptToken(mixed $encrypted): ?string
{
    $encoded = trim((string) $encrypted);
    if ($encoded === '') {
        return null;
    }
    $padding = strlen($encoded) % 4;
    if ($padding > 0) {
        $encoded .= str_repeat('=', 4 - $padding);
    }
    $packed = base64_decode(strtr($encoded, '-_', '+/'), true);
    if (!is_string($packed) || strlen($packed) <= 28) {
        return null;
    }
    $iv = substr($packed, 0, 12);
    $tag = substr($packed, 12, 16);
    $ciphertext = substr($packed, 28);
    $key = hash_hmac('sha256', 'dynabase-agreement-external-token-encryption-v1', jwtSecret(), true);
    $raw = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'dynabase-agreement-external-token-v1'
    );
    if (!is_string($raw) || !preg_match('/^[A-Za-z0-9_-]{48,160}$/', $raw)) {
        return null;
    }
    return $raw;
}

function agreementExternalFrontendUrl(string $rawToken): string
{
    $base = rtrim(envString('FRONTEND_URL', allowedFrontendOrigins()[0] ?? 'http://localhost:5173'), '/');
    return $base . '/agreement-workspace/' . rawurlencode($rawToken);
}

function agreementExternalTablesExist(mysqli $conn): bool
{
    $rows = dbFetchAll(
        $conn,
        "SELECT TABLE_NAME AS table_name
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('agreement_external_links','agreement_external_sessions','agreement_external_access_attempts','agreement_external_submissions')"
    );
    $available = array_unique(array_map(static fn (array $row): string => strtolower((string) $row['table_name']), $rows));
    return count($available) === 4;
}

function assertAgreementExternalSchema(mysqli $conn): void
{
    if (!agreementExternalTablesExist($conn)) {
        throw new RuntimeException('Agreement external sharing is not installed. Apply the Agreement External Workspace migration and try again.', 409);
    }
}

function cleanupAgreementExternalSecurityArtifacts(mysqli $conn, bool $force = false): void
{
    if (!agreementExternalTablesExist($conn)) {
        return;
    }
    if (!$force) {
        try {
            if (random_int(1, 40) !== 1) {
                return;
            }
        } catch (Throwable) {
            return;
        }
    }

    try {
        dbExecute(
            $conn,
            "DELETE FROM agreement_external_sessions
             WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
                OR (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(), INTERVAL 7 DAY))"
        )->close();
        dbExecute(
            $conn,
            "DELETE FROM agreement_external_access_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->close();
    } catch (Throwable $exception) {
        error_log('[Dynabase Agreement External] Cleanup failed: ' . $exception->getMessage());
    }
}

function normaliseAgreementExternalAccessMode(mixed $value): string
{
    $mode = strtolower(cleanString($value));
    if ($mode === 'password') {
        $mode = 'controlled';
    }
    if (!in_array($mode, DYNABASE_AGREEMENT_EXTERNAL_ACCESS_MODES, true)) {
        throw new RuntimeException('Invalid agreement workspace access mode.', 422);
    }
    return $mode;
}

function normaliseAgreementExternalPassword(mixed $value, bool $required): ?string
{
    $password = trim((string) $value);
    if ($password === '') {
        if ($required) {
            throw new RuntimeException('A password is required for protected agreement links.', 422);
        }
        return null;
    }
    if (strlen($password) < 8 || strlen($password) > 128) {
        throw new RuntimeException('Agreement-link passwords must be between 8 and 128 characters.', 422);
    }
    return password_hash($password, PASSWORD_DEFAULT);
}

function normaliseAgreementExternalLinkName(mixed $value): string
{
    $name = cleanString($value);
    if (strlen($name) > 255) {
        throw new RuntimeException('Link name must not exceed 255 characters.', 422);
    }
    return $name;
}

function normaliseAgreementExternalExpiry(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        throw new RuntimeException('Link expiry is required.', 422);
    }
    $raw = str_replace('T', ' ', $raw);
    if (strlen($raw) === 16) {
        $raw .= ':00';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw);
    if (!$date || $date->format('Y-m-d H:i:s') !== $raw) {
        throw new RuntimeException('Link expiry must be a valid date and time.', 422);
    }
    $now = new DateTimeImmutable('now');
    if ($date <= $now) {
        throw new RuntimeException('Link expiry must be in the future.', 422);
    }
    if ($date > $now->modify('+1 year')) {
        throw new RuntimeException('Agreement links may be created for a maximum of one year.', 422);
    }
    return $date->format('Y-m-d H:i:s');
}

function agreementExternalEffectiveStatus(array $share): string
{
    if (($share['status'] ?? '') === 'revoked') {
        return 'revoked';
    }
    if (strtotime((string) ($share['expires_at'] ?? '')) <= time()) {
        return 'expired';
    }
    return 'active';
}

function fetchAgreementExternalShareById(mysqli $conn, int $shareId): ?array
{
    return dbFetchOne(
        $conn,
        "SELECT s.*,
                a.document_ref_no, a.document_ref_type, a.project_subject, a.client_company,
                a.counterparty_contact_person, a.status AS agreement_status, a.record_status AS agreement_record_status,
                a.linked_document_id, d.document_title AS linked_document_title,
                NULLIF(TRIM(CONCAT(COALESCE(creator.first_name,''),' ',COALESCE(creator.last_name,''))), '') AS creator_name,
                creator.email AS creator_email,
                NULLIF(TRIM(CONCAT(COALESCE(revoker.first_name,''),' ',COALESCE(revoker.last_name,''))), '') AS revoker_name
         FROM agreement_external_links s
         INNER JOIN agreement_registers a ON a.id = s.agreement_id
         LEFT JOIN document_table d ON d.id = a.linked_document_id
         LEFT JOIN users creator ON creator.id = s.created_by_id
         LEFT JOIN users revoker ON revoker.id = s.revoked_by_id
         WHERE s.id = ? LIMIT 1",
        'i',
        [$shareId]
    );
}

function assertAgreementExternalAdminAccessible(mysqli $conn, array $authUser, int $shareId): array
{
    $share = fetchAgreementExternalShareById($conn, $shareId);
    if (!$share) {
        throw new RuntimeException('Agreement workspace link not found.', 404);
    }
    assertAgreementAccessible($conn, $authUser, (int) $share['agreement_id']);
    return $share;
}

function assertAgreementExternalTokenFormat(string $rawToken): void
{
    if (!preg_match('/^[A-Za-z0-9_-]{48,160}$/', $rawToken)) {
        throw new RuntimeException('This agreement workspace link is invalid or no longer available.', 404);
    }
}

function assertPublicAgreementExternalAvailable(mysqli $conn, string $rawToken, bool $lock = false): array
{
    cleanupAgreementExternalSecurityArtifacts($conn);
    assertAgreementExternalTokenFormat($rawToken);
    $lockSql = $lock ? ' FOR UPDATE' : '';
    $share = dbFetchOne(
        $conn,
        "SELECT s.*,
                a.document_ref_no, a.document_ref_type, a.project_subject, a.client_company,
                a.counterparty_contact_person, a.date_received, a.client_signatory, a.effective_date,
                a.expiry_date, a.duration, a.renewal, a.purpose, a.department, a.remark,
                a.status AS agreement_status, a.record_status AS agreement_record_status,
                a.linked_document_id, d.document_title AS linked_document_title, d.status AS linked_document_status
         FROM agreement_external_links s
         INNER JOIN agreement_registers a ON a.id = s.agreement_id
         LEFT JOIN document_table d ON d.id = a.linked_document_id
         WHERE s.token_hash = ? LIMIT 1{$lockSql}",
        's',
        [agreementExternalHash($rawToken)]
    );

    if (!$share || ($share['agreement_record_status'] ?? '') !== 'active') {
        throw new RuntimeException('This agreement workspace link is invalid or no longer available.', 404);
    }
    $effective = agreementExternalEffectiveStatus($share);
    if ($effective !== 'active') {
        throw new RuntimeException($effective === 'expired' ? 'This agreement workspace link has expired.' : 'This agreement workspace link is no longer active.', 410);
    }
    $share['effective_status'] = $effective;
    return $share;
}

function agreementExternalSessionTtlMinutes(): int
{
    return max(15, min((int) envString('AGREEMENT_EXTERNAL_SESSION_MINUTES', '120'), 720));
}

function createAgreementExternalSession(mysqli $conn, array $share): array
{
    $rawToken = agreementExternalRandomToken(36);
    $expiresUnix = min(
        strtotime((string) $share['expires_at']),
        time() + (agreementExternalSessionTtlMinutes() * 60)
    );
    $expiresAt = date('Y-m-d H:i:s', $expiresUnix);
    $stmt = dbExecute(
        $conn,
        'INSERT INTO agreement_external_sessions
            (share_link_id, session_token_hash, ip_hash, user_agent_hash, expires_at)
         VALUES (?, ?, ?, ?, ?)',
        'issss',
        [(int) $share['id'], agreementExternalHash($rawToken), agreementExternalIpHash(), agreementExternalUserAgentHash(), $expiresAt]
    );
    $sessionId = (int) $stmt->insert_id;
    $stmt->close();
    return ['id' => $sessionId, 'token' => $rawToken, 'expires_at' => $expiresAt];
}

function assertAgreementExternalSession(mysqli $conn, int $shareId, string $rawSessionToken): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{40,160}$/', $rawSessionToken)) {
        throw new RuntimeException('This protected-access session is invalid. Enter the link password again.', 401);
    }
    $session = dbFetchOne(
        $conn,
        "SELECT * FROM agreement_external_sessions
         WHERE share_link_id = ? AND session_token_hash = ? AND ip_hash = ? AND user_agent_hash = ?
           AND revoked_at IS NULL LIMIT 1",
        'isss',
        [$shareId, agreementExternalHash($rawSessionToken), agreementExternalIpHash(), agreementExternalUserAgentHash()]
    );
    if (!$session || strtotime((string) $session['expires_at']) <= time()) {
        throw new RuntimeException('This protected-access session has expired. Enter the link password again.', 401);
    }
    dbExecute($conn, 'UPDATE agreement_external_sessions SET last_accessed_at = CURRENT_TIMESTAMP WHERE id = ?', 'i', [(int) $session['id']])->close();
    return $session;
}

function assertAgreementExternalAccess(mysqli $conn, array $share, ?string $rawSessionToken): void
{
    if (($share['access_mode'] ?? '') !== 'controlled') {
        return;
    }
    assertAgreementExternalSession($conn, (int) $share['id'], trim((string) $rawSessionToken));
}

function assertAgreementExternalPasswordRateLimit(mysqli $conn, int $shareId): void
{
    $ipHash = agreementExternalIpHash();
    $perLink = dbScalarInt(
        $conn,
        "SELECT COUNT(*) AS total FROM agreement_external_access_attempts
         WHERE share_link_id = ? AND ip_hash = ? AND outcome = 'denied'
           AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        'is',
        [$shareId, $ipHash]
    );
    $maximum = max(3, min((int) envString('AGREEMENT_EXTERNAL_MAX_PASSWORD_ATTEMPTS_PER_HOUR', '8'), 25));
    if ($perLink >= $maximum) {
        throw new RuntimeException('Too many incorrect password attempts. Please try again later.', 429);
    }
}

function recordAgreementExternalPasswordAttempt(mysqli $conn, array $share, string $outcome): void
{
    dbExecute(
        $conn,
        'INSERT INTO agreement_external_access_attempts (share_link_id, token_hash, ip_hash, outcome) VALUES (?, ?, ?, ?)',
        'isss',
        [(int) $share['id'], (string) $share['token_hash'], agreementExternalIpHash(), $outcome]
    )->close();
}

function touchAgreementExternalLink(mysqli $conn, int $shareId, string $event = 'access'): void
{
    $increment = match ($event) {
        'submission' => ', submission_count = submission_count + 1, last_submitted_at = CURRENT_TIMESTAMP',
        'download' => ', download_count = download_count + 1',
        'upload' => ', upload_count = upload_count + 1, last_submitted_at = CURRENT_TIMESTAMP',
        default => '',
    };
    dbExecute(
        $conn,
        "UPDATE agreement_external_links
         SET access_count = access_count + 1{$increment}, last_accessed_at = CURRENT_TIMESTAMP, updated_at = updated_at
         WHERE id = ? AND status = 'active'",
        'i',
        [$shareId]
    )->close();
}

function agreementExternalCurrentRevision(mysqli $conn, array $share): ?array
{
    $documentId = (int) ($share['linked_document_id'] ?? 0);
    if ($documentId <= 0 || ($share['linked_document_status'] ?? 'active') !== 'active') {
        return null;
    }
    $revision = dbFetchOne(
        $conn,
        "SELECT * FROM document_revisions
         WHERE document_id = ? AND record_status = 'active' AND is_current = 1
         ORDER BY id DESC LIMIT 1",
        'i',
        [$documentId]
    );
    return $revision ?: null;
}

function agreementExternalPublicPayload(mysqli $conn, array $share): array
{
    $decodedFields = json_decode((string) ($share['editable_fields'] ?? '[]'), true);
    $editableFields = is_array($decodedFields) ? array_values($decodedFields) : [];
    $definitions = agreementExternalEditableFieldDefinitions();
    $fields = [];
    foreach ($editableFields as $key) {
        if (!isset($definitions[$key])) {
            continue;
        }
        $fields[] = ['key' => $key, 'value' => $share[$key] ?? null] + $definitions[$key];
    }

    $revision = agreementExternalCurrentRevision($conn, $share);
    return [
        'agreement' => [
            'reference' => $share['document_ref_no'],
            'type' => $share['document_ref_type'],
            'project_subject' => $share['project_subject'] ?? '',
            'client_company' => $share['client_company'] ?? '',
            'counterparty_contact_person' => $share['counterparty_contact_person'] ?? '',
            'status' => $share['agreement_status'] ?? '',
        ],
        'fields' => $fields,
        'document' => $revision ? [
            'available' => true,
            'title' => $share['linked_document_title'] ?: $share['document_ref_no'],
            'revision_code' => $revision['revision_code'],
            'original_name' => $revision['original_name'],
            'file_size' => (int) $revision['file_size'],
            'uploaded_at' => $revision['uploaded_at'],
        ] : ['available' => false],
        'share' => [
            'link_name' => $share['link_name'] ?? '',
            'access_mode' => $share['access_mode'],
            'requires_password' => $share['access_mode'] === 'controlled',
            'allow_document_download' => (bool) $share['allow_document_download'],
            'allow_document_upload' => (bool) $share['allow_document_upload'],
            'expires_at' => $share['expires_at'],
        ],
    ];
}

function agreementExternalAdminPayload(array $share): array
{
    $fields = json_decode((string) ($share['editable_fields'] ?? '[]'), true);
    $token = agreementExternalDecryptToken($share['token_ciphertext'] ?? null);
    return [
        'id' => (int) $share['id'],
        'agreement_id' => (int) $share['agreement_id'],
        'link_name' => $share['link_name'] ?? '',
        'access_mode' => $share['access_mode'],
        'requires_password' => $share['access_mode'] === 'controlled',
        'editable_fields' => is_array($fields) ? array_values($fields) : [],
        'allow_document_download' => (bool) $share['allow_document_download'],
        'allow_document_upload' => (bool) $share['allow_document_upload'],
        'expires_at' => $share['expires_at'],
        'status' => agreementExternalEffectiveStatus($share),
        'stored_status' => $share['status'],
        'access_count' => (int) $share['access_count'],
        'submission_count' => (int) $share['submission_count'],
        'download_count' => (int) $share['download_count'],
        'upload_count' => (int) $share['upload_count'],
        'last_accessed_at' => $share['last_accessed_at'],
        'last_submitted_at' => $share['last_submitted_at'],
        'created_at' => $share['created_at'],
        'creator_name' => $share['creator_name'] ?? $share['created_by'] ?? '',
        'revoked_at' => $share['revoked_at'],
        'revoker_name' => $share['revoker_name'] ?? '',
        'share_url' => $token ? agreementExternalFrontendUrl($token) : null,
    ];
}

function normaliseAgreementExternalSubmittedFields(array $share, mixed $rawFields): array
{
    if (!is_array($rawFields)) {
        throw new RuntimeException('Agreement details are required.', 422);
    }
    $editable = json_decode((string) ($share['editable_fields'] ?? '[]'), true);
    $editable = is_array($editable) ? array_values($editable) : [];
    $definitions = agreementExternalEditableFieldDefinitions();
    $result = [];

    foreach ($rawFields as $key => $_value) {
        if (!in_array((string) $key, $editable, true)) {
            throw new RuntimeException('A submitted field is not enabled for this client workspace.', 422);
        }
    }

    foreach ($editable as $key) {
        if (!isset($definitions[$key])) {
            continue;
        }
        $definition = $definitions[$key];
        $value = array_key_exists($key, $rawFields) ? $rawFields[$key] : ($share[$key] ?? '');
        if ($definition['type'] === 'date') {
            $clean = cleanString($value);
            if ($clean === '') {
                $result[$key] = null;
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $clean);
            if (!$date || $date->format('Y-m-d') !== $clean) {
                throw new RuntimeException($definition['label'] . ' must be a valid date.', 422);
            }
            $result[$key] = $clean;
            continue;
        }
        if ($definition['type'] === 'select') {
            $clean = cleanString($value);
            if (!in_array($clean, $definition['options'] ?? [], true)) {
                throw new RuntimeException('Invalid ' . $definition['label'] . '.', 422);
            }
            $result[$key] = $clean;
            continue;
        }

        $clean = cleanString($value);
        if (($definition['required'] ?? false) && $clean === '') {
            throw new RuntimeException($definition['label'] . ' is required.', 422);
        }
        $max = (int) ($definition['max'] ?? 5000);
        $length = function_exists('mb_strlen') ? mb_strlen($clean) : strlen($clean);
        if ($length > $max) {
            throw new RuntimeException($definition['label'] . " must not exceed {$max} characters.", 422);
        }
        $result[$key] = $clean;
    }

    $effective = array_key_exists('effective_date', $result) ? $result['effective_date'] : ($share['effective_date'] ?? null);
    $expiry = array_key_exists('expiry_date', $result) ? $result['expiry_date'] : ($share['expiry_date'] ?? null);
    if ($effective && $expiry && $expiry < $effective) {
        throw new RuntimeException('Expiry Date cannot be earlier than Effective Date.', 422);
    }
    return $result;
}

function applyAgreementExternalSubmittedFields(mysqli $conn, array $share, array $fields): array
{
    if ($fields === []) {
        return ['fields' => [], 'changes' => []];
    }

    $sets = [];
    $types = '';
    $params = [];
    $changes = [];
    foreach ($fields as $key => $value) {
        $sets[] = "{$key} = ?";
        $types .= 's';
        $params[] = $value;
        $before = $share[$key] ?? null;
        if ((string) ($before ?? '') !== (string) ($value ?? '')) {
            $changes[$key] = ['before' => $before, 'after' => $value];
        }
    }

    if (array_key_exists('client_company', $fields) && isset($changes['client_company'])) {
        $sets[] = 'client_id = NULL';
    }
    if (array_key_exists('counterparty_contact_person', $fields) && isset($changes['counterparty_contact_person'])) {
        $sets[] = 'counterparty_keyperson_id = NULL';
    }
    $sets[] = 'updated_by = ?';
    $types .= 's';
    $params[] = 'external-client-workspace';
    $sets[] = 'updated_by_id = NULL';
    $sets[] = 'updated_at = CURRENT_TIMESTAMP';
    $types .= 'i';
    $params[] = (int) $share['agreement_id'];

    dbExecute(
        $conn,
        'UPDATE agreement_registers SET ' . implode(', ', $sets) . " WHERE id = ? AND record_status = 'active'",
        $types,
        $params
    )->close();

    return ['fields' => $fields, 'changes' => $changes];
}

function recordAgreementExternalSubmission(
    mysqli $conn,
    array $share,
    string $type,
    ?array $submittedFields = null,
    ?int $revisionId = null,
    ?string $originalName = null
): int {
    $json = $submittedFields !== null
        ? json_encode($submittedFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : null;
    if ($submittedFields !== null && $json === false) {
        throw new RuntimeException('Unable to preserve the submitted agreement details.', 500);
    }
    $stmt = dbExecute(
        $conn,
        'INSERT INTO agreement_external_submissions
            (share_link_id, agreement_id, submission_type, submitted_fields, document_revision_id, original_name, ip_hash, user_agent_hash)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        'iississs',
        [
            (int) $share['id'],
            (int) $share['agreement_id'],
            $type,
            $json,
            $revisionId,
            $originalName,
            agreementExternalIpHash(),
            agreementExternalUserAgentHash(),
        ]
    );
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function agreementExternalRecentSubmissions(mysqli $conn, int $agreementId, int $limit = 40): array
{
    $limit = max(1, min($limit, 100));
    $rows = dbFetchAll(
        $conn,
        "SELECT sub.id, sub.share_link_id, sub.submission_type, sub.submitted_fields,
                sub.document_revision_id, sub.original_name, sub.created_at,
                link.link_name, revision.revision_code
         FROM agreement_external_submissions sub
         INNER JOIN agreement_external_links link ON link.id = sub.share_link_id
         LEFT JOIN document_revisions revision ON revision.id = sub.document_revision_id
         WHERE sub.agreement_id = ?
         ORDER BY sub.created_at DESC, sub.id DESC
         LIMIT {$limit}",
        'i',
        [$agreementId]
    );
    return array_map(static function (array $row): array {
        $decoded = json_decode((string) ($row['submitted_fields'] ?? ''), true);
        return [
            'id' => (int) $row['id'],
            'share_link_id' => (int) $row['share_link_id'],
            'submission_type' => $row['submission_type'],
            'submitted_fields' => is_array($decoded) ? $decoded : null,
            'document_revision_id' => $row['document_revision_id'] !== null ? (int) $row['document_revision_id'] : null,
            'original_name' => $row['original_name'],
            'revision_code' => $row['revision_code'],
            'link_name' => $row['link_name'],
            'created_at' => $row['created_at'],
        ];
    }, $rows);
}

function insertAgreementExternalDocumentRevision(mysqli $conn, array $share, array $file): array
{
    $documentId = (int) ($share['linked_document_id'] ?? 0);
    if ($documentId <= 0) {
        throw new RuntimeException('No agreement document is currently attached for client re-upload.', 409);
    }

    lockDocumentForRevision($conn, $documentId);
    assertDocumentCurrentRevisionShareDelivery($conn, $documentId, (string) $file['file_extension']);
    $nextRevisionNo = nextDocumentRevisionNo($conn, $documentId);
    $revisionCode = normaliseDocumentRevisionCode('', $nextRevisionNo);
    assertDocumentRevisionCodeAvailable($conn, $documentId, $revisionCode);
    $uploadedBy = 'External client — ' . (string) $share['document_ref_no'];
    $notes = 'Uploaded through Agreement Workspace' . ($share['link_name'] ? ': ' . $share['link_name'] : '');

    dbExecute(
        $conn,
        "UPDATE document_revisions SET is_current = 0 WHERE document_id = ? AND record_status = 'active'",
        'i',
        [$documentId]
    )->close();

    $stmt = dbExecute(
        $conn,
        'INSERT INTO document_revisions
            (document_id, revision_code, revision_no, revision_notes, original_name, stored_name, storage_path,
             mime_type, file_extension, file_size, checksum_sha256, preview_file_path, is_current, record_status,
             replaces_revision_id, uploaded_by_id, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1, "active", NULL, NULL, ?)',
        'isissssssiss',
        [
            $documentId,
            $revisionCode,
            $nextRevisionNo,
            $notes,
            $file['original_name'],
            $file['stored_name'],
            $file['storage_path'],
            $file['mime_type'],
            $file['file_extension'],
            $file['file_size'],
            $file['checksum_sha256'],
            $uploadedBy,
        ]
    );
    $revisionId = (int) $stmt->insert_id;
    $stmt->close();

    dbExecute(
        $conn,
        'UPDATE document_table
         SET document = ?, original_name = ?, storage_path = ?, mime_type = ?, file_extension = ?, file_size = ?,
             checksum_sha256 = ?, version_no = ?, updated_content = ?, updated_by = ?, updated_by_id = NULL,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = "active"',
        'sssssisissi',
        [
            $file['stored_name'],
            $file['original_name'],
            $file['storage_path'],
            $file['mime_type'],
            $file['file_extension'],
            $file['file_size'],
            $file['checksum_sha256'],
            $nextRevisionNo,
            $notes,
            $uploadedBy,
            $documentId,
        ]
    )->close();

    $revision = fetchDocumentRevision($conn, $revisionId, true);
    if (!$revision) {
        throw new RuntimeException('The uploaded agreement revision could not be loaded.', 500);
    }
    return $revision;
}
