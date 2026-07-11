<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/request.php';

requireMethod('GET');

$authUser = authenticateUser();
$userId = (int) $authUser['id'];
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = max(1, min(50, (int) ($_GET['limit'] ?? 10)));
$offset = ($page - 1) * $limit;
$status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$category = strtolower(trim((string) ($_GET['category'] ?? 'all')));
$search = trim((string) ($_GET['search'] ?? $_GET['q'] ?? ''));

if (!notificationsTableExists($conn)) {
    jsonResponse([
        'status' => 'Success',
        'data' => [
            'items' => [],
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'total_pages' => 1],
            'summary' => ['total' => 0, 'unread' => 0, 'read' => 0, 'today' => 0],
            'categories' => [],
        ],
    ]);
}

$where = ' WHERE n.user_id = ? AND n.deleted_at IS NULL';
$types = 'i';
$params = [$userId];

if ($status === 'unread') {
    $where .= ' AND n.read_at IS NULL';
} elseif ($status === 'read') {
    $where .= ' AND n.read_at IS NOT NULL';
}

if ($category !== '' && $category !== 'all') {
    $where .= ' AND n.category = ?';
    $types .= 's';
    $params[] = $category;
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= ' AND (n.title LIKE ? OR n.message LIKE ? OR n.entity_type LIKE ?)';
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}

$total = dbScalarInt($conn, "SELECT COUNT(*) AS total FROM notifications n{$where}", $types, $params);
$listTypes = $types . 'ii';
$listParams = [...$params, $limit, $offset];

$items = dbFetchAll(
    $conn,
    "SELECT n.id, n.category, n.severity, n.title, n.message, n.action_url,
            n.entity_type, n.entity_id, n.read_at, n.created_at,
            n.actor_user_id,
            NULLIF(TRIM(CONCAT(COALESCE(actor.first_name, ''), ' ', COALESCE(actor.last_name, ''))), '') AS actor_name
     FROM notifications n
     LEFT JOIN users actor ON actor.id = n.actor_user_id
     {$where}
     ORDER BY n.created_at DESC, n.id DESC
     LIMIT ? OFFSET ?",
    $listTypes,
    $listParams
);

$summary = dbFetchOne(
    $conn,
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread,
            SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) AS `read`,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today
     FROM notifications
     WHERE user_id = ? AND deleted_at IS NULL",
    'i',
    [$userId]
) ?? [];

$categories = dbFetchAll(
    $conn,
    "SELECT category AS value, COUNT(*) AS total
     FROM notifications
     WHERE user_id = ? AND deleted_at IS NULL
     GROUP BY category
     ORDER BY category ASC",
    'i',
    [$userId]
);

jsonResponse([
    'status' => 'Success',
    'data' => [
        'items' => array_map(static function (array $item): array {
            return [
                'id' => (int) $item['id'],
                'category' => (string) $item['category'],
                'severity' => (string) $item['severity'],
                'title' => (string) $item['title'],
                'message' => (string) $item['message'],
                'action_url' => $item['action_url'] ?: null,
                'entity_type' => $item['entity_type'] ?: null,
                'entity_id' => $item['entity_id'] ?: null,
                'read_at' => $item['read_at'] ?: null,
                'created_at' => (string) $item['created_at'],
                'actor_user_id' => $item['actor_user_id'] !== null ? (int) $item['actor_user_id'] : null,
                'actor_name' => $item['actor_name'] ?: null,
                'is_read' => $item['read_at'] !== null,
            ];
        }, $items),
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / $limit)),
        ],
        'summary' => [
            'total' => (int) ($summary['total'] ?? 0),
            'unread' => (int) ($summary['unread'] ?? 0),
            'read' => (int) ($summary['read'] ?? 0),
            'today' => (int) ($summary['today'] ?? 0),
        ],
        'categories' => array_map(static fn (array $item): array => [
            'value' => (string) $item['value'],
            'total' => (int) $item['total'],
        ], $categories),
    ],
]);
