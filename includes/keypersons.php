<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/dbHelpers.php';
require_once __DIR__ . '/security.php';

function normalizeKeypersonName(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function normalizeKeypersonPhone(string $value): ?string
{
    $digits = preg_replace('/\D+/', '', trim($value)) ?? '';
    return strlen($digits) >= 7 ? $digits : null;
}

function normalizeKeypersonEmail(string $value): ?string
{
    $email = strtolower(trim($value));
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

function resolveKeypersonAssignmentPmsAdminId(
    mysqli $conn,
    array $authUser,
    ?int $requestedOwnerPmsAdminId = null
): int {
    $fixedOwnerId = resolveOwnerPmsAdminId($authUser);
    if ($fixedOwnerId !== null && $fixedOwnerId > 0) {
        return $fixedOwnerId;
    }

    if ($requestedOwnerPmsAdminId === null || $requestedOwnerPmsAdminId <= 0) {
        throw new RuntimeException('Please assign this Key Person to a PMS Admin.', 422);
    }

    $pmsAdmin = dbFetchOne(
        $conn,
        "SELECT id FROM users WHERE id = ? AND status = 'active' AND (role = 'pms_admin' OR is_pms_admin = 1) LIMIT 1",
        'i',
        [$requestedOwnerPmsAdminId]
    );
    if (!$pmsAdmin) {
        throw new RuntimeException('The selected PMS Admin is not available.', 422);
    }

    return (int) $pmsAdmin['id'];
}

function keypersonPmsAssignments(mysqli $conn, array $authUser, int $keypersonId): array
{
    $where = ' WHERE kpa.keyperson_id = ?';
    $types = 'i';
    $params = [$keypersonId];

    if (userHasRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN])) {
        // Global administrators may inspect the complete assignment relationship.
    } elseif (isPmsWorkspaceUser($authUser)) {
        $ownerId = resolveOwnerPmsAdminId($authUser);
        if ($ownerId === null || $ownerId <= 0) {
            return [];
        }
        $where .= ' AND kpa.pms_admin_id = ?';
        $types .= 'i';
        $params[] = $ownerId;
    } else {
        // PMS ownership information is not exposed to ordinary users.
        return [];
    }

    $rows = dbFetchAll(
        $conn,
        "SELECT u.id, u.first_name, u.last_name, u.email
         FROM keyperson_pms_assignments kpa
         INNER JOIN users u ON u.id = kpa.pms_admin_id
         {$where}
         ORDER BY u.first_name ASC, u.last_name ASC, u.id ASC",
        $types,
        $params
    );

    return array_map(static function (array $row): array {
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        return [
            'id' => (int) $row['id'],
            'name' => $name !== '' ? $name : (string) ($row['email'] ?? ('PMS Admin #' . (int) $row['id'])),
            'email' => $row['email'] ?? null,
        ];
    }, $rows);
}

function keypersonHasPmsAssignment(mysqli $conn, int $keypersonId, int $pmsAdminId): bool
{
    if ($keypersonId <= 0 || $pmsAdminId <= 0) {
        return false;
    }

    return dbFetchOne(
        $conn,
        'SELECT id FROM keyperson_pms_assignments WHERE keyperson_id = ? AND pms_admin_id = ? LIMIT 1',
        'ii',
        [$keypersonId, $pmsAdminId]
    ) !== null;
}

function ensureKeypersonPmsAssignment(mysqli $conn, int $keypersonId, ?int $pmsAdminId, ?int $assignedById): bool
{
    if ($pmsAdminId === null || $pmsAdminId <= 0) {
        return false;
    }

    $stmt = dbExecute(
        $conn,
        'INSERT IGNORE INTO keyperson_pms_assignments (keyperson_id, pms_admin_id, assigned_by_id) VALUES (?, ?, ?)',
        'iii',
        [$keypersonId, $pmsAdminId, $assignedById]
    );
    $created = $stmt->affected_rows > 0;
    $stmt->close();

    return $created;
}

function acquireKeypersonCanonicalMutationLock(mysqli $conn): string
{
    $lockName = 'dynabase_keyperson_canonical_mutation';
    $row = dbFetchOne($conn, 'SELECT GET_LOCK(?, 5) AS acquired', 's', [$lockName]);
    if ((int) ($row['acquired'] ?? 0) !== 1) {
        throw new RuntimeException('Key Person duplicate checking is busy. Please try again.', 409);
    }
    return $lockName;
}

function releaseKeypersonCanonicalMutationLock(mysqli $conn, string $lockName): void
{
    try {
        dbFetchOne($conn, 'SELECT RELEASE_LOCK(?) AS released', 's', [$lockName]);
    } catch (Throwable $exception) {
        error_log('[Dynabase Key Person Mutation Lock] ' . $exception->getMessage());
    }
}

function keypersonDuplicateConditions(string $name, int $clientId, string $phone, string $email): array
{
    $normalizedName = normalizeKeypersonName($name);
    $normalizedPhone = normalizeKeypersonPhone($phone);
    $normalizedEmail = normalizeKeypersonEmail($email);
    $conditions = [];
    $types = '';
    $params = [];

    if ($normalizedEmail !== null) {
        $conditions[] = 'k.normalized_email = ?';
        $types .= 's';
        $params[] = $normalizedEmail;
    }
    if ($normalizedPhone !== null) {
        $conditions[] = 'k.normalized_phone = ?';
        $types .= 's';
        $params[] = $normalizedPhone;
    }
    if ($clientId > 0 && $normalizedName !== '') {
        $conditions[] = '(k.clients_id = ? AND k.normalized_name = ?)';
        $types .= 'is';
        array_push($params, $clientId, $normalizedName);
    }

    return [$conditions, $types, $params, $normalizedName, $normalizedPhone, $normalizedEmail];
}

