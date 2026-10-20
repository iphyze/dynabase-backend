<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/dbHelpers.php';
require_once __DIR__ . '/security.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function pmsPortalCookieName(): string
{
    return envString('PMS_PORTAL_COOKIE_NAME', 'dynabase_pms_portal_token');
}

function pmsPortalSessionSeconds(): int
{
    return max(1, min((int) envString('PMS_PORTAL_SESSION_HOURS', '12'), 24)) * 3600;
}

function pmsPortalCookieOptions(int $expires): array
{
    return array_merge(cookieOptions($expires, true), ['path' => '/']);
}

function pmsPortalClearCookie(): void
{
    setcookie(pmsPortalCookieName(), '', pmsPortalCookieOptions(time() - 3600));
    unset($_COOKIE[pmsPortalCookieName()]);
}

function pmsPortalOwnerId(mysqli $conn, array $user): ?int
{
    if (($user['status'] ?? '') !== 'active') {
        return null;
    }

    $role = (string) ($user['role'] ?? '');
    $userId = (int) ($user['id'] ?? 0);
    if (($role === DYNABASE_ROLE_PMS_ADMIN || ($role === DYNABASE_ROLE_ADMIN && (int) ($user['is_pms_admin'] ?? 0) === 1)) && $userId > 0) {
        return $userId;
    }

    if ($role !== DYNABASE_ROLE_PMS_USER) {
        return null;
    }

    $parentId = (int) ($user['parent_pms_admin_id'] ?? 0);
    if ($parentId <= 0) {
        return null;
    }

    $parent = dbFetchOne(
        $conn,
        "SELECT id FROM users WHERE id = ? AND status = 'active' AND (role = 'pms_admin' OR (role = 'admin' AND is_pms_admin = 1)) LIMIT 1",
        'i',
        [$parentId]
    );

    return $parent ? $parentId : null;
}

function pmsPortalUserByEmail(mysqli $conn, string $email): ?array
{
    return dbFetchOne(
        $conn,
        'SELECT id, first_name, last_name, email, role, is_pms_admin, status, parent_pms_admin_id, pms_portal_only, must_change_password,
                (password_hash IS NOT NULL AND LENGTH(password_hash) > 0) AS has_password
         FROM users WHERE email = ? LIMIT 1',
        's',
        [$email]
    );
}

function pmsPortalCodeHash(int $userId, string $code): string
{
    return hash_hmac('sha256', 'pms-portal-code:' . $userId . ':' . $code, jwtSecret());
}

function pmsPortalRateLimit(mysqli $conn, string $kind, int $limit): void
{
    $ipHash = hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), jwtSecret());
    $count = dbScalarInt(
        $conn,
        'SELECT COUNT(*) AS total FROM pms_portal_login_requests
         WHERE ip_hash = ? AND request_kind = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
        'ss',
        [$ipHash, $kind]
    );

    if ($count >= $limit) {
        throw new RuntimeException('Too many attempts. Please try again later.', 429);
    }

    dbExecute(
        $conn,
        'INSERT INTO pms_portal_login_requests (ip_hash, request_kind) VALUES (?, ?)',
        'ss',
        [$ipHash, $kind]
    )->close();
}

function pmsPortalCurrentUser(mysqli $conn): array
{
    $token = (string) ($_COOKIE[pmsPortalCookieName()] ?? '');
    if ($token === '') {
        throw new RuntimeException('Portal login required.', 401);
    }

    try {
        $decoded = (array) JWT::decode($token, new Key(jwtSecret(), 'HS256'));
        $userId = (int) ($decoded['sub'] ?? 0);
        $jti = (string) ($decoded['jti'] ?? '');
        if ($userId <= 0 || strlen($jti) !== 64
            || !hash_equals(jwtIssuer(), (string) ($decoded['iss'] ?? ''))
            || !hash_equals('dynabase-pms-portal', (string) ($decoded['aud'] ?? ''))
            || !hash_equals('gift-list-only', (string) ($decoded['scope'] ?? ''))) {
            throw new RuntimeException('Invalid session.');
        }

        $session = dbFetchOne(
            $conn,
            'SELECT id FROM auth_sessions WHERE user_id = ? AND jti_hash = ? AND revoked_at IS NULL
             AND expires_at > CURRENT_TIMESTAMP LIMIT 1',
            'is',
            [$userId, sessionTokenHash($jti)]
        );
        if (!$session) {
            throw new RuntimeException('Expired session.');
        }

        $user = dbFetchOne(
            $conn,
            'SELECT id, first_name, last_name, email, role, is_pms_admin, status, parent_pms_admin_id, pms_portal_only, must_change_password,
                (password_hash IS NOT NULL AND LENGTH(password_hash) > 0) AS has_password
             FROM users WHERE id = ? LIMIT 1',
            'i',
            [$userId]
        );
        $ownerId = $user ? pmsPortalOwnerId($conn, $user) : null;
        if ($ownerId === null) {
            throw new RuntimeException('Portal access revoked.');
        }

        validateCsrfToken();
        $user['owner_pms_admin_id'] = $ownerId;
        $user['session_jti'] = $jti;
        return $user;
    } catch (Throwable $exception) {
        pmsPortalClearCookie();
        throw new RuntimeException('Portal session expired or unavailable. Please sign in again.', 401);
    }
}

function pmsPortalPublicUser(array $user, mysqli $conn): array
{
    $ownerId = (int) ($user['owner_pms_admin_id'] ?? 0);
    $owner = $ownerId > 0 ? dbFetchOne(
        $conn,
        "SELECT first_name, last_name, email FROM users WHERE id = ? AND status = 'active' LIMIT 1",
        'i',
        [$ownerId]
    ) : null;
    $ownerName = trim((string) ($owner['first_name'] ?? '') . ' ' . (string) ($owner['last_name'] ?? ''));
    if ($ownerName === '') {
        $ownerName = (string) ($owner['email'] ?? 'Your PMS team');
    }
    return [
        'id' => (int) $user['id'],
        'name' => trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'owner_pms_admin_id' => $ownerId,
        'owner_name' => $ownerName,
        'can_open_main_app' => (int) ($user['pms_portal_only'] ?? 0) !== 1
            && (int) ($user['must_change_password'] ?? 0) !== 1
            && (int) ($user['has_password'] ?? 0) === 1
            && (in_array((string) ($user['role'] ?? ''), [DYNABASE_ROLE_PMS_ADMIN, DYNABASE_ROLE_PMS_USER], true)
                || ((string) ($user['role'] ?? '') === DYNABASE_ROLE_ADMIN && (int) ($user['is_pms_admin'] ?? 0) === 1)),
    ];
}
