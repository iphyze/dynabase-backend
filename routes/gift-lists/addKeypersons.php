<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/giftLists.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();

$rawIds = $payload['keyperson_ids'] ?? $payload['ids'] ?? [];
if (!is_array($rawIds)) {
    throw new RuntimeException('Select at least one key person.', 422);
}

$keypersonIds = [];
foreach ($rawIds as $rawId) {
    $id = (int) $rawId;
    if ($id > 0) {
        $keypersonIds[$id] = $id;
    }
}
$keypersonIds = array_values($keypersonIds);

if ($keypersonIds === []) {
    throw new RuntimeException('Select at least one key person.', 422);
}
if (count($keypersonIds) > 500) {
    throw new RuntimeException('You can add a maximum of 500 key persons at once.', 422);
}

$giftYear = validateGiftYear($payload['gift_year'] ?? date('Y'));
$giftRate = validateGiftRate($payload['gift_rate'] ?? '');
$notes = cleanString($payload['notes'] ?? '');
if (mb_strlen($notes) > 1000) {
    throw new RuntimeException('Gift-list notes cannot exceed 1000 characters.', 422);
}

$requestedOwnerId = isset($payload['owner_pms_admin_id']) ? (int) $payload['owner_pms_admin_id'] : null;
$ownerPmsAdminId = resolveGiftOwnerPmsAdminId($conn, $authUser, $requestedOwnerId);

$placeholders = implode(',', array_fill(0, count($keypersonIds), '?'));
[$scopeSql, $types, $params] = appendKeypersonScopedWhere(
    $authUser,
    'k',
    str_repeat('i', count($keypersonIds)),
    $keypersonIds
);

$keypersons = dbFetchAll(
    $conn,
    "SELECT k.id, k.clients_id, k.clients_name, k.key_person
     FROM keypersons_table k
     WHERE k.id IN ({$placeholders})
       AND k.status = 'active'{$scopeSql}
     ORDER BY k.clients_name ASC, k.key_person ASC",
    $types,
    $params
);

if (count($keypersons) !== count($keypersonIds)) {
    throw new RuntimeException('One or more selected key persons were not found or are unavailable.', 404);
}

$actorId = (int) $authUser['id'];
$totalAdded = 0;
$totalSkipped = 0;
$createdList = false;

$conn->begin_transaction();
try {
    $existingList = dbFetchOne(
        $conn,
        'SELECT id FROM gift_lists WHERE gift_year = ? AND owner_pms_admin_id = ? LIMIT 1',
        'ii',
        [$giftYear, $ownerPmsAdminId]
    );
    $createdList = !$existingList;
    $giftListId = ensureGiftList($conn, $giftYear, $ownerPmsAdminId, $actorId);

    $existingRows = dbFetchAll(
        $conn,
        "SELECT keyperson_id, gift_decision FROM gift_list_items WHERE gift_list_id = ? AND keyperson_id IN ({$placeholders})",
        'i' . str_repeat('i', count($keypersonIds)),
        array_merge([$giftListId], $keypersonIds)
    );
    $existingDecisions = [];
    foreach ($existingRows as $existingRow) {
        $existingDecisions[(int) $existingRow['keyperson_id']] = (string) $existingRow['gift_decision'];
    }

    foreach ($keypersons as $keyperson) {
        $keypersonId = (int) $keyperson['id'];
        if (($existingDecisions[$keypersonId] ?? '') === 'selected') {
            $totalSkipped++;
            continue;
        }

        upsertGiftListItem($conn, $giftListId, $keyperson, 'selected', $giftRate, $notes, $actorId);
        $totalAdded++;
    }

    writeAuditLog($conn, $authUser, 'gift_list.keypersons_added', 'gift_list', $giftListId, [
        'gift_year' => $giftYear,
        'owner_pms_admin_id' => $ownerPmsAdminId,
        'selected_count' => count($keypersons),
        'added_count' => $totalAdded,
        'skipped_existing_count' => $totalSkipped,
        'gift_rate' => $giftRate,
        'list_created' => $createdList,
    ]);

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

if ($totalAdded === 0) {
    $message = "All selected key persons are already included in the {$giftYear} annual gift list.";
} else {
    $message = $totalAdded === 1
        ? "1 key person was added to the {$giftYear} annual gift list."
        : "{$totalAdded} key persons were added to the {$giftYear} annual gift list.";
    if ($totalSkipped > 0) {
        $message .= " {$totalSkipped} existing " . ($totalSkipped === 1 ? 'recipient was' : 'recipients were') . ' left unchanged.';
    }
}

jsonResponse([
    'status' => 'Success',
    'message' => $message,
    'data' => [
        'gift_list_id' => $giftListId,
        'gift_year' => $giftYear,
        'owner_pms_admin_id' => $ownerPmsAdminId,
        'gift_rate' => $giftRate,
        'selected_count' => count($keypersonIds),
        'added_count' => $totalAdded,
        'skipped_existing_count' => $totalSkipped,
        'list_created' => $createdList,
    ],
]);
