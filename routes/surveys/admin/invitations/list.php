<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/surveys.php';
require_once __DIR__ . '/../../../../includes/pagination.php';

requireMethod('GET');
$authUser = authenticateUser();
[$page, $limit, $offset] = paginationParams();

$q = cleanString($_GET['q'] ?? $_GET['search'] ?? '');
$status = cleanString($_GET['status'] ?? '');
$where = ' WHERE 1 = 1';
$types = '';
$params = [];
if ($q !== '') {
    $where .= ' AND (i.recipient_name LIKE ? OR i.recipient_email LIKE ? OR i.client_name_snapshot LIKE ? OR i.project_title_snapshot LIKE ? OR c.clients_name LIKE ? OR p.project_title LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ssssss';
    $params = array_merge($params, array_fill(0, 6, $like));
}
if ($status === 'active') {
    $where .= " AND i.status = 'active' AND i.expires_at >= NOW() AND i.submission_count < i.max_submissions";
} elseif ($status === 'completed') {
    $where .= " AND (i.status = 'completed' OR i.submission_count >= i.max_submissions)";
} elseif ($status === 'revoked') {
    $where .= " AND i.status = 'revoked'";
} elseif ($status === 'expired') {
    $where .= " AND i.status = 'active' AND i.expires_at < NOW()";
}

$total = dbScalarInt($conn, 'SELECT COUNT(*) AS total FROM client_survey_invitations i LEFT JOIN clients_table c ON c.id = i.client_id LEFT JOIN project_info_table p ON p.id = i.project_id' . $where, $types, $params);
$items = dbFetchAll(
    $conn,
    invitationSelectSql() . $where . ' ORDER BY i.created_at DESC, i.id DESC LIMIT ? OFFSET ?',
    $types . 'ii',
    array_merge($params, [$limit, $offset])
);

jsonResponse([
    'status' => 'Success',
    'message' => 'Client survey links retrieved successfully.',
    'data' => [
        'items' => array_map('castSurveyInvitation', $items),
        'pagination' => paginationMeta($page, $limit, $total),
    ],
]);
