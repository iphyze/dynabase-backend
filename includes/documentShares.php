<?php
declare(strict_types=1);

require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/security.php';

const DYNABASE_DOCUMENT_SHARE_ACCESS_MODES = ['open', 'controlled'];
const DYNABASE_DOCUMENT_SHARE_STATUSES = ['active', 'revoked'];

function documentShareSecret(): string
{
    // Reuse the already-required strong JWT secret so this feature introduces no
    // additional mandatory environment variable.
    return hash_hmac('sha256', 'dynabase-document-sharing-v1', jwtSecret(), true);
}

function documentShareHash(string $value): string
{
    return hash_hmac('sha256', $value, documentShareSecret());
}

function documentShareIpHash(): string
{
    return documentShareHash((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function documentShareUserAgentHash(): string
{
    return documentShareHash(substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512));
}

function documentShareRandomToken(int $bytes = 48): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function documentShareEncryptToken(string $rawToken): string
{
    $key = hash_hmac('sha256', 'dynabase-document-share-token-encryption-v1', jwtSecret(), true);
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $rawToken,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'dynabase-document-share-token-v1'
    );
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('Unable to protect the document-link token.', 500);
    }
    return rtrim(strtr(base64_encode($iv . $tag . $ciphertext), '+/', '-_'), '=');
}

function documentShareDecryptToken(mixed $encrypted): ?string
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
    $key = hash_hmac('sha256', 'dynabase-document-share-token-encryption-v1', jwtSecret(), true);
    $rawToken = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'dynabase-document-share-token-v1'
    );
    if (!is_string($rawToken) || !preg_match('/^[A-Za-z0-9_-]{48,160}$/', $rawToken)) {
        return null;
    }
    return $rawToken;
}

function documentShareFrontendUrl(string $rawToken): string
{
    $base = rtrim(envString('FRONTEND_URL', allowedFrontendOrigins()[0] ?? 'http://localhost:5173'), '/');
    return $base . '/shared-documents/' . rawurlencode($rawToken);
}

function documentShareTablesExist(mysqli $conn): bool
{
    $rows = dbFetchAll(
        $conn,
        "SELECT TABLE_NAME AS table_name
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('document_share_links', 'document_share_sessions', 'document_share_access_attempts')"
    );
    $available = array_map(static fn (array $row): string => strtolower((string) $row['table_name']), $rows);
    return count(array_unique($available)) === 3;
}

function assertDocumentShareSchema(mysqli $conn): void
{
    if (!documentShareTablesExist($conn)) {
        throw new RuntimeException('Document sharing is not installed. Apply the Documents sharing migration and try again.', 409);
    }

    $required = [
        'document_share_links' => [
            'id', 'document_id', 'revision_id', 'token_hash', 'token_ciphertext', 'link_name', 'access_mode', 'password_hash',
            'allow_download', 'expires_at', 'status', 'access_count', 'download_count', 'created_by_id',
            'created_by', 'created_at', 'updated_at', 'last_accessed_at', 'revoked_at', 'revoked_by_id',
        ],
        'document_share_sessions' => [
            'id', 'share_link_id', 'session_token_hash', 'ip_hash', 'user_agent_hash', 'expires_at',
            'created_at', 'last_accessed_at', 'revoked_at',
        ],
        'document_share_access_attempts' => [
            'id', 'share_link_id', 'token_hash', 'ip_hash', 'outcome', 'created_at',
        ],
    ];

    foreach ($required as $table => $columns) {
        $rows = dbFetchAll(
            $conn,
            'SELECT COLUMN_NAME AS column_name
             FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?',
            's',
            [$table]
        );
        $available = array_map(static fn (array $row): string => strtolower((string) $row['column_name']), $rows);
        $missing = array_values(array_diff($columns, $available));
        if ($missing !== []) {
            error_log('[Dynabase Documents] Missing ' . $table . ' columns: ' . implode(', ', $missing));
            throw new RuntimeException('Document sharing storage is incomplete. Reapply the Documents sharing migration and try again.', 409);
        }
    }
}

