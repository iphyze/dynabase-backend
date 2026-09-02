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
$keypersonId = (int) ($payload['keyperson_id'] ?? 0);
if ($keypersonId <= 0) {
    throw new RuntimeException('Please select a key person.', 422);
}
$decision = validateGiftDecision($payload['gift_decision'] ?? 'selected');
$rate = $decision === 'selected' ? validateGiftRate($payload['gift_rate'] ?? null) : null;
$notes = cleanString($payload['notes'] ?? '');
if (mb_strlen($notes) > 1000) {
    throw new RuntimeException('Gift-list notes cannot exceed 1000 characters.', 422);
}

$keyperson = fetchGiftListKeyperson($conn, $keypersonId);
$actorId = (int) $authUser['id'];
$giftListId = 0;
$conn->begin_transaction();
try {
    $giftListId = ensureGiftList($conn, $giftYear, $ownerPmsAdminId, $actorId);
    upsertGiftListItem($conn, $giftListId, $keyperson, $decision, $rate, $notes, $actorId);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

writeAuditLog($conn, $authUser, 'gift_list.item_updated', 'keyperson', $keypersonId, [
    'gift_year' => $giftYear,
    'owner_pms_admin_id' => $ownerPmsAdminId,
    'gift_decision' => $decision,
    'gift_rate' => $rate,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Gift decision saved.',
    'data' => [
        'keyperson_id' => $keypersonId,
        'gift_year' => $giftYear,
        'gift_decision' => $decision,
        'gift_rate' => $rate,
    ],
]);
