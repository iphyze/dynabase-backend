<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/giftLists.php';
require_once __DIR__ . '/../../includes/audit.php';

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

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));
$params = $ids;
[$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'k');
$types .= $scopeTypes;
$params = array_merge($params, $scopeParams);

$accessibleRows = dbFetchAll(
    $conn,
    "SELECT k.id, k.clients_id, k.clients_name, k.key_person, k.owner_pms_admin_id
     FROM keypersons_table k
     WHERE k.id IN ({$placeholders}){$scopeSql}",
    $types,
    $params
);

if (count($accessibleRows) !== count($ids)) {
    throw new RuntimeException('One or more selected key persons are not accessible.', 403);
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
    $rate = validateGiftRate($payload['gift_type'] ?? null, $decision);
    $listIds = [];

    $conn->begin_transaction();
    try {
        foreach ($accessibleRows as $keyperson) {
            $ownerId = (int) ($keyperson['owner_pms_admin_id'] ?? 0);
            if ($ownerId <= 0) {
                throw new RuntimeException('One or more selected key persons do not yet have a PMS owner.', 422);
            }
            if (!isset($listIds[$ownerId])) {
                $listIds[$ownerId] = ensureGiftList($conn, $giftYear, $ownerId, $actorId);
            }
            upsertGiftListItem($conn, $listIds[$ownerId], $keyperson, $decision, $rate, '', $actorId);
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
