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
if ($status !== '' && !in_array($status, ['submitted', 'reviewed', 'archived'], true)) {
    throw new RuntimeException('Invalid response status filter.', 422);
}
$where = ' WHERE s.invitation_id = ? AND s.deleted_at IS NULL';
$types = 'i';
$params = [$id];
if ($q !== '') {
    $where .= ' AND (s.submission_reference LIKE ? OR s.filled_by LIKE ? OR s.company LIKE ? OR s.project_title LIKE ? OR s.email LIKE ?)';
    $types .= 'sssss';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($status !== '') {
    $where .= ' AND s.response_status = ?';
    $types .= 's';
    $params[] = $status;
}
$total = dbScalarInt($conn, 'SELECT COUNT(*) AS total FROM clients_survey_form s' . $where, $types, $params);
$rows = dbFetchAll($conn,
    'SELECT s.id, s.submission_reference, s.filled_by, s.company, s.project_title, s.email, s.createdAt, '
    . 's.response_status, s.overall_score, s.survey_version, s.survey_rated_count, s.scoring_configuration_id '
    . 'FROM clients_survey_form s' . $where . ' ORDER BY s.createdAt DESC, s.id DESC LIMIT ? OFFSET ?',
    $types . 'ii', array_merge($params, [$limit, $offset])
);
$items = array_map(static function (array $row): array {
    $row['id'] = (int) $row['id'];
    $row['survey_version'] = (int) $row['survey_version'];
    $row['survey_rated_count'] = (int) $row['survey_rated_count'];
    $row['scoring_configuration_id'] = $row['scoring_configuration_id'] === null ? null : (int) $row['scoring_configuration_id'];
    $row['overall_score'] = (float) $row['overall_score'];
    $rated = $row['survey_version'] === 1 || $row['survey_rated_count'] > 0;
    $row['score_label'] = !$rated ? 'Not rated' : surveyScoreLabel($row['overall_score']);
    if ($row['survey_version'] === 2 && $row['score_label'] === 'Poor') $row['score_label'] = 'Needs Improvement';
    $row['scoring_method'] = $row['scoring_configuration_id'] !== null ? 'Weighted' : 'Historical';
    return $row;
}, $rows);
jsonResponse([
    'status' => 'Success',
    'message' => 'Responses for this survey link retrieved successfully.',
    'data' => ['items' => $items, 'pagination' => paginationMeta($page, $limit, $total)],
]);