function cleanupDocumentShareSecurityArtifacts(mysqli $conn, bool $force = false): void
{
    if (!documentShareTablesExist($conn)) {
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
            "DELETE FROM document_share_sessions
             WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
                OR (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(), INTERVAL 7 DAY))"
        )->close();
        dbExecute(
            $conn,
            "DELETE FROM document_share_access_attempts
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->close();
    } catch (Throwable $exception) {
        error_log('[Dynabase Documents] Share-security cleanup failed: ' . $exception->getMessage());
    }
}

function revokeDocumentSharesForDocumentIds(
    mysqli $conn,
    array $documentIds,
    int $actorUserId
): array {
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $documentIds),
        static fn (int $id): bool => $id > 0
    )));
    if ($ids === [] || !documentShareTablesExist($conn)) {
        return ['links_revoked' => 0, 'sessions_revoked' => 0];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmt = dbExecute(
        $conn,
        "UPDATE document_share_links
         SET status = 'revoked',
             revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP),
             revoked_by_id = COALESCE(revoked_by_id, ?),
             updated_at = CURRENT_TIMESTAMP
         WHERE document_id IN ({$placeholders}) AND status = 'active'",
        'i' . $types,
        array_merge([$actorUserId], $ids)
    );
    $linksRevoked = $stmt->affected_rows;
    $stmt->close();

    $stmt = dbExecute(
        $conn,
        "UPDATE document_share_sessions session_row
         INNER JOIN document_share_links share_row ON share_row.id = session_row.share_link_id
         SET session_row.revoked_at = COALESCE(session_row.revoked_at, CURRENT_TIMESTAMP)
         WHERE share_row.document_id IN ({$placeholders})
           AND session_row.revoked_at IS NULL",
        $types,
        $ids
    );
    $sessionsRevoked = $stmt->affected_rows;
    $stmt->close();

    return [
        'links_revoked' => max(0, $linksRevoked),
        'sessions_revoked' => max(0, $sessionsRevoked),
    ];
}

function documentShareSummaryForDocument(mysqli $conn, int $documentId): array
{
    $row = dbFetchOne(
        $conn,
        "SELECT COUNT(*) AS total_links,
                SUM(CASE WHEN status = 'active' AND expires_at > NOW() THEN 1 ELSE 0 END) AS active_links,
                SUM(CASE WHEN status = 'active' AND expires_at <= NOW() THEN 1 ELSE 0 END) AS expired_links,
                SUM(CASE WHEN status = 'revoked' THEN 1 ELSE 0 END) AS revoked_links,
                COALESCE(SUM(access_count), 0) AS total_accesses,
                COALESCE(SUM(download_count), 0) AS total_downloads
         FROM document_share_links
         WHERE document_id = ?",
        'i',
        [$documentId]
    ) ?? [];

    return [
        'total_links' => (int) ($row['total_links'] ?? 0),
        'active_links' => (int) ($row['active_links'] ?? 0),
        'expired_links' => (int) ($row['expired_links'] ?? 0),
        'revoked_links' => (int) ($row['revoked_links'] ?? 0),
        'total_accesses' => (int) ($row['total_accesses'] ?? 0),
        'total_downloads' => (int) ($row['total_downloads'] ?? 0),
    ];
}

function normaliseDocumentShareAccessMode(mixed $value): string
{
    $mode = strtolower(trim((string) $value));
    if ($mode === 'password') {
        $mode = 'controlled';
    }
    if (!in_array($mode, DYNABASE_DOCUMENT_SHARE_ACCESS_MODES, true)) {
        throw new RuntimeException('Please choose open or controlled link access.', 422);
    }
    return $mode;
}

