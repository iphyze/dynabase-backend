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

/**
 * Phone identity keys: one key per number in the field (a field may hold "0803..., +234 805..."),
 * each key = the last 9 digits, so +234 803 123 4567, 0803 123 4567 and 803-123-4567 all match.
 * Stored space-separated in keypersons_table.normalized_phone. The migration's SQL backfill uses
 * exactly the same rules (separators , ; / | newline and the word "or"; numbers of 7+ digits).
 */
function keypersonPhoneKeys(string $value): array
{
    $parts = preg_split('/[,;\/|\n]|\s+or\s+/iu', $value) ?: [];
    $keys = [];
    foreach ($parts as $part) {
        $digits = preg_replace('/\D+/', '', $part) ?? '';
        if (strlen($digits) >= 7) {
            $keys[substr($digits, -9)] = true;
        }
    }
    return array_keys($keys);
}

function normalizeKeypersonPhone(string $value): ?string
{
    $keys = keypersonPhoneKeys($value);
    return $keys ? implode(' ', $keys) : null;
}

/** Placeholder addresses typed when the real email is unknown (they made unrelated people look identical). */
function isPlaceholderEmail(string $email): bool
{
    $email = strtolower(trim($email));
    $local = strstr($email, '@', true);
    return $local !== false && in_array($local, ['nil', 'na', 'n/a', 'none', 'null', 'noemail', 'no-email', 'nomail', 'unknown', 'xxx', 'test'], true);
}

function normalizeKeypersonEmail(string $value): ?string
{
    $email = strtolower(trim($value));
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !isPlaceholderEmail($email) ? $email : null;
}

/** Rejects placeholder emails at entry ("Nil@gmail.com"): leave the field blank instead. */
function assertRealKeypersonEmail(string $email): void
{
    if ($email !== '' && isPlaceholderEmail($email)) {
        throw new RuntimeException('Please enter the person\'s real email address, or leave the email blank.', 422);
    }
}

/** Name tokens without titles, accents or punctuation ("Engr. Benson Omopariola" -> [benson, omopariola]). */
function keypersonNameTokens(string $name): array
{
    $name = normalizeKeypersonName($name);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if (is_string($ascii) && $ascii !== '') {
            $name = strtolower($ascii);
        }
    }
    $tokens = preg_split('/[^a-z0-9]+/', $name) ?: [];
    $titles = ['mr', 'mrs', 'ms', 'miss', 'dr', 'engr', 'eng', 'arc', 'arch', 'prof', 'chief', 'alhaji', 'sir', 'qs', 'barr'];
    return array_values(array_filter($tokens, static fn (string $t): bool => strlen($t) >= 3 && !in_array($t, $titles, true)));
}

