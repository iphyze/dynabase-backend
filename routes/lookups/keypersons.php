<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/ownership.php';
require_once __DIR__ . '/../../includes/lookup.php';
require_once __DIR__ . '/../../includes/permissions.php';

requireMethod('GET');
$authUser = authenticateUser();

$q = lookupSearchTerm();
$clientId = (int) ($_GET['client_id'] ?? 0);
$category = cleanString($_GET['category'] ?? '');
$status = cleanString($_GET['status'] ?? 'active');
$limit = lookupLimit(100, 100);
$offset = lookupOffset();
$includeOther = cleanString($_GET['include_other'] ?? '') === '1';
$referenceScope = cleanString($_GET['reference_scope'] ?? '');
$viewerOwnerPmsAdminId = resolveOwnerPmsAdminId($authUser);

if ($referenceScope !== '' && $referenceScope !== 'gift_lists') {
    throw new RuntimeException('Invalid key person reference scope.', 422);
}

$isSharedReference = $referenceScope === 'gift_lists';
if ($isSharedReference) {
    $canUseReference = userHasPermission($conn, $authUser, 'gift_lists.create')
        || userHasPermission($conn, $authUser, 'gift_lists.edit');
    if (!$canUseReference) {
        throw new RuntimeException('You are not authorised to use the shared key person reference lookup.', 403);
    }

    if ($clientId <= 0) {
        throw new RuntimeException('Please select a client before loading shared key person references.', 422);
    }

    if ($status !== '' && $status !== 'active') {
        throw new RuntimeException('Shared key person references only include active records.', 422);
    }
}

$where = ' WHERE 1 = 1';
$types = '';
$params = [];

if ($status !== '' && $status !== 'all') {
    if (!in_array($status, ['active', 'inactive', 'deactivated'], true)) {
        throw new RuntimeException('Invalid status filter.', 422);
    }
    $where .= ' AND k.status = ?';
    $types .= 's';
    $params[] = $status;
}

if ($clientId > 0) {
    $where .= ' AND k.clients_id = ?';
    $types .= 'i';
    $params[] = $clientId;
}

if ($category !== '') {
    $where .= ' AND k.clients_category = ?';
    $types .= 's';
    $params[] = $category;
}

if ($q !== '') {
    $where .= ' AND (k.key_person LIKE ? OR k.key_persons_email LIKE ? OR k.clients_name LIKE ? OR k.title LIKE ?)';
    $like = likeTerm($q);
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}

if (!$isSharedReference) {
    [$scopeSql, $scopeTypes, $scopeParams] = appendKeypersonScopedWhere($authUser, 'k');
    $where .= $scopeSql;
    $types .= $scopeTypes;
    $params = array_merge($params, $scopeParams);
}

$total = dbScalarInt($conn, "SELECT COUNT(*) AS total FROM keypersons_table k{$where}", $types, $params);
$rows = dbFetchAll(
    $conn,
    "SELECT k.id, k.clients_id, k.clients_name, k.clients_category, k.key_person,
            k.key_persons_tel, k.key_persons_email, k.title
     FROM keypersons_table k
     {$where}
     ORDER BY k.key_person ASC, k.clients_name ASC
     LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

$data = array_map(static function (array $row) use ($isSharedReference, $viewerOwnerPmsAdminId): array {
    $name = (string) $row['key_person'];
    $client = (string) $row['clients_name'];
    $label = $client !== '' ? $name . ' — ' . $client : $name;

    $meta = [
        'id' => (int) $row['id'],
        'clients_id' => (int) $row['clients_id'],
        'clients_name' => $row['clients_name'],
        'clients_category' => $row['clients_category'],
        'key_person' => $row['key_person'],
        'key_persons_tel' => $row['key_persons_tel'],
        'key_persons_email' => $row['key_persons_email'],
        'title' => $row['title'],
    ];

    // Shared references deliberately do not expose PMS ownership information.
    if (!$isSharedReference && $viewerOwnerPmsAdminId !== null && $viewerOwnerPmsAdminId > 0) {
        // PMS-scoped viewers may only receive their own/parent assignment context.
        $meta['owner_pms_admin_id'] = $viewerOwnerPmsAdminId;
    }

    return optionRow((int) $row['id'], $label, $meta);
}, $rows);

if ($includeOther && $offset === 0) {
    $otherLabel = $category !== '' ? 'Other ' . $category . ' Key Person' : 'Other Key Person';
    $data[] = optionRow('__other__', $otherLabel, ['is_other' => true, 'category' => $category]);
}

lookupResponse('Key person lookup retrieved successfully.', $data, $limit, $offset, $total);
