<?php
declare(strict_types=1);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/security.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function unauthenticated(string $message = 'Authentication required.'): never
{
    clearAuthCookie();
    jsonResponse([
        'status' => 'Failed',
        'message' => $message
    ], 401);
}

function currentUserSelectSql(): string
{
    return 'SELECT id, first_name, last_name, email, role, is_pms_admin, status, parent_pms_admin_id,
                   invited_by, created_by, updated_by, must_change_password, password_changed_at,
                   temporary_password_set_at, last_login_at, created_at
            FROM users
            WHERE id = ?
            LIMIT 1';
}

function normalizeUserRow(array $user): array
{
    $user['id'] = (int) $user['id'];
    $user['is_pms_admin'] = (int) ($user['is_pms_admin'] ?? 0) === 1;
    $user['parent_pms_admin_id'] = $user['parent_pms_admin_id'] !== null ? (int) $user['parent_pms_admin_id'] : null;
    $user['invited_by'] = $user['invited_by'] !== null ? (int) $user['invited_by'] : null;
    $user['created_by'] = $user['created_by'] !== null ? (int) $user['created_by'] : null;
    $user['updated_by'] = $user['updated_by'] !== null ? (int) $user['updated_by'] : null;
    $user['must_change_password'] = (int) ($user['must_change_password'] ?? 0) === 1;
    $user['full_name'] = trim((string) $user['first_name'] . ' ' . (string) $user['last_name']);
    return $user;
}

function passwordChangeAllowedRoute(): bool
{
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $requestPath = '/' . trim($requestPath, '/');

    return str_ends_with($requestPath, '/auth/me')
        || str_ends_with($requestPath, '/auth/logout')
        || str_ends_with($requestPath, '/auth/change-password')
        || str_ends_with($requestPath, '/auth/csrf');
}

function authenticateUser(): array
{
    global $conn;
    static $authenticatedUser = null;

    if (is_array($authenticatedUser)) {
        return $authenticatedUser;
    }

    $token = (string) ($_COOKIE[authCookieName()] ?? '');
    if ($token === '') {
        unauthenticated();
    }

    try {
        $decoded = (array) JWT::decode($token, new Key(jwtSecret(), 'HS256'));

        $userId = (int) ($decoded['sub'] ?? 0);
        $jti = (string) ($decoded['jti'] ?? '');
        $issuer = (string) ($decoded['iss'] ?? '');
        $audience = (string) ($decoded['aud'] ?? '');

        if ($userId <= 0 || $jti === '' || !hash_equals(jwtIssuer(), $issuer) || !hash_equals(jwtAudience(), $audience)) {
            unauthenticated('Invalid session.');
        }

        $sessionHash = sessionTokenHash($jti);
        $sessionStmt = $conn->prepare(
            'SELECT id
             FROM auth_sessions
             WHERE user_id = ?
               AND jti_hash = ?
               AND revoked_at IS NULL
               AND expires_at > CURRENT_TIMESTAMP
             LIMIT 1'
        );
        $sessionStmt->bind_param('is', $userId, $sessionHash);
        $sessionStmt->execute();
        $activeSession = $sessionStmt->get_result()->fetch_assoc();
        $sessionStmt->close();

        if (!$activeSession) {
            unauthenticated('Session expired. Please log in again.');
        }

        $userStmt = $conn->prepare(currentUserSelectSql());
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $user = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();

        if (!$user) {
            unauthenticated('Account is no longer available.');
        }

        if (!in_array((string) $user['status'], ['active'], true)) {
            unauthenticated('Your account is not active. Please contact an administrator.');
        }

        validateCsrfToken();

        $user = normalizeUserRow($user);
        $user['jti'] = $jti;

        if ($user['must_change_password'] && !passwordChangeAllowedRoute()) {
            jsonResponse([
                'status' => 'PasswordChangeRequired',
                'message' => 'Please create a new password before continuing.',
                'data' => [
                    'id' => (int) $user['id'],
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'full_name' => $user['full_name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'is_pms_admin' => $user['is_pms_admin'],
                    'status' => $user['status'],
                    'must_change_password' => true,
                    'parent_pms_admin_id' => $user['parent_pms_admin_id'],
                ],
            ], 428);
        }

        $authenticatedUser = $user;

        return $authenticatedUser;
    } catch (Throwable $exception) {
        error_log('[Dynabase Auth] ' . $exception->getMessage());
        unauthenticated('Invalid or expired session.');
    }
}
