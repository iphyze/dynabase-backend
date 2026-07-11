<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';

requireMethod('GET');
$authUser = authenticateUser();
$id = requiredIntFromRequest('id');
assertClientAccessible($conn, $authUser, $id, true);

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
     WHERE c.id = ?
     LIMIT 1",
    'i',
    [$id]
);

$keypersons = dbFetchAll(
    $conn,
    "SELECT id, key_person, key_persons_tel, key_persons_email, key_persons_address,
            gift_status, gift_type, title, info, status, created_at, updated_at
     FROM keypersons_table
     WHERE clients_id = ? AND status <> 'deactivated'
     ORDER BY key_person ASC",
    'i',
    [$id]
);

$logs = dbFetchAll(
    $conn,
    "SELECT id, key_person, log, created_by, created_by_id, updated_by, updated_by_id, created_at, updated_at
     FROM log_table
     WHERE clients_id = ?
     ORDER BY created_at DESC, id DESC
     LIMIT 20",
    'i',
    [$id]
);

jsonResponse([
    'status' => 'Success',
    'message' => 'Client retrieved successfully.',
    'data' => [
        'client' => $client,
        'keypersons' => $keypersons,
        'recent_logs' => $logs,
    ],
]);
