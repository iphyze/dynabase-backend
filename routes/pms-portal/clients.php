<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';

requireMethod('GET');
pmsPortalCurrentUser($conn);
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$limit = max(1, min((int) ($_GET['limit'] ?? 100), 100));
$search = '%' . addcslashes($q, '%_\\') . '%';
$items = dbFetchAll($conn,
    'SELECT c.id, c.clients_name FROM clients_table c
     WHERE c.status = "active" AND (c.clients_name LIKE ? OR c.clients_email LIKE ?)
     ORDER BY c.clients_name ASC LIMIT ?',
    'ssi', [$search, $search, $limit]
);
header('Cache-Control: no-store');
jsonResponse(['status' => 'Success', 'data' => array_map(
    static fn (array $row): array => ['value' => (string) $row['id'], 'label' => $row['clients_name'], 'id' => (int) $row['id']],
    $items
)]);
