<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/giftListEmails.php';

requireMethod('GET');
$actor = authenticateUser();
giftEmailRequireSender($conn, $actor);
header('Cache-Control: no-store, private');

// Expand a saved batch without exposing recipient lists to unauthorized users.
if (isset($_GET['batch_id'])) {
    $rawId = $_GET['batch_id'];
    if ((!is_string($rawId) && !is_int($rawId))
        || !ctype_digit((string) $rawId) || (int) $rawId <= 0) {
        throw new RuntimeException('Invalid email batch.', 422);
    }
    $batchId = (int) $rawId;
    $batches = dbFetchAll($conn,
        'SELECT b.id, b.email_type AS type, b.gift_year, b.subject, b.created_at,
                b.recipient_count, CONCAT_WS(\' \', u.first_name, u.last_name) AS sender_name
         FROM gift_list_email_batches b
         LEFT JOIN users u ON u.id = b.created_by_id
         WHERE b.id = ? LIMIT 1',
        'i', [$batchId]
    );
    if (!$batches) {
        throw new RuntimeException('Email batch not found.', 404);
    }
    $deliveries = dbFetchAll($conn,
        'SELECT d.id, d.recipient_email, d.delivery_status AS status, d.error_message, d.created_at,
                CONCAT_WS(\' \', u.first_name, u.last_name) AS recipient_name
         FROM gift_list_email_deliveries d
         LEFT JOIN users u ON u.id = d.recipient_user_id
         WHERE d.batch_id = ? ORDER BY d.id',
        'i', [$batchId]
    );
    jsonResponse(['status' => 'Success', 'data' => [
        'batch' => $batches[0], 'deliveries' => $deliveries,
    ]]);
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;
$year = isset($_GET['gift_year']) ? validateGiftYear($_GET['gift_year']) : null;
$where = $year === null ? '' : 'WHERE b.gift_year = ?';
$types = $year === null ? '' : 'i';
$params = $year === null ? [] : [$year];
$total = dbScalarInt($conn, "SELECT COUNT(*) AS total FROM gift_list_email_batches b {$where}", $types, $params);
$items = dbFetchAll($conn,
    "SELECT b.id, b.email_type AS type, b.gift_year, b.subject, b.created_at, b.recipient_count,
            CONCAT_WS(' ', u.first_name, u.last_name) AS sender_name,
            COALESCE(SUM(CASE WHEN d.delivery_status = 'sent' THEN 1 ELSE 0 END),0) AS sent,
            COALESCE(SUM(CASE WHEN d.delivery_status = 'failed' THEN 1 ELSE 0 END),0) AS failed,
            COALESCE(SUM(CASE WHEN d.delivery_status = 'logged' THEN 1 ELSE 0 END),0) AS logged_not_delivered
     FROM gift_list_email_batches b
     LEFT JOIN users u ON u.id = b.created_by_id
     LEFT JOIN gift_list_email_deliveries d ON d.batch_id = b.id
     {$where}
     GROUP BY b.id
     ORDER BY b.id DESC
     LIMIT ? OFFSET ?",
    $types . 'ii', array_merge($params, [$limit, $offset])
);
jsonResponse(['status' => 'Success', 'data' => [
    'items' => $items,
    'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)],
]]);
