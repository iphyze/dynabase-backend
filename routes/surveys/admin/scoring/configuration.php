<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/surveyScoringConfigurations.php';

requireMethod('GET');
$authUser = authenticateUser();
$state = dbFetchOne($conn, 'SELECT active_configuration_id FROM client_survey_scoring_state WHERE id = 1');
if (!$state) throw new RuntimeException('Survey scoring has not been initialized. Apply the Batch 1 migration.', 503);
$versions = dbFetchAll($conn, "SELECT c.id, c.version_number, c.title, c.status, c.created_by, c.modified_by, c.activated_by,
    c.created_at, c.updated_at, c.activated_at,
    NULLIF(TRIM(CONCAT(COALESCE(editor.first_name, ''), ' ', COALESCE(editor.last_name, ''))), '') AS modified_by_name
    FROM client_survey_scoring_configurations c
    LEFT JOIN users editor ON editor.id = c.modified_by
    ORDER BY c.version_number DESC");
$draft = null;
foreach ($versions as $version) {
    if ($draft === null && $version['status'] === 'draft') $draft = surveyScoringLoad($conn, (int) $version['id']);
}
// Optional version inspection for the internal configuration history. The same
// manage permission is enforced by the central route-permission map.
$selected = null;
if (isset($_GET['configuration_id'])) {
    $selectedId = filter_var($_GET['configuration_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$selectedId) throw new RuntimeException('Invalid scoring configuration ID.', 422);
    $selected = surveyScoringLoad($conn, (int) $selectedId);
}
jsonResponse(['status' => 'Success', 'data' => [
    'selected' => $selected,
    'active' => surveyScoringLoad($conn, (int) $state['active_configuration_id']),
    'draft' => $draft,
    'versions' => $versions,
    'question_definitions' => [
        'performance' => STAKEHOLDER_SURVEY_V2_PERFORMANCE,
        'stakeholder' => STAKEHOLDER_SURVEY_V2_CATEGORIES,
        'service' => STAKEHOLDER_SURVEY_V2_SERVICE_QUESTIONS,
    ],
]]);
