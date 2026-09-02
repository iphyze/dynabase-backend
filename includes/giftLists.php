<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/dbHelpers.php';

const DYNABASE_GIFT_RATES = ['A+', 'A', 'B+', 'B', 'C+', 'C', 'D'];

function validateGiftYear(mixed $value): int
{
    $year = (int) ($value ?: date('Y'));
    $maximum = (int) date('Y') + 1;
    if ($year < 2000 || $year > $maximum) {
        throw new RuntimeException('Please choose a valid gift year.', 422);
    }
    return $year;
}

function validateGiftRate(mixed $value): string
{
    $rate = trim((string) $value);
    if (!in_array($rate, DYNABASE_GIFT_RATES, true)) {
        throw new RuntimeException('Please choose a valid gift rate.', 422);
    }
    return $rate;
}

function resolveGiftOwnerPmsAdminId(mysqli $conn, array $authUser, ?int $requestedOwnerId = null): int
{
    $fixedOwnerId = resolveOwnerPmsAdminId($authUser);
    if ($fixedOwnerId !== null && $fixedOwnerId > 0) {
        return $fixedOwnerId;
    }

    if (($requestedOwnerId === null || $requestedOwnerId <= 0) && userActsAsPmsAdmin($authUser)) {
        return (int) $authUser['id'];
    }

    if ($requestedOwnerId === null || $requestedOwnerId <= 0) {
        throw new RuntimeException('Please select the PMS owner for this gift list.', 422);
    }

    $owner = dbFetchOne(
        $conn,
        "SELECT id FROM users WHERE id = ? AND status = 'active' AND (role = 'pms_admin' OR is_pms_admin = 1) LIMIT 1",
        'i',
        [$requestedOwnerId]
    );

    if (!$owner) {
        throw new RuntimeException('The selected PMS owner is not available.', 422);
    }

    return (int) $owner['id'];
}

function giftListFixedOwnerId(array $authUser): ?int
{
    $ownerId = resolveOwnerPmsAdminId($authUser);
    return $ownerId !== null && $ownerId > 0 ? $ownerId : null;
}

function assertGiftListAccessible(mysqli $conn, array $authUser, int $giftListId): array
{
    $types = 'i';
    $params = [$giftListId];
    $scope = '';
    $fixedOwnerId = giftListFixedOwnerId($authUser);
    if (!isGlobalDataUser($authUser)) {
        if ($fixedOwnerId === null) {
            throw new RuntimeException('Your account is not assigned to a PMS owner.', 403);
        }
        $scope = ' AND gl.owner_pms_admin_id = ?';
        $types .= 'i';
        $params[] = $fixedOwnerId;
    }

    $row = dbFetchOne(
        $conn,
        "SELECT gl.*, u.first_name AS owner_first_name, u.last_name AS owner_last_name,
                u.email AS owner_email, u.role AS owner_role, u.is_pms_admin AS owner_is_pms_admin
         FROM gift_lists gl
         INNER JOIN users u ON u.id = gl.owner_pms_admin_id
         WHERE gl.id = ?{$scope}
         LIMIT 1",
        $types,
        $params
    );

    if (!$row) {
        throw new RuntimeException('Gift list not found or not accessible.', 404);
    }

    return $row;
}

function validateGiftDecision(mixed $value): string
{
    $decision = trim((string) $value);
    if (!in_array($decision, ['selected', 'not_selected'], true)) {
        throw new RuntimeException('Please choose a valid gift-list decision.', 422);
    }
    return $decision;
}

function ensureGiftList(mysqli $conn, int $giftYear, int $ownerPmsAdminId, int $actorId): int
{
    $stmt = dbExecute(
        $conn,
        'INSERT INTO gift_lists (gift_year, owner_pms_admin_id, created_by_id, updated_by_id)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
        'iiii',
        [$giftYear, $ownerPmsAdminId, $actorId, $actorId]
    );
    $giftListId = (int) $stmt->insert_id;
    $stmt->close();
    if ($giftListId <= 0) {
        throw new RuntimeException('Unable to resolve the annual gift list.', 500);
    }
    return $giftListId;
}

