<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/giftLists.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/permissions.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$ids = $payload['ids'] ?? [];
$action = cleanString($payload['action'] ?? '');

if (!is_array($ids)) {
    throw new RuntimeException('Please select at least one key person.', 422);
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
if ($ids === []) {
    throw new RuntimeException('Please select at least one key person.', 422);
}

if (!in_array($action, ['activate', 'deactivate', 'gift_yes', 'gift_no'], true)) {
    throw new RuntimeException('Please select a valid bulk action.', 422);
}

if (in_array($action, ['gift_yes', 'gift_no'], true)) {
    requirePermission($conn, $authUser, 'gift_lists.edit', 'You do not have permission to change annual gift-list decisions.');
} else {
    requirePermission($conn, $authUser, 'keypersons.edit', 'You do not have permission to change key-person status.');
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));
$params = $ids;
[$scopeSql, $scopeTypes, $scopeParams] = appendKeypersonScopedWhere($authUser, 'k');
$types .= $scopeTypes;
$params = array_merge($params, $scopeParams);

$accessibleRows = dbFetchAll(
    $conn,
    "SELECT k.id, k.clients_id, k.clients_name, k.key_person
     FROM keypersons_table k
     WHERE k.id IN ({$placeholders}){$scopeSql}",
    $types,
    $params
);

if (count($accessibleRows) !== count($ids)) {
    throw new RuntimeException('One or more selected key persons were not found.', 404);
}

$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];
$message = 'Selected key persons updated successfully.';

if (in_array($action, ['activate', 'deactivate'], true)) {
    $status = $action === 'activate' ? 'active' : 'deactivated';
    dbExecute(
        $conn,
        "UPDATE keypersons_table k
         SET status = ?, updated_by = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP
         WHERE k.id IN ({$placeholders}){$scopeSql}",
        'ssi' . $types,
        [$status, $actorEmail, $actorId, ...$params]
    )->close();
    $message = $action === 'activate'
        ? 'Selected key persons activated successfully.'
        : 'Selected key persons deactivated successfully.';
} else {
    $giftYear = validateGiftYear($payload['gift_year'] ?? date('Y'));
    $decision = $action === 'gift_yes' ? 'selected' : 'not_selected';
    $rate = $decision === 'selected' ? validateGiftRate($payload['gift_type'] ?? null) : null;
    $requestedOwnerId = isset($payload['owner_pms_admin_id']) ? (int) $payload['owner_pms_admin_id'] : null;
    $giftOwnerPmsAdminId = resolveGiftOwnerPmsAdminId($conn, $authUser, $requestedOwnerId);

    $conn->begin_transaction();
    try {
        $giftListId = ensureGiftList($conn, $giftYear, $giftOwnerPmsAdminId, $actorId);
        foreach ($accessibleRows as $keyperson) {
            upsertGiftListItem($conn, $giftListId, $keyperson, $decision, $rate, '', $actorId);
        }
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    $message = $action === 'gift_yes'
        ? "Selected key persons added to the {$giftYear} gift list."
        : "Selected key persons marked as not selected for {$giftYear}.";
}

writeAuditLog($conn, $authUser, 'keyperson.bulk_updated', 'keyperson', null, [
    'action' => $action,
    'ids' => $ids,
    'gift_year' => $payload['gift_year'] ?? null,
]);

jsonResponse([
    'status' => 'Success',
    'message' => $message,
    'data' => ['updated_count' => count($ids)],
]);
