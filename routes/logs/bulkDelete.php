<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$ids = $payload['ids'] ?? [];

if (!is_array($ids)) {
    throw new RuntimeException('Please select at least one influence log.', 422);
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
if ($ids === []) {
    throw new RuntimeException('Please select at least one influence log.', 422);
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));
$params = $ids;
[$scopeSql, $scopeTypes, $scopeParams] = appendScopedWhere($authUser, 'l');
$types .= $scopeTypes;
$params = array_merge($params, $scopeParams);

$accessibleCount = dbScalarInt(
    $conn,
    "SELECT COUNT(*) AS total FROM log_table l WHERE l.id IN ({$placeholders}){$scopeSql}",
    $types,
    $params
);

if ($accessibleCount !== count($ids)) {
    throw new RuntimeException('One or more selected influence logs were not found.', 404);
}

dbExecute(
    $conn,
    "DELETE FROM log_table WHERE id IN ({$placeholders})",
    str_repeat('i', count($ids)),
    $ids
)->close();

writeAuditLog($conn, $authUser, 'influence_log.bulk_deleted', 'influence_log', null, [
    'ids' => $ids,
    'deleted_count' => count($ids),
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Selected influence logs deleted successfully.',
    'data' => ['deleted_count' => count($ids)],
]);
