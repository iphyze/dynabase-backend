<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/giftLists.php';

/**
 * Read-only preparation of a Key Persons selection. No annual register or recipients are
 * persisted until the user presses Save on the create/edit screen.
 */
requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$giftYear = validateGiftYear($payload['gift_year'] ?? date('Y'));
$requestedOwnerId = isset($payload['owner_pms_admin_id']) ? (int) $payload['owner_pms_admin_id'] : null;
$ownerId = resolveGiftOwnerPmsAdminId($conn, $authUser, $requestedOwnerId);

$rawIds = $payload['keyperson_ids'] ?? [];
if (!is_array($rawIds) || $rawIds === []) {
    throw new RuntimeException('Select at least one key person.', 422);
}
$ids = [];
foreach ($rawIds as $rawId) {
    $id = filter_var($rawId, FILTER_VALIDATE_INT);
    if ($id === false || $id <= 0) {
        throw new RuntimeException('One or more key-person IDs are invalid.', 422);
    }
    $ids[$id] = $id;
}
$ids = array_values($ids);
if (count($ids) > 500) {
    throw new RuntimeException('Select a maximum of 500 key persons.', 422);
}

$list = dbFetchOne(
    $conn,
    'SELECT id FROM gift_lists WHERE gift_year = ? AND owner_pms_admin_id = ? LIMIT 1',
    'ii',
    [$giftYear, $ownerId]
);
$listId = $list ? (int) $list['id'] : null;
if ($listId !== null) {
    assertGiftListAccessible($conn, $authUser, $listId);
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
[$scopeSql, $types, $params] = appendKeypersonScopedWhere(
    $authUser,
    'k',
    str_repeat('i', count($ids)),
    $ids
);
$keypersons = dbFetchAll(
    $conn,
    "SELECT k.id, k.clients_id, k.clients_name, k.key_person
     FROM keypersons_table k
     INNER JOIN clients_table c ON c.id = k.clients_id
     WHERE k.id IN ({$placeholders}) AND k.status = 'active'{$scopeSql}
     ORDER BY k.clients_name, k.key_person, k.id",
    $types,
    $params
);
if (count($keypersons) !== count($ids)) {
    throw new RuntimeException('One or more key persons are inactive, missing a client, or outside your permitted scope.', 422);
}

$existingIds = [];
if ($listId !== null) {
    $existingRows = dbFetchAll(
        $conn,
        "SELECT keyperson_id FROM gift_list_items
         WHERE gift_list_id = ? AND gift_decision = 'selected' AND keyperson_id IN ({$placeholders})",
        'i' . str_repeat('i', count($ids)),
        array_merge([$listId], $ids)
    );
    foreach ($existingRows as $row) {
        $existingIds[(int) $row['keyperson_id']] = true;
    }
}

// Prefer the chosen owner's rate last year. Otherwise use last year's rate
// from another register only when all recorded rates agree.
$priorRates = dbFetchAll(
    $conn,
    "SELECT gli.keyperson_id,
            MAX(CASE WHEN gl.owner_pms_admin_id = ? THEN gli.gift_rate END) AS owner_rate,
            COUNT(DISTINCT gli.gift_rate) AS distinct_rates,
            MAX(gli.gift_rate) AS shared_rate
     FROM gift_list_items gli
     INNER JOIN gift_lists gl ON gl.id = gli.gift_list_id
     WHERE gl.gift_year = ? AND gli.gift_decision = 'selected'
       AND gli.gift_rate IS NOT NULL AND gli.keyperson_id IN ({$placeholders})
     GROUP BY gli.keyperson_id",
    'ii' . str_repeat('i', count($ids)),
    array_merge([$ownerId, $giftYear - 1], $ids)
);
$rateById = [];
foreach ($priorRates as $record) {
    $rateById[(int) $record['keyperson_id']] = $record['owner_rate']
        ?: ((int) $record['distinct_rates'] === 1 ? $record['shared_rate'] : null);
}

$additions = [];
foreach ($keypersons as $person) {
    $personId = (int) $person['id'];
    if (isset($existingIds[$personId])) {
        continue;
    }
    $additions[] = [
        'keyperson_id' => $personId,
        'client_id' => (int) $person['clients_id'],
        'keyperson_name' => (string) $person['key_person'],
        'client_name' => (string) $person['clients_name'],
        'gift_rate' => $rateById[$personId] ?? null,
        'notes' => '',
    ];
}

// A saved annual register and its incoming selection must fit the normal form's limit.
if ($listId !== null) {
    $existingCount = dbScalarInt(
        $conn,
        "SELECT COUNT(*) AS total FROM gift_list_items WHERE gift_list_id = ? AND gift_decision = 'selected'",
        'i',
        [$listId]
    );
    if ($existingCount + count($additions) > 500) {
        throw new RuntimeException('This selection would exceed the maximum of 500 gift recipients. Choose fewer key persons.', 422);
    }
}

jsonResponse([
    'status' => 'Success',
    'data' => [
        'gift_year' => $giftYear,
        'owner_pms_admin_id' => $ownerId,
        'existing_list_id' => $listId,
        'selected_count' => count($ids),
        'skipped_existing_count' => count($existingIds),
        'additions' => $additions,
    ],
]);