function normaliseDocumentShareLinkName(mixed $value): string
{
    $name = trim((string) $value);
    if (documentStringLength($name) > 120) {
        throw new RuntimeException('Share-link name must not exceed 120 characters.', 422);
    }
    return $name;
}

function normaliseDocumentSharePassword(mixed $value, bool $required): ?string
{
    $password = (string) $value;
    if ($password === '') {
        if ($required) {
            throw new RuntimeException('A password is required for controlled access.', 422);
        }
        return null;
    }
    if (strlen($password) < 8 || strlen($password) > 128) {
        throw new RuntimeException('Controlled-link passwords must contain between 8 and 128 characters.', 422);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('Unable to protect the controlled-link password.', 500);
    }
    return $hash;
}

function normaliseDocumentShareExpiry(mixed $value, ?string $fallback = null): string
{
    $raw = trim((string) $value);
    if ($raw === '' && $fallback !== null) {
        return $fallback;
    }

    $timestamp = $raw === ''
        ? strtotime('+7 days')
        : strtotime($raw . (strlen($raw) <= 10 ? ' 23:59:59' : ''));

    if (!$timestamp || $timestamp <= time()) {
        throw new RuntimeException('Share-link expiry must be in the future.', 422);
    }
    if ($timestamp > strtotime('+365 days')) {
        throw new RuntimeException('Document share links may not remain active for more than 365 days.', 422);
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function assertDocumentShareTargetRevision(
    mysqli $conn,
    array $authUser,
    int $documentId,
    ?int $revisionId
): ?array {
    assertDocumentAccessible($conn, $authUser, $documentId);
    if ($revisionId === null || $revisionId <= 0) {
        return null;
    }

    return assertDocumentRevisionAccessible($conn, $authUser, $documentId, $revisionId, true);
}

function assertDocumentShareDeliveryOptions(
    mysqli $conn,
    int $documentId,
    ?array $targetRevision,
    bool $allowDownload
): array {
    $revision = $targetRevision ?? resolveDocumentShareTargetRevision($conn, [
        'document_id' => $documentId,
        'revision_id' => null,
    ]);
    $extension = strtolower((string) ($revision['file_extension'] ?? ''));
    $capabilities = documentFileCapabilities($extension);

    if ($capabilities['download_only'] && !$allowDownload) {
        throw new RuntimeException(
            'This file type cannot be previewed online. Download access must remain enabled for this share link.',
            422
        );
    }

    return $revision;
}

function fetchDocumentShareById(mysqli $conn, int $shareId): ?array
{
    return dbFetchOne(
        $conn,
        "SELECT s.*,
                CONCAT_WS(' ', creator.first_name, creator.last_name) AS creator_name,
                creator.email AS creator_email,
                CONCAT_WS(' ', revoker.first_name, revoker.last_name) AS revoker_name,
                fixed_revision.revision_code AS fixed_revision_code,
                fixed_revision.original_name AS fixed_original_name,
                fixed_revision.record_status AS fixed_revision_status
         FROM document_share_links s
         LEFT JOIN users creator ON creator.id = s.created_by_id
         LEFT JOIN users revoker ON revoker.id = s.revoked_by_id
         LEFT JOIN document_revisions fixed_revision ON fixed_revision.id = s.revision_id
         WHERE s.id = ?
         LIMIT 1",
        'i',
        [$shareId]
    );
}

function assertDocumentShareAdminAccessible(mysqli $conn, array $authUser, int $shareId): array
{
    $share = fetchDocumentShareById($conn, $shareId);
    if (!$share) {
        throw new RuntimeException('Document share link not found.', 404);
    }
    assertDocumentAccessible($conn, $authUser, (int) $share['document_id']);
    return $share;
}

function documentShareEffectiveStatus(array $share): string
{
    if (($share['status'] ?? '') === 'revoked') {
        return 'revoked';
    }
    if (!empty($share['expires_at']) && strtotime((string) $share['expires_at']) <= time()) {
        return 'expired';
    }
    return 'active';
}

function resolveDocumentShareTargetRevision(mysqli $conn, array $share): array
{
    $revisionId = (int) ($share['revision_id'] ?? 0);
    if ($revisionId > 0) {
        $revision = fetchDocumentRevision($conn, $revisionId, true);
        if (!$revision
            || (int) $revision['document_id'] !== (int) $share['document_id']
            || ($revision['record_status'] ?? '') === 'deleted') {
            throw new RuntimeException('The revision linked to this share is no longer available.', 410);
        }
        return $revision;
    }

    $revision = dbFetchOne(
        $conn,
        "SELECT r.*,
                CONCAT_WS(' ', uploader.first_name, uploader.last_name) AS uploader_name,
                uploader.email AS uploader_email,
                replacement.id AS replacement_revision_id
         FROM document_revisions r
         LEFT JOIN users uploader ON uploader.id = r.uploaded_by_id
         LEFT JOIN document_revisions replacement ON replacement.replaces_revision_id = r.id AND replacement.record_status <> 'deleted'
         WHERE r.document_id = ? AND r.record_status = 'active' AND r.is_current = 1
         ORDER BY r.id DESC LIMIT 1",
        'i',
        [(int) $share['document_id']]
    );
    if (!$revision) {
        throw new RuntimeException('The current document revision is no longer available.', 410);
    }
    return $revision;
}

function documentShareAdminPayload(mysqli $conn, array $share): array
{
    $effectiveStatus = documentShareEffectiveStatus($share);
    $targetRevision = null;
    $targetAvailable = true;
    try {
        $targetRevision = resolveDocumentShareTargetRevision($conn, $share);
    } catch (Throwable) {
        // Admins must still be able to see and revoke an old/broken link.
        $targetAvailable = false;
        if ($effectiveStatus === 'active') {
            $effectiveStatus = 'unavailable';
        }
    }

    $rawToken = documentShareDecryptToken($share['token_ciphertext'] ?? null);
    $targetExtension = strtolower((string) ($targetRevision['file_extension'] ?? ''));
    $targetCapabilities = documentFileCapabilities($targetExtension);

    return [
        'id' => (int) $share['id'],
        'document_id' => (int) $share['document_id'],
        'revision_id' => $share['revision_id'] !== null ? (int) $share['revision_id'] : null,
        'targets_current_revision' => $share['revision_id'] === null,
        'target_revision_code' => $targetRevision['revision_code'] ?? $share['fixed_revision_code'] ?? null,
        'target_original_name' => $targetRevision['original_name'] ?? $share['fixed_original_name'] ?? null,
        'target_file_extension' => $targetExtension !== '' ? $targetExtension : null,
        'target_previewable' => $targetAvailable ? $targetCapabilities['previewable'] : false,
        'target_download_only' => $targetAvailable ? $targetCapabilities['download_only'] : false,
        'target_file_kind' => $targetAvailable ? $targetCapabilities['file_kind'] : null,
        'target_delivery_mode' => $targetAvailable ? $targetCapabilities['delivery_mode'] : null,
        'target_available' => $targetAvailable,
        'link_name' => $share['link_name'] ?? '',
        'access_mode' => $share['access_mode'],
        'requires_password' => $share['access_mode'] === 'controlled',
        'allow_download' => (bool) $share['allow_download'],
        'expires_at' => $share['expires_at'],
        'status' => $share['status'],
        'effective_status' => $effectiveStatus,
        'access_count' => (int) $share['access_count'],
        'download_count' => (int) $share['download_count'],
        'created_by_id' => $share['created_by_id'] !== null ? (int) $share['created_by_id'] : null,
        'created_by_name' => trim((string) ($share['creator_name'] ?? '')) ?: ($share['creator_email'] ?? $share['created_by'] ?? ''),
        'created_at' => $share['created_at'],
        'updated_at' => $share['updated_at'],
        'last_accessed_at' => $share['last_accessed_at'] ?? null,
        'revoked_at' => $share['revoked_at'] ?? null,
        'revoked_by_id' => $share['revoked_by_id'] !== null ? (int) $share['revoked_by_id'] : null,
        'revoked_by_name' => trim((string) ($share['revoker_name'] ?? '')) ?: null,
        'share_url' => $rawToken !== null ? documentShareFrontendUrl($rawToken) : null,
    ];
}

function assertDocumentShareTokenFormat(string $rawToken): void
{
    if (!preg_match('/^[A-Za-z0-9_-]{48,160}$/', $rawToken)) {
        throw new RuntimeException('This document link is invalid or no longer available.', 404);
    }
}

function assertPublicDocumentShareAvailable(mysqli $conn, string $rawToken, bool $lock = false): array
{
    cleanupDocumentShareSecurityArtifacts($conn);
    assertDocumentShareTokenFormat($rawToken);
    $tokenHash = documentShareHash($rawToken);
    $lockSql = $lock ? ' FOR UPDATE' : '';
    $share = dbFetchOne(
        $conn,
        "SELECT s.*, d.document_title, d.document_type, d.document_category, d.description,
                d.relationship_type, d.presentation_code, d.status AS document_status
         FROM document_share_links s
         INNER JOIN document_table d ON d.id = s.document_id
         WHERE s.token_hash = ?
         LIMIT 1{$lockSql}",
        's',
        [$tokenHash]
    );

    if (!$share || ($share['document_status'] ?? '') !== 'active') {
        throw new RuntimeException('This document link is invalid or no longer available.', 404);
    }

    $status = documentShareEffectiveStatus($share);
    if ($status !== 'active') {
        throw new RuntimeException(
            $status === 'expired'
                ? 'This document link has expired.'
                : 'This document link is no longer active.',
            410
        );
    }

    $share['effective_status'] = $status;
    return $share;
}

function documentShareSessionTtlMinutes(): int
{
    return max(15, min((int) envString('DOCUMENT_SHARE_SESSION_MINUTES', '120'), 720));
}

function createDocumentShareSession(mysqli $conn, int $shareId): array
{
    $rawToken = documentShareRandomToken(36);
    $tokenHash = documentShareHash($rawToken);
    $ipHash = documentShareIpHash();
    $userAgentHash = documentShareUserAgentHash();
    $expiresAt = date('Y-m-d H:i:s', time() + (documentShareSessionTtlMinutes() * 60));

    $stmt = dbExecute(
        $conn,
        'INSERT INTO document_share_sessions
            (share_link_id, session_token_hash, ip_hash, user_agent_hash, expires_at)
         VALUES (?, ?, ?, ?, ?)',
        'issss',
        [$shareId, $tokenHash, $ipHash, $userAgentHash, $expiresAt]
    );
    $sessionId = (int) $stmt->insert_id;
    $stmt->close();

    return [
        'id' => $sessionId,
        'token' => $rawToken,
        'expires_at' => $expiresAt,
    ];
}

function assertDocumentShareSession(mysqli $conn, int $shareId, string $rawSessionToken): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{40,160}$/', $rawSessionToken)) {
        throw new RuntimeException('This controlled-access session is invalid. Please enter the link password again.', 401);
    }

    $session = dbFetchOne(
        $conn,
        "SELECT * FROM document_share_sessions
         WHERE share_link_id = ?
           AND session_token_hash = ?
           AND ip_hash = ?
           AND user_agent_hash = ?
           AND revoked_at IS NULL
         LIMIT 1",
        'isss',
        [
            $shareId,
            documentShareHash($rawSessionToken),
            documentShareIpHash(),
            documentShareUserAgentHash(),
        ]
    );

    if (!$session || strtotime((string) $session['expires_at']) <= time()) {
        throw new RuntimeException('This controlled-access session has expired. Please enter the link password again.', 401);
    }

    dbExecute(
        $conn,
        'UPDATE document_share_sessions SET last_accessed_at = CURRENT_TIMESTAMP WHERE id = ?',
        'i',
        [(int) $session['id']]
    )->close();

    return $session;
}