/** Two names can belong to the same person when they share a real name token (order-insensitive). */
function keypersonNamesCompatible(string $a, string $b): bool
{
    $ta = keypersonNameTokens($a);
    $tb = keypersonNameTokens($b);
    if ($ta === [] || $tb === []) {
        return true;
    }
    return array_intersect($ta, $tb) !== [];
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

/**
 * Identity matcher. A person is identified by email and/or phone; a name alone never proves identity
 * (two different people can both be "John Doe").
 *
 *   strong   - email or a phone number matches AND the names are compatible        -> same person (blocks a duplicate)
 *   possible - the name matches at another client and nothing contradicts it          -> suggestion only (may have moved)
 *   strong   - same name at the same client, and no email/phone was entered          -> same person (nothing new)
 *   possible - same name at the same client, entered email/phone the record lacks    -> suggestion only
 *              (so a second person with the same name at the same company can still be created)
 *   possible - email/phone matches but the name differs (shared office line, info@)   -> suggestion only
 *   (none)   - same name but a different email or phone                               -> a different person
 *
 * Returns up to $limit candidates, strongest first. Each row carries match_strength and match_reasons.
 */
function findKeypersonCandidates(
    mysqli $conn,
    string $name,
    string $phone,
    string $email,
    ?int $excludeId = null,
    int $limit = 5,
    int $clientId = 0
): array {
    $normalizedName = normalizeKeypersonName($name);
    $phoneKeys = keypersonPhoneKeys($phone);
    $normalizedEmail = normalizeKeypersonEmail($email);

    $conditions = [];
    $types = '';
    $params = [];
    if ($normalizedEmail !== null) {
        $conditions[] = 'k.normalized_email = ?';
        $types .= 's';
        $params[] = $normalizedEmail;
    }
    foreach ($phoneKeys as $key) {
        $conditions[] = "CONCAT(' ', k.normalized_phone, ' ') LIKE ?";
        $types .= 's';
        $params[] = '% ' . $key . ' %';
    }
    if ($normalizedName !== '') {
        $conditions[] = 'k.normalized_name = ?';
        $types .= 's';
        $params[] = $normalizedName;
    }
    if ($conditions === []) {
        return [];
    }

    $where = '(' . implode(' OR ', $conditions) . ')';
    if ($excludeId !== null && $excludeId > 0) {
        $where .= ' AND k.id <> ?';
        $types .= 'i';
        $params[] = $excludeId;
    }

    $rows = dbFetchAll(
        $conn,
        "SELECT k.id, k.clients_id, COALESCE(c.clients_name, k.clients_name) AS clients_name, k.key_person,
                k.key_persons_tel, k.key_persons_email, k.title, k.status,
                k.normalized_name, k.normalized_phone, k.normalized_email
         FROM keypersons_table k
         LEFT JOIN clients_table c ON c.id = k.clients_id -- live client name, never the copied snapshot
         WHERE {$where}
         ORDER BY CASE WHEN k.status = 'active' THEN 0 WHEN k.status = 'inactive' THEN 1 ELSE 2 END, k.id ASC
         LIMIT 50",
        $types,
        $params
    );

    $candidates = [];
    foreach ($rows as $row) {
        $rowKeys = $row['normalized_phone'] ? explode(' ', (string) $row['normalized_phone']) : [];
        $emailMatch = $normalizedEmail !== null && hash_equals($normalizedEmail, (string) ($row['normalized_email'] ?? ''));
        $phoneMatch = $phoneKeys !== [] && array_intersect($phoneKeys, $rowKeys) !== [];
        $nameMatch = $normalizedName !== '' && hash_equals($normalizedName, (string) ($row['normalized_name'] ?? ''));
        $namesCompatible = $nameMatch || $normalizedName === '' || keypersonNamesCompatible($name, (string) $row['key_person']);

        $reasons = [];
        if ($emailMatch) $reasons[] = 'email';
        if ($phoneMatch) $reasons[] = 'phone';
        if ($nameMatch) $reasons[] = 'name';

        if ($emailMatch || $phoneMatch) {
            $strength = $namesCompatible ? 'strong' : 'possible';
            if (!$namesCompatible) $reasons[] = 'different_name';
        } else {
            // Same name only: a different email or phone means a different person.
            $emailConflict = $normalizedEmail !== null && !empty($row['normalized_email']);
            $phoneConflict = $phoneKeys !== [] && $rowKeys !== [];
            if ($emailConflict || $phoneConflict) {
                continue;
            }
            // Same name at the same client and nothing entered to tell them apart: the same person.
            if ($nameMatch && $clientId > 0 && (int) $row['clients_id'] === $clientId
                && $normalizedEmail === null && $phoneKeys === []) {
                $strength = 'strong';
                $reasons[] = 'client';
            } else {
                $strength = 'possible';
            }
        }

        $row['match_strength'] = $strength;
        $row['match_reasons'] = $reasons;
        $row['_score'] = ($strength === 'strong' ? 1000 : 0) + ($emailMatch ? 100 : 0) + ($phoneMatch ? 80 : 0) + ($nameMatch ? 40 : 0);
        $candidates[] = $row;
    }

    usort($candidates, static fn (array $a, array $b): int => [$b['_score'], $a['id']] <=> [$a['_score'], $b['id']]);
    return array_slice($candidates, 0, max(1, $limit));
}

/** The existing record this entry would duplicate (strong identity match), or null. Client is not part of identity. */
function findStrongKeypersonDuplicate(
    mysqli $conn,
    string $name,
    int $clientId,
    string $phone,
    string $email,
    ?int $excludeId = null
): ?array {
    foreach (findKeypersonCandidates($conn, $name, $phone, $email, $excludeId, 5, $clientId) as $candidate) {
        if ($candidate['match_strength'] === 'strong') {
            return $candidate;
        }
    }
    return null;
}

/**
 * A name alone never identifies a person, so a new person who shares a name with an existing one must
 * carry an email or phone number that tells them apart. Returns the namesakes when that is missing
 * (the caller then answers 422 "IdentifierRequired"), or [] when the entry is fine.
 */
function keypersonNamesakesWithoutIdentifier(
    mysqli $conn,
    string $name,
    string $phone,
    string $email,
    ?int $excludeId = null
): array {
    if (normalizeKeypersonEmail($email) !== null || keypersonPhoneKeys($phone) !== []) {
        return [];
    }
    $normalizedName = normalizeKeypersonName($name);
    if ($normalizedName === '') {
        return [];
    }
    $types = 's';
    $params = [$normalizedName];
    $exclude = '';
    if ($excludeId !== null && $excludeId > 0) {
        $exclude = ' AND k.id <> ?';
        $types .= 'i';
        $params[] = $excludeId;
    }
    return dbFetchAll(
        $conn,
        "SELECT k.id, k.key_person, k.clients_id, COALESCE(c.clients_name, k.clients_name) AS clients_name, k.status
         FROM keypersons_table k
         LEFT JOIN clients_table c ON c.id = k.clients_id
         WHERE k.normalized_name = ?{$exclude}
         ORDER BY CASE WHEN k.status = 'active' THEN 0 ELSE 1 END, k.id
         LIMIT 5",
        $types,
        $params
    );
}

/** The 422 answer for a namesake entered without an email or phone. Call outside any lock/transaction. */
function respondKeypersonIdentifierRequired(string $name, array $namesakes): never
{
    $clients = array_values(array_unique(array_filter(array_map(
        static fn (array $row): string => (string) ($row['clients_name'] ?? ''),
        $namesakes
    ))));
    jsonResponse([
        'status' => 'IdentifierRequired',
        'message' => sprintf(
            '%s already exists in Dynabase%s. Use the existing details, or enter an email or phone number so this new person can be told apart.',
            $name,
            $clients ? ' (' . implode(', ', $clients) . ')' : ''
        ),
        'data' => [
            'namesakes' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'key_person' => (string) $row['key_person'],
                'clients_id' => (int) $row['clients_id'],
                'clients_name' => (string) ($row['clients_name'] ?? ''),
            ], $namesakes),
        ],
    ], 422);
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
        'match_strength' => (string) ($row['match_strength'] ?? 'strong'),
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
