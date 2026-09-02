<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/giftLists.php';

requireMethod('GET');
$authUser = authenticateUser();
$q = cleanString($_GET['search'] ?? ($_GET['q'] ?? ''));
$giftYear = (int) ($_GET['gift_year'] ?? 0);
$ownerPmsAdminId = (int) ($_GET['owner_pms_admin_id'] ?? 0);
$clientId = (int) ($_GET['client_id'] ?? 0);
$giftRate = cleanString($_GET['gift_rate'] ?? '');

if ($giftYear > 0) validateGiftYear($giftYear);
if ($giftRate !== '' && !in_array($giftRate, DYNABASE_GIFT_RATES, true)) {
    throw new RuntimeException('Invalid gift-rate filter.', 422);
}

$where = " WHERE items.gift_decision = 'selected'";
$types = '';
$params = [];
$fixedOwnerId = giftListFixedOwnerId($authUser);
if (!isGlobalDataUser($authUser)) {
    if ($fixedOwnerId === null) {
        $where .= ' AND 1 = 0';
    } else {
        $where .= ' AND gl.owner_pms_admin_id = ?';
        $types .= 'i';
        $params[] = $fixedOwnerId;
    }
} elseif ($ownerPmsAdminId > 0) {
    $where .= ' AND gl.owner_pms_admin_id = ?';
    $types .= 'i';
    $params[] = $ownerPmsAdminId;
}
if ($giftYear > 0) {
    $where .= ' AND gl.gift_year = ?';
    $types .= 'i';
    $params[] = $giftYear;
}
if ($clientId > 0) {
    $where .= ' AND items.client_id = ?';
    $types .= 'i';
    $params[] = $clientId;
}
if ($giftRate !== '') {
    $where .= ' AND items.gift_rate = ?';
    $types .= 's';
    $params[] = $giftRate;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $where .= " AND (CONCAT_WS(' ', owner.first_name, owner.last_name) LIKE ? OR owner.email LIKE ? OR items.keyperson_name_snapshot LIKE ? OR items.client_name_snapshot LIKE ?)";
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}

$rows = dbFetchAll(
    $conn,
    "SELECT gl.gift_year,
            CONCAT_WS(' ', owner.first_name, owner.last_name) AS owner_name,
            owner.email AS owner_email,
            items.client_name_snapshot, items.keyperson_name_snapshot, items.gift_rate, items.notes,
            k.title, k.key_persons_email, k.key_persons_tel,
            items.source, items.is_verified, items.updated_at
     FROM gift_lists gl
     INNER JOIN users owner ON owner.id = gl.owner_pms_admin_id
     INNER JOIN gift_list_items items ON items.gift_list_id = gl.id
     LEFT JOIN keypersons_table k ON k.id = items.keyperson_id
     {$where}
     ORDER BY gl.gift_year DESC, owner.first_name ASC, items.client_name_snapshot ASC, items.keyperson_name_snapshot ASC",
    $types,
    $params
);

$filename = 'dynabase-gift-lists-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fputcsv($output, ['Gift Year', 'PMS Owner', 'Owner Email', 'Client', 'Key Person', 'Designation', 'Email', 'Telephone', 'Gift Rate', 'Notes', 'Source', 'Verified', 'Updated At']);
foreach ($rows as $row) {
    fputcsv($output, [
        $row['gift_year'] ?? '',
        trim((string) ($row['owner_name'] ?? '')),
        $row['owner_email'] ?? '',
        $row['client_name_snapshot'] ?? '',
        $row['keyperson_name_snapshot'] ?? '',
        $row['title'] ?? '',
        $row['key_persons_email'] ?? '',
        $row['key_persons_tel'] ?? '',
        $row['gift_rate'] ?? '',
        $row['notes'] ?? '',
        $row['source'] ?? '',
        isset($row['is_verified']) ? ((int) $row['is_verified'] === 1 ? 'Yes' : 'No') : '',
        $row['updated_at'] ?? '',
    ]);
}
fclose($output);
exit;