function fetchGiftListKeyperson(mysqli $conn, int $keypersonId): array
{
    $keyperson = dbFetchOne(
        $conn,
        "SELECT k.id, k.clients_id, k.clients_name, k.key_person, k.status
         FROM keypersons_table k
         WHERE k.id = ? AND k.status = 'active'
         LIMIT 1",
        'i',
        [$keypersonId]
    );
    if (!$keyperson) {
        throw new RuntimeException('The selected Key Person is unavailable or inactive.', 422);
    }
    return $keyperson;
}

function upsertGiftListItem(
    mysqli $conn,
    int $giftListId,
    array $keyperson,
    string $decision,
    ?string $rate,
    string $notes,
    int $actorId
): void {
    $decision = validateGiftDecision($decision);
    if ($decision === 'selected') {
        $rate = validateGiftRate($rate);
    } else {
        $rate = null;
    }

    dbExecute(
        $conn,
        'INSERT INTO gift_list_items
            (gift_list_id, keyperson_id, client_id, gift_decision, gift_rate, notes,
             keyperson_name_snapshot, client_name_snapshot, source, is_verified,
             created_by_id, updated_by_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "live", 1, ?, ?)
         ON DUPLICATE KEY UPDATE
            client_id = VALUES(client_id),
            gift_decision = VALUES(gift_decision),
            gift_rate = VALUES(gift_rate),
            notes = VALUES(notes),
            keyperson_name_snapshot = VALUES(keyperson_name_snapshot),
            client_name_snapshot = VALUES(client_name_snapshot),
            source = "live",
            is_verified = 1,
            updated_by_id = VALUES(updated_by_id),
            updated_at = CURRENT_TIMESTAMP',
        'iiisssssii',
        [
            $giftListId,
            (int) $keyperson['id'],
            (int) $keyperson['clients_id'],
            $decision,
            $rate,
            $notes !== '' ? $notes : null,
            (string) $keyperson['key_person'],
            (string) $keyperson['clients_name'],
            $actorId,
            $actorId,
        ]
    )->close();

    dbExecute(
        $conn,
        'UPDATE gift_lists SET updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
        'ii',
        [$actorId, $giftListId]
    )->close();
}

function normalizeGiftListRows(mysqli $conn, int $_ownerPmsAdminId, mixed $rows): array
{
    if (!is_array($rows) || $rows === []) {
        throw new RuntimeException('Please add at least one key person to the gift list.', 422);
    }
    if (count($rows) > 500) {
        throw new RuntimeException('A gift list cannot contain more than 500 rows at once.', 422);
    }

    $inputRows = [];
    $keypersonIds = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            throw new RuntimeException('Gift-list row ' . ($index + 1) . ' is invalid.', 422);
        }
        $clientId = (int) ($row['client_id'] ?? 0);
        $keypersonId = (int) ($row['keyperson_id'] ?? 0);
        $rate = validateGiftRate($row['gift_rate'] ?? '');
        $notes = cleanString($row['notes'] ?? '');
        if ($clientId <= 0) {
            throw new RuntimeException('Please select a client on row ' . ($index + 1) . '.', 422);
        }
        if ($keypersonId <= 0) {
            throw new RuntimeException('Please select a key person on row ' . ($index + 1) . '.', 422);
        }
        if (mb_strlen($notes) > 1000) {
            throw new RuntimeException('Notes on row ' . ($index + 1) . ' cannot exceed 1000 characters.', 422);
        }
        if (isset($inputRows[$keypersonId])) {
            throw new RuntimeException('A key person cannot appear more than once in the same gift list.', 422);
        }
        $inputRows[$keypersonId] = [
            'client_id' => $clientId,
            'keyperson_id' => $keypersonId,
            'gift_rate' => $rate,
            'notes' => $notes,
        ];
        $keypersonIds[] = $keypersonId;
    }

    $placeholders = implode(',', array_fill(0, count($keypersonIds), '?'));
    $types = str_repeat('i', count($keypersonIds));
    $keypersons = dbFetchAll(
        $conn,
        "SELECT k.id, k.clients_id, k.clients_name, k.key_person
         FROM keypersons_table k
         WHERE k.id IN ({$placeholders})
           AND k.status = 'active'",
        $types,
        $keypersonIds
    );

    $available = [];
    foreach ($keypersons as $keyperson) {
        $available[(int) $keyperson['id']] = $keyperson;
    }

    $normalized = [];
    foreach ($inputRows as $keypersonId => $row) {
        $keyperson = $available[$keypersonId] ?? null;
        if (!$keyperson) {
            throw new RuntimeException('One or more selected key persons are unavailable or inactive.', 422);
        }
        if ((int) $keyperson['clients_id'] !== (int) $row['client_id']) {
            throw new RuntimeException('The selected key person does not belong to the selected client.', 422);
        }
        $normalized[] = [
            ...$row,
            'keyperson_name_snapshot' => (string) $keyperson['key_person'],
            'client_name_snapshot' => (string) $keyperson['clients_name'],
        ];
    }

    return $normalized;
}

