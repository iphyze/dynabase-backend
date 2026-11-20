<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/surveyScoringConfigurations.php';
require_once __DIR__ . '/../../../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$id = filter_var($payload['configuration_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) throw new RuntimeException('A valid scoring configuration ID is required.', 422);
$result = surveyScoringActivate($conn, $authUser, (int) $id);
writeAuditLog($conn, $authUser, 'client_surveys.scoring_activated', 'client_survey_scoring_configuration', (int) $id, [
    'version_number' => $result['configuration']['version_number'],
    'previous_configuration_id' => $result['previous_configuration_id'],
]);
jsonResponse(['status' => 'Success', 'message' => 'Survey scoring configuration activated.', 'data' => $result]);
