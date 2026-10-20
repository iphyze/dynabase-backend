<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/pmsPortalAuth.php';
require_once __DIR__ . '/../../includes/dbHelpers.php';

requireMethod('GET');
pmsPortalCurrentUser($conn);
$clientId = (int) ($_GET['client_id'] ?? 0);
if ($clientId <= 0) {
    throw new RuntimeException('Select a client before searching for key persons.', 422);
}
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$limit = max(1, min((int) ($_GET['limit'] ?? 100), 100));
$search = '%' . addcslashes($q, '%_\\') . '%';
$items = dbFetchAll($conn,
    'SELECT k.id, k.key_person, k.clients_id
     FROM keypersons_table k JOIN clients_table c ON c.id = k.clients_id
     WHERE k.clients_id = ? AND k.status = "active" AND c.status = "active"
       AND (k.key_person LIKE ? OR k.key_persons_email LIKE ?)
     ORDER BY k.key_person ASC LIMIT ?',
    'issi', [$clientId, $search, $search, $limit]
);
header('Cache-Control: no-store');
jsonResponse(['status' => 'Success', 'data' => array_map(
    static fn (array $row): array => ['value' => (string) $row['id'], 'label' => $row['key_person'], 'id' => (int) $row['id'], 'clients_id' => (int) $row['clients_id']],
    $items
)]);