function replaceGiftListItems(mysqli $conn, int $giftListId, array $rows, int $actorId): void
{
    dbExecute($conn, 'DELETE FROM gift_list_items WHERE gift_list_id = ?', 'i', [$giftListId])->close();

    foreach ($rows as $row) {
        dbExecute(
            $conn,
            'INSERT INTO gift_list_items
                (gift_list_id, keyperson_id, client_id, gift_decision, gift_rate, notes,
                 keyperson_name_snapshot, client_name_snapshot, source, is_verified,
                 created_by_id, updated_by_id)
             VALUES (?, ?, ?, "selected", ?, ?, ?, ?, "live", 1, ?, ?)',
            'iiissssii',
            [
                $giftListId,
                (int) $row['keyperson_id'],
                (int) $row['client_id'],
                (string) $row['gift_rate'],
                $row['notes'] !== '' ? $row['notes'] : null,
                (string) $row['keyperson_name_snapshot'],
                (string) $row['client_name_snapshot'],
                $actorId,
                $actorId,
            ]
        )->close();
    }
}

function giftListResponsePayload(mysqli $conn, array $list): array
{
    $items = dbFetchAll(
        $conn,
        "SELECT gli.id, gli.gift_list_id, gli.keyperson_id, gli.client_id, gli.gift_rate, gli.notes,
                gli.keyperson_name_snapshot, gli.client_name_snapshot, gli.source, gli.is_verified,
                gli.legacy_source_updated_at, gli.created_at, gli.updated_at,
                k.title, k.key_persons_email, k.key_persons_tel, k.status AS keyperson_status,
                c.clients_category, c.status AS client_status
         FROM gift_list_items gli
         LEFT JOIN keypersons_table k ON k.id = gli.keyperson_id
         LEFT JOIN clients_table c ON c.id = gli.client_id
         WHERE gli.gift_list_id = ? AND gli.gift_decision = 'selected'
         ORDER BY gli.client_name_snapshot ASC, gli.keyperson_name_snapshot ASC, gli.id ASC",
        'i',
        [(int) $list['id']]
    );

    $ownerName = trim((string) ($list['owner_first_name'] ?? '') . ' ' . (string) ($list['owner_last_name'] ?? ''));
    return [
        'id' => (int) $list['id'],
        'gift_year' => (int) $list['gift_year'],
        'owner_pms_admin_id' => (int) $list['owner_pms_admin_id'],
        'owner' => [
            'id' => (int) $list['owner_pms_admin_id'],
            'name' => $ownerName !== '' ? $ownerName : (string) ($list['owner_email'] ?? 'PMS owner'),
            'email' => $list['owner_email'] ?? null,
            'role' => $list['owner_role'] ?? null,
            'is_pms_admin' => (int) ($list['owner_is_pms_admin'] ?? 0) === 1,
        ],
        'items' => $items,
        'recipient_count' => count($items),
        'client_count' => count(array_unique(array_map(static fn (array $item): int => (int) $item['client_id'], $items))),
        'created_by_id' => isset($list['created_by_id']) ? (int) $list['created_by_id'] : null,
        'updated_by_id' => isset($list['updated_by_id']) ? (int) $list['updated_by_id'] : null,
        'created_at' => $list['created_at'] ?? null,
        'updated_at' => $list['updated_at'] ?? null,
    ];
}
