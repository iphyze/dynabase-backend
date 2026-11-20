<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/surveys.php';
require_once __DIR__ . '/../../../../includes/pagination.php';

requireMethod('GET');
$authUser = authenticateUser();
$id = requiredIntFromRequest();
if (!dbFetchOne($conn, 'SELECT id FROM client_survey_invitations WHERE id = ? LIMIT 1', 'i', [$id])) {
    throw new RuntimeException('Survey link not found.', 404);
}
[$page, $limit, $offset] = paginationParams();
$q = cleanString($_GET['q'] ?? '');
$status = cleanString($_GET['status'] ?? '');
if ($status !== '' && !in_array($status, ['not_requested', 'queued', 'processing', 'accepted', 'failed', 'uncertain'], true)) {
    throw new RuntimeException('Invalid invitation email status filter.', 422);
}
$where = ' WHERE r.invitation_id = ?';
$types = 'i';
$params = [$id];
if ($q !== '') {
    $where .= ' AND (r.recipient_email LIKE ? OR r.recipient_name LIKE ?)';
    $types .= 'ss';
    $like = '%' . $q . '%';
    array_push($params, $like, $like);
}
if ($status !== '') {
    $where .= ' AND r.mail_status = ?';
    $types .= 's';
    $params[] = $status;
}
$total = dbScalarInt($conn, 'SELECT COUNT(*) AS total FROM client_survey_invitation_recipients r' . $where, $types, $params);
$items = dbFetchAll($conn,
    'SELECT r.id, r.recipient_email, r.recipient_name, r.mail_status, r.attempt_count, r.last_error, '
    . 'r.last_attempted_at, r.accepted_at, r.created_at, '
    . "CASE WHEN r.attempt_count < 5 AND (r.mail_status = 'not_requested' OR "
    . "(r.mail_status = 'accepted' AND (r.last_attempted_at IS NULL OR r.last_attempted_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)))) "
    . 'THEN 1 ELSE 0 END AS can_manual_resend '
    . 'FROM client_survey_invitation_recipients r' . $where . ' ORDER BY r.id ASC LIMIT ? OFFSET ?',
    $types . 'ii', array_merge($params, [$limit, $offset])
);
foreach ($items as &$row) {
    $row['id'] = (int) $row['id'];
    $row['attempt_count'] = (int) $row['attempt_count'];
    $row['can_manual_resend'] = (int) $row['can_manual_resend'] === 1;
}
unset($row);
jsonResponse([
    'status' => 'Success',
    'message' => 'Email recipients for this survey link retrieved successfully.',
    'data' => ['items' => $items, 'pagination' => paginationMeta($page, $limit, $total)],
]);