function assertDocumentShareAccess(mysqli $conn, array $share, ?string $rawSessionToken): void
{
    if (($share['access_mode'] ?? '') !== 'controlled') {
        return;
    }
    assertDocumentShareSession($conn, (int) $share['id'], trim((string) $rawSessionToken));
}

function assertDocumentSharePasswordRateLimit(mysqli $conn, int $shareId): void
{
    $ipHash = documentShareIpHash();
    $perLink = dbScalarInt(
        $conn,
        "SELECT COUNT(*) AS total
         FROM document_share_access_attempts
         WHERE share_link_id = ? AND ip_hash = ? AND outcome = 'denied'
           AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        'is',
        [$shareId, $ipHash]
    );
    $maxPerLink = max(3, min((int) envString('DOCUMENT_SHARE_MAX_PASSWORD_ATTEMPTS_PER_HOUR', '8'), 25));
    if ($perLink >= $maxPerLink) {
        throw new RuntimeException('Too many incorrect password attempts. Please try again later.', 429);
    }

    $global = dbScalarInt(
        $conn,
        "SELECT COUNT(*) AS total
         FROM document_share_access_attempts
         WHERE ip_hash = ? AND outcome = 'denied'
           AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        's',
        [$ipHash]
    );
    if ($global >= max(10, $maxPerLink * 2)) {
        throw new RuntimeException('Too many incorrect password attempts. Please try again later.', 429);
    }
}

