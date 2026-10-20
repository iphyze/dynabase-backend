<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';

use Firebase\JWT\JWT;

requireMethod('POST');
validateCsrfToken();
$data = readJsonBody();
$email = cleanEmail($data['email'] ?? '');
$code = trim((string) ($data['code'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || preg_match('/^[0-9]{6}$/D', $code) !== 1) {
    throw new RuntimeException('Invalid or expired verification code.', 401);
}

pmsPortalRateLimit($conn, 'verify', 30);
$user = pmsPortalUserByEmail($conn, $email);
$ownerId = $user ? pmsPortalOwnerId($conn, $user) : null;
if ($ownerId === null) {
    throw new RuntimeException('Invalid or expired verification code.', 401);
}

$userId = (int) $user['id'];
$conn->begin_transaction();
$invalidCode = false;
try {
    $stmt = $conn->prepare('SELECT code_hash, attempts, (expires_at > NOW()) AS unexpired, consumed_at
        FROM pms_portal_login_codes WHERE user_id = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $challenge = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $valid = $challenge && $challenge['consumed_at'] === null
        && (int) $challenge['attempts'] < 5
        && (int) $challenge['unexpired'] === 1
        && hash_equals((string) $challenge['code_hash'], pmsPortalCodeHash($userId, $code));

    if (!$valid) {
        if ($challenge && $challenge['consumed_at'] === null && (int) $challenge['attempts'] < 5) {
            dbExecute($conn, 'UPDATE pms_portal_login_codes SET attempts = attempts + 1 WHERE user_id = ?', 'i', [$userId])->close();
        }
        $invalidCode = true;
    }

    if (!$invalidCode) {
        $now = time();
        $expires = $now + pmsPortalSessionSeconds();
        $jti = bin2hex(random_bytes(32));
        $jwt = JWT::encode([
            'iss' => jwtIssuer(),
            'aud' => 'dynabase-pms-portal',
            'scope' => 'gift-list-only',
            'sub' => (string) $userId,
            'jti' => $jti,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expires,
        ], jwtSecret(), 'HS256');

        dbExecute($conn, 'UPDATE pms_portal_login_codes SET consumed_at = NOW() WHERE user_id = ?', 'i', [$userId])->close();
        createAuthSession($conn, $userId, $jti, $expires);
    }
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

if ($invalidCode) {
    throw new RuntimeException('Invalid or expired verification code.', 401);
}

setcookie(pmsPortalCookieName(), $jwt, pmsPortalCookieOptions($expires));
header('Cache-Control: no-store');
$user['owner_pms_admin_id'] = $ownerId;
jsonResponse([
    'status' => 'Success',
    'message' => 'Verified successfully.',
    'data' => pmsPortalPublicUser($user, $conn),
]);
