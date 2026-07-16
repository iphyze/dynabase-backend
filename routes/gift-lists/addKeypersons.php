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

$placeholders = implode(',', array_fill(0, count($keypersonIds), '?'));
[$scopeSql, $types, $params] = appendScopedWhere(
    $authUser,
    'k',
    str_repeat('i', count($keypersonIds)),
    $keypersonIds
);

$keypersons = dbFetchAll(
    $conn,
    "SELECT k.id, k.clients_id, k.clients_name, k.key_person, k.owner_pms_admin_id
     FROM keypersons_table k
     WHERE k.id IN ({$placeholders})
       AND k.status = 'active'{$scopeSql}
     ORDER BY k.owner_pms_admin_id ASC, k.clients_name ASC, k.key_person ASC",
    $types,
    $params
);

if (count($keypersons) !== count($keypersonIds)) {
    throw new RuntimeException('One or more selected key persons were not found or are unavailable.', 404);
}

$missingOwnerNames = [];
$grouped = [];
foreach ($keypersons as $keyperson) {
    $ownerId = (int) ($keyperson['owner_pms_admin_id'] ?? 0);
    if ($ownerId <= 0) {
        $missingOwnerNames[] = (string) ($keyperson['key_person'] ?? 'Unnamed key person');
        continue;
    }
    $grouped[$ownerId][] = $keyperson;
}

if ($missingOwnerNames !== []) {
    $preview = implode(', ', array_slice($missingOwnerNames, 0, 3));
    $suffix = count($missingOwnerNames) > 3 ? ' and others' : '';
    throw new RuntimeException("Assign a PMS owner before adding these key persons to a gift list: {$preview}{$suffix}.", 422);
}

$actorId = (int) $authUser['id'];
$totalAdded = 0;
$totalSkipped = 0;
$affectedLists = [];

$conn->begin_transaction();
try {
    foreach ($grouped as $ownerId => $ownerKeypersons) {
        $resolvedOwnerId = resolveGiftOwnerPmsAdminId($conn, $authUser, (int) $ownerId);
        if ($resolvedOwnerId !== (int) $ownerId) {
            throw new RuntimeException('One or more selected key persons were not found.', 404);
        }

        $giftList = dbFetchOne(
            $conn,
            'SELECT id FROM gift_lists WHERE gift_year = ? AND owner_pms_admin_id = ? LIMIT 1',
            'ii',
            [$giftYear, $resolvedOwnerId]
        );

        $createdList = false;
        if (!$giftList) {
            $listStatement = dbExecute(
                $conn,
                'INSERT INTO gift_lists (gift_year, owner_pms_admin_id, created_by_id, updated_by_id) VALUES (?, ?, ?, ?)',
                'iiii',
                [$giftYear, $resolvedOwnerId, $actorId, $actorId]
            );
            $giftListId = (int) $listStatement->insert_id;
            $listStatement->close();
            $createdList = true;
        } else {
            $giftListId = (int) $giftList['id'];
        }

        $ownerKeypersonIds = array_map(
            static fn (array $keyperson): int => (int) $keyperson['id'],
            $ownerKeypersons
        );
        $existingItemIds = [];
        if ($ownerKeypersonIds !== []) {
            $existingPlaceholders = implode(',', array_fill(0, count($ownerKeypersonIds), '?'));
            $existingRows = dbFetchAll(
                $conn,
                "SELECT keyperson_id FROM gift_list_items WHERE gift_list_id = ? AND keyperson_id IN ({$existingPlaceholders})",
                'i' . str_repeat('i', count($ownerKeypersonIds)),
                array_merge([$giftListId], $ownerKeypersonIds)
            );
            foreach ($existingRows as $existingRow) {
                $existingItemIds[(int) $existingRow['keyperson_id']] = true;
            }
        }

        $listAdded = 0;
        $listSkipped = 0;
        foreach ($ownerKeypersons as $keyperson) {
            $keypersonId = (int) $keyperson['id'];
            if (isset($existingItemIds[$keypersonId])) {
                $listSkipped++;
                $totalSkipped++;
                continue;
            }

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
                    $keypersonId,
                    (int) $keyperson['clients_id'],
                    $giftRate,
                    $notes !== '' ? $notes : null,
                    (string) $keyperson['key_person'],
                    (string) $keyperson['clients_name'],
                    $actorId,
                    $actorId,
                ]
            )->close();
            $listAdded++;
            $totalAdded++;
        }

        if ($listAdded > 0) {
            dbExecute(
                $conn,
                'UPDATE gift_lists SET updated_by_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                'ii',
                [$actorId, $giftListId]
            )->close();
        }

        writeAuditLog($conn, $authUser, 'gift_list.keypersons_added', 'gift_list', $giftListId, [
            'gift_year' => $giftYear,
            'owner_pms_admin_id' => $resolvedOwnerId,
            'selected_count' => count($ownerKeypersons),
            'added_count' => $listAdded,
            'skipped_existing_count' => $listSkipped,
            'gift_rate' => $giftRate,
            'list_created' => $createdList,
        ]);

        $affectedLists[] = [
            'gift_list_id' => $giftListId,
            'owner_pms_admin_id' => $resolvedOwnerId,
            'created' => $createdList,
            'added_count' => $listAdded,
            'skipped_existing_count' => $listSkipped,
        ];
    }

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

if ($totalAdded === 0) {
    $message = "All selected key persons are already included in their {$giftYear} annual gift lists.";
} else {
    $message = $totalAdded === 1
        ? "1 key person was added to the {$giftYear} annual gift list."
        : "{$totalAdded} key persons were added to the {$giftYear} annual gift lists.";
    if ($totalSkipped > 0) {
        $message .= " {$totalSkipped} existing " . ($totalSkipped === 1 ? 'recipient was' : 'recipients were') . ' left unchanged.';
    }
}

jsonResponse([
    'status' => 'Success',
    'message' => $message,
    'data' => [
        'gift_year' => $giftYear,
        'gift_rate' => $giftRate,
        'selected_count' => count($keypersonIds),
        'added_count' => $totalAdded,
        'skipped_existing_count' => $totalSkipped,
        'affected_lists' => $affectedLists,
    ],
]);
