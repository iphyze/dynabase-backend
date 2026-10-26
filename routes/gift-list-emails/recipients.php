<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/giftListEmails.php';

requireMethod('GET');
$actor = authenticateUser();
giftEmailRequireSender($conn, $actor);
$year = validateGiftYear($_GET['gift_year'] ?? date('Y'));
$group = trim((string) ($_GET['group'] ?? 'pms_admins'));
$search = trim((string) ($_GET['search'] ?? ''));
$onlyUnstarted = filter_var($_GET['only_unstarted'] ?? false, FILTER_VALIDATE_BOOLEAN);
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = max(1, min(100, (int) ($_GET['limit'] ?? 30)));
$offset = ($page - 1) * $limit;
$options = ['group' => $group, 'search' => $search, 'only_unstarted' => $onlyUnstarted];
[$from, $types, $params] = giftEmailRecipientsSql($year, $options);
$total = dbScalarInt($conn, 'SELECT COUNT(*) AS total ' . $from, $types, $params);
$owner = giftEmailOwnerSql();
$rows = dbFetchAll($conn,
    "SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.status, u.is_pms_admin,
            u.pms_portal_only, u.parent_pms_admin_id,
            {$owner} AS pms_owner_id,
            CONCAT_WS(' ', parent.first_name, parent.last_name) AS parent_name,
            gl.id AS gift_list_id,
            (SELECT COUNT(*) FROM gift_list_items gi
             WHERE gi.gift_list_id = gl.id AND gi.gift_decision = 'selected') AS selected_recipients
       {$from}
       ORDER BY u.first_name, u.last_name, u.id
       LIMIT ? OFFSET ?",
    $types . 'ii', array_merge($params, [$limit, $offset])
);
$items = [];
foreach ($rows as $row) {
    if (!dynabaseIsDeliverableEmail($row['email'])) continue;
    $items[] = giftEmailRecipientPayload($row);
}
jsonResponse([
    'status' => 'Success',
    'data' => [
        'items' => $items,
        'gift_year' => $year,
        'groups' => array_keys(giftEmailGroups()),
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)],
    ],
]);
