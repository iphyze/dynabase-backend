<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/giftLists.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$giftYear = validateGiftYear($payload['gift_year'] ?? date('Y'));
$requestedOwner = isset($payload['owner_pms_admin_id']) ? (int) $payload['owner_pms_admin_id'] : null;
$ownerPmsAdminId = resolveGiftOwnerPmsAdminId($conn, $authUser, $requestedOwner);
$decision = validateGiftDecision($payload['gift_decision'] ?? 'pending');
$rate = validateGiftRate($payload['gift_rate'] ?? null, $decision);
$ids = $payload['keyperson_ids'] ?? $payload['ids'] ?? [];
if (!is_array($ids)) {
    throw new RuntimeException('Please select at least one key person.', 422);
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
if ($ids === []) {
    throw new RuntimeException('Please select at least one key person.', 422);
}

$actorId = (int) $authUser['id'];
$giftListId = ensureGiftList($conn, $giftYear, $ownerPmsAdminId, $actorId);
$processed = 0;
$conn->begin_transaction();
try {
    foreach ($ids as $id) {
        $keyperson = assertGiftKeypersonOwnedBy($conn, $id, $ownerPmsAdminId);
        upsertGiftListItem($conn, $giftListId, $keyperson, $decision, $rate, '', $actorId);
        $processed++;
    }
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

writeAuditLog($conn, $authUser, 'gift_list.bulk_updated', 'gift_list', $giftListId, [
    'gift_year' => $giftYear,
    'owner_pms_admin_id' => $ownerPmsAdminId,
    'gift_decision' => $decision,
    'gift_rate' => $rate,
    'keyperson_ids' => $ids,
]);

jsonResponse([
    'status' => 'Success',
    'message' => "{$processed} gift-list record(s) updated.",
    'data' => ['updated_count' => $processed],
]);
