<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/projects.php';

requireMethod('GET');
$authUser = authenticateUser();

[$page, $limit, $offset] = paginationParams();
$q = cleanString($_GET['q'] ?? '');
$action = cleanString($_GET['action'] ?? '');
$entityType = cleanString($_GET['entity_type'] ?? '');
$actorRole = cleanString($_GET['actor_role'] ?? '');
$actorUserId = (int) ($_GET['actor_user_id'] ?? 0);
$dateFrom = cleanString($_GET['date_from'] ?? '');
$dateTo = cleanString($_GET['date_to'] ?? '');

$where = 'WHERE 1 = 1';
$types = '';
$params = [];

if ($q !== '') {
    $where .= ' AND (a.`action` LIKE ? OR a.`entity_type` LIKE ? OR a.`entity_id` LIKE ? OR a.`metadata` LIKE ? OR u.`email` LIKE ? OR CONCAT(u.`first_name`, \' \', u.`last_name`) LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ssssss';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

if ($action !== '') {
    $where .= ' AND a.`action` = ?';
    $types .= 's';
    $params[] = $action;
}

if ($entityType !== '') {
    $where .= ' AND a.`entity_type` = ?';
    $types .= 's';
    $params[] = $entityType;
}

if ($actorRole !== '') {
    $where .= ' AND a.`actor_role` = ?';
    $types .= 's';
    $params[] = $actorRole;
}

if ($actorUserId > 0) {
    $where .= ' AND a.`actor_user_id` = ?';
    $types .= 'i';
    $params[] = $actorUserId;
}

if ($dateFrom !== '') {
    $fromTs = strtotime($dateFrom);
    if ($fromTs === false) {
        throw new RuntimeException('date_from must be a valid date.', 422);
    }
    $where .= ' AND a.`created_at` >= ?';
    $types .= 's';
    $params[] = date('Y-m-d 00:00:00', $fromTs);
}

if ($dateTo !== '') {
    $toTs = strtotime($dateTo);
    if ($toTs === false) {
        throw new RuntimeException('date_to must be a valid date.', 422);
    }
    $where .= ' AND a.`created_at` <= ?';
    $types .= 's';
    $params[] = date('Y-m-d 23:59:59', $toTs);
}

$total = dbScalarInt(
    $conn,
    "SELECT COUNT(*) AS total FROM `audit_logs` a LEFT JOIN `users` u ON u.`id` = a.`actor_user_id` {$where}",
    $types,
    $params
);

$rows = dbFetchAll(
    $conn,
    "SELECT a.`id`, a.`actor_user_id`, a.`actor_role`, a.`action`, a.`entity_type`, a.`entity_id`, a.`metadata`, a.`created_at`,
            u.`first_name`, u.`last_name`, u.`email`
     FROM `audit_logs` a
     LEFT JOIN `users` u ON u.`id` = a.`actor_user_id`
     {$where}
     ORDER BY a.`created_at` DESC, a.`id` DESC
     LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

foreach ($rows as &$row) {
    $row['id'] = (int) $row['id'];
    $row['actor_user_id'] = $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null;
    $row['actor_name'] = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
    $row['metadata'] = $row['metadata'] ? json_decode((string) $row['metadata'], true) : null;
    unset($row['first_name'], $row['last_name']);
}
unset($row);

jsonResponse([
    'status' => 'Success',
    'message' => 'Audit logs retrieved successfully.',
    'data' => $rows,
    'meta' => paginationMeta($page, $limit, $total),
]);
