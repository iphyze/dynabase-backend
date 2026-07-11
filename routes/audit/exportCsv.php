<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/projects.php';

requireMethod('GET');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN], 'Only Super Admin can download audit logs.');

$q = cleanString($_GET['q'] ?? '');
$action = cleanString($_GET['action'] ?? '');
$entityType = cleanString($_GET['entity_type'] ?? '');
$actorRole = cleanString($_GET['actor_role'] ?? '');
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
if ($action !== '') { $where .= ' AND a.`action` = ?'; $types .= 's'; $params[] = $action; }
if ($entityType !== '') { $where .= ' AND a.`entity_type` = ?'; $types .= 's'; $params[] = $entityType; }
if ($actorRole !== '') { $where .= ' AND a.`actor_role` = ?'; $types .= 's'; $params[] = $actorRole; }
if ($dateFrom !== '') { $fromTs = strtotime($dateFrom); if ($fromTs === false) { throw new RuntimeException('date_from must be a valid date.', 422); } $where .= ' AND a.`created_at` >= ?'; $types .= 's'; $params[] = date('Y-m-d 00:00:00', $fromTs); }
if ($dateTo !== '') { $toTs = strtotime($dateTo); if ($toTs === false) { throw new RuntimeException('date_to must be a valid date.', 422); } $where .= ' AND a.`created_at` <= ?'; $types .= 's'; $params[] = date('Y-m-d 23:59:59', $toTs); }

$rows = dbFetchAll(
    $conn,
    "SELECT a.`id`, a.`actor_user_id`, a.`actor_role`, a.`action`, a.`entity_type`, a.`entity_id`, a.`metadata`, a.`created_at`,
            u.`first_name`, u.`last_name`, u.`email`
     FROM `audit_logs` a
     LEFT JOIN `users` u ON u.`id` = a.`actor_user_id`
     {$where}
     ORDER BY a.`created_at` DESC, a.`id` DESC
     LIMIT 5000",
    $types,
    $params
);

$filename = 'dynabase-audit-logs-' . date('Y-m-d-His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Actor Name', 'Actor Email', 'Actor Role', 'Action', 'Entity Type', 'Entity ID', 'Metadata', 'Created At']);

foreach ($rows as $row) {
    fputcsv($out, [
        $row['id'],
        trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
        $row['email'] ?? '',
        $row['actor_role'] ?? '',
        $row['action'] ?? '',
        $row['entity_type'] ?? '',
        $row['entity_id'] ?? '',
        $row['metadata'] ?? '',
        $row['created_at'] ?? '',
    ]);
}

fclose($out);
exit;
