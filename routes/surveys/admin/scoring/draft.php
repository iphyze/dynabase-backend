<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/surveyScoringConfigurations.php';
require_once __DIR__ . '/../../../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$result = surveyScoringSaveDraft($conn, $authUser, $payload);
writeAuditLog($conn, $authUser, 'client_surveys.scoring_draft_saved', 'client_survey_scoring_configuration', $result['configuration']['id'], [
    'version_number' => $result['configuration']['version_number'], 'totals_valid' => $result['validation']['valid'],
]);
jsonResponse(['status' => 'Success', 'message' => 'Scoring draft saved.', 'data' => $result]);