function recordDocumentSharePasswordAttempt(mysqli $conn, array $share, string $outcome): void
{
    dbExecute(
        $conn,
        'INSERT INTO document_share_access_attempts
            (share_link_id, token_hash, ip_hash, outcome)
         VALUES (?, ?, ?, ?)',
        'isss',
        [
            (int) $share['id'],
            (string) $share['token_hash'],
            documentShareIpHash(),
            $outcome,
        ]
    )->close();
}

function touchDocumentShareAccess(mysqli $conn, int $shareId, bool $download = false): void
{
    $downloadSql = $download ? ', download_count = download_count + 1' : '';
    dbExecute(
        $conn,
        "UPDATE document_share_links
         SET access_count = access_count + 1{$downloadSql}, last_accessed_at = CURRENT_TIMESTAMP,
             updated_at = updated_at
         WHERE id = ? AND status = 'active'",
        'i',
        [$shareId]
    )->close();
}

function documentSharePublicPayload(mysqli $conn, array $share, array $revision): array
{
    $extension = strtolower((string) ($revision['file_extension'] ?? ''));
    $capabilities = documentFileCapabilities($extension);

    return [
        'document' => [
            'title' => $share['document_title'],
            'type' => $share['document_type'],
            'category' => $share['document_category'],
            'description' => $share['description'] ?? '',
            'reference_code' => ($share['presentation_code'] ?? '') !== 'N/A' ? ($share['presentation_code'] ?? '') : '',
        ],
        'revision' => [
            'revision_code' => $revision['revision_code'],
            'original_name' => $revision['original_name'],
            'mime_type' => $revision['mime_type'],
            'file_extension' => $extension,
            'file_size' => (int) $revision['file_size'],
            'previewable' => $capabilities['previewable'],
            'download_only' => $capabilities['download_only'],
            'file_kind' => $capabilities['file_kind'],
            'delivery_mode' => $capabilities['delivery_mode'],
            'uploaded_at' => $revision['uploaded_at'],
        ],
        'share' => [
            'link_name' => $share['link_name'] ?? '',
            'access_mode' => $share['access_mode'],
            'requires_password' => $share['access_mode'] === 'controlled',
            'allow_download' => (bool) $share['allow_download'],
            'expires_at' => $share['expires_at'],
        ],
    ];
}
