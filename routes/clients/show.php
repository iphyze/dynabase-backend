<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';
require_once __DIR__ . '/../../includes/permissions.php';

requireMethod('GET');
$authUser = authenticateUser();
$id = requiredIntFromRequest('id');

[$clientScopeSql, $clientTypes, $clientParams] = appendScopedWhere($authUser, 'c', 'i', [$id]);
$client = dbFetchOne(
    $conn,
    "SELECT c.*,
            TRIM(CONCAT(COALESCE(owner.first_name, ''), ' ', COALESCE(owner.last_name, ''))) AS owner_pms_admin_name,
            owner.email AS owner_pms_admin_email,
            TRIM(CONCAT(COALESCE(creator.first_name, ''), ' ', COALESCE(creator.last_name, ''))) AS created_by_name,
            creator.email AS created_by_email
     FROM clients_table c
     LEFT JOIN users owner ON owner.id = c.owner_pms_admin_id
     LEFT JOIN users creator ON creator.id = c.created_by_id
     WHERE c.id = ?{$clientScopeSql}
     LIMIT 1",
    $clientTypes,
    $clientParams
);

if (!$client) {
    throw new RuntimeException('Client not found.', 404);
}

$canViewKeypersons = userHasPermission($conn, $authUser, 'keypersons.view');
$canViewLogs = userHasPermission($conn, $authUser, 'influence_logs.view');
$canViewGiftLists = userHasPermission($conn, $authUser, 'gift_lists.view');

$keypersons = [];
if ($canViewKeypersons) {
    [$keypersonScopeSql, $keypersonTypes, $keypersonParams] = appendScopedWhere($authUser, 'k', 'i', [$id]);
    $giftSelect = $canViewGiftLists
        ? 'k.gift_status, k.gift_type'
        : 'NULL AS gift_status, NULL AS gift_type';
    $keypersons = dbFetchAll(
        $conn,
        "SELECT k.id, k.key_person, k.key_persons_tel, k.key_persons_email, k.key_persons_address,
                {$giftSelect}, k.title, k.info, k.status, k.created_at, k.updated_at
         FROM keypersons_table k
         WHERE k.clients_id = ? AND k.status <> 'deactivated'{$keypersonScopeSql}
         ORDER BY k.key_person ASC",
        $keypersonTypes,
        $keypersonParams
    );
}

$logs = [];
if ($canViewLogs) {
    [$logScopeSql, $logTypes, $logParams] = appendScopedWhere($authUser, 'l', 'i', [$id]);
    $logs = dbFetchAll(
    $conn,
    "SELECT l.id, l.key_person, l.log, l.created_by, l.created_by_id, l.updated_by, l.updated_by_id, l.created_at, l.updated_at
     FROM log_table l
     WHERE l.clients_id = ?{$logScopeSql}
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT 20",
    $logTypes,
        $logParams
    );
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Client retrieved successfully.',
    'data' => [
        'client' => $client,
        'keypersons' => $keypersons,
        'recent_logs' => $logs,
        'access' => [
            'keypersons' => $canViewKeypersons,
            'influence_logs' => $canViewLogs,
            'gift_lists' => $canViewGiftLists,
        ],
    ],
]);