function findStrongKeypersonDuplicate(
    mysqli $conn,
    string $name,
    int $clientId,
    string $phone,
    string $email,
    ?int $excludeId = null
): ?array {
    [$conditions, $types, $params, $normalizedName, $normalizedPhone, $normalizedEmail] = keypersonDuplicateConditions(
        $name,
        $clientId,
        $phone,
        $email
    );

    if (!$conditions) {
        return null;
    }

    $where = '(' . implode(' OR ', $conditions) . ')';
    if ($excludeId !== null && $excludeId > 0) {
        $where .= ' AND k.id <> ?';
        $types .= 'i';
        $params[] = $excludeId;
    }

    $rows = dbFetchAll(
        $conn,
        "SELECT k.id, k.clients_id, k.clients_name, k.key_person, k.key_persons_tel, k.key_persons_email,
                k.title, k.status, k.normalized_name, k.normalized_phone, k.normalized_email
         FROM keypersons_table k
         WHERE {$where}
         ORDER BY CASE WHEN k.status = 'active' THEN 0 WHEN k.status = 'inactive' THEN 1 ELSE 2 END, k.id ASC
         LIMIT 20",
        $types,
        $params
    );

    $bestMatch = null;
    $bestScore = -1;
    foreach ($rows as $row) {
        $reasons = [];
        $score = 0;
        if ($normalizedEmail !== null && hash_equals($normalizedEmail, (string) ($row['normalized_email'] ?? ''))) {
            $reasons[] = 'email';
            $score += 100;
        }
        if ($normalizedPhone !== null && hash_equals($normalizedPhone, (string) ($row['normalized_phone'] ?? ''))) {
            $reasons[] = 'phone';
            $score += 80;
        }
        if ($clientId > 0
            && (int) ($row['clients_id'] ?? 0) === $clientId
            && $normalizedName !== ''
            && hash_equals($normalizedName, (string) ($row['normalized_name'] ?? ''))) {
            $reasons[] = 'name_client';
            $score += 60;
        }

        if ($reasons && $score > $bestScore) {
            $row['match_reasons'] = $reasons;
            $bestMatch = $row;
            $bestScore = $score;
        }
    }

    return $bestMatch;
}

function findExactKeypersonNameMatch(
    mysqli $conn,
    string $name,
    ?int $excludeId = null
): ?array {
    $normalizedName = normalizeKeypersonName($name);
    if ($normalizedName === '') {
        return null;
    }

    $where = 'k.normalized_name = ?';
    $types = 's';
    $params = [$normalizedName];
    if ($excludeId !== null && $excludeId > 0) {
        $where .= ' AND k.id <> ?';
        $types .= 'i';
        $params[] = $excludeId;
    }

    $row = dbFetchOne(
        $conn,
        "SELECT k.id, k.clients_id, k.clients_name, k.key_person, k.key_persons_tel, k.key_persons_email,
                k.title, k.status, k.normalized_name, k.normalized_phone, k.normalized_email
         FROM keypersons_table k
         WHERE {$where}
         ORDER BY CASE WHEN k.status = 'active' THEN 0 WHEN k.status = 'inactive' THEN 1 ELSE 2 END, k.id ASC
         LIMIT 1",
        $types,
        $params
    );

    if (!$row) {
        return null;
    }

    $row['match_reasons'] = ['name'];
    return $row;
}

function keypersonDuplicatePublicPayload(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'clients_id' => (int) $row['clients_id'],
        'clients_name' => (string) $row['clients_name'],
        'key_person' => (string) $row['key_person'],
        'key_persons_tel' => (string) ($row['key_persons_tel'] ?? ''),
        'key_persons_email' => (string) ($row['key_persons_email'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'status' => (string) ($row['status'] ?? 'active'),
        'match_reasons' => array_values($row['match_reasons'] ?? []),
    ];
}

function keypersonAssignmentToken(array $authUser, int $keypersonId, int $pmsAdminId): string
{
    $payload = json_encode([
        'uid' => (int) ($authUser['id'] ?? 0),
        'kid' => $keypersonId,
        'pid' => $pmsAdminId,
        'exp' => time() + 600,
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Unable to prepare the contact assignment.', 500);
    }

    $body = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', 'dynabase-keyperson-assignment-v1|' . $body, jwtSecret());
    return $body . '.' . $signature;
}

function verifyKeypersonAssignmentToken(array $authUser, string $token): array
{
    $parts = explode('.', trim($token), 2);
    if (count($parts) !== 2 || $parts[0] === '' || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
        throw new RuntimeException('This contact assignment request is invalid or has expired.', 422);
    }

    [$body, $signature] = $parts;
    $expected = hash_hmac('sha256', 'dynabase-keyperson-assignment-v1|' . $body, jwtSecret());
    if (!hash_equals($expected, $signature)) {
        throw new RuntimeException('This contact assignment request is invalid or has expired.', 422);
    }

    $padding = strlen($body) % 4;
    if ($padding > 0) {
        $body .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($body, '-_', '+/'), true);
    $payload = is_string($decoded) ? json_decode($decoded, true) : null;

    if (!is_array($payload)
        || (int) ($payload['uid'] ?? 0) !== (int) ($authUser['id'] ?? 0)
        || (int) ($payload['kid'] ?? 0) <= 0
        || (int) ($payload['pid'] ?? 0) <= 0
        || (int) ($payload['exp'] ?? 0) < time()) {
        throw new RuntimeException('This contact assignment request is invalid or has expired.', 422);
    }

    return [
        'keyperson_id' => (int) $payload['kid'],
        'pms_admin_id' => (int) $payload['pid'],
    ];
}
