<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/permissions.php';
require_once __DIR__ . '/../../../../includes/surveyAi.php';

requireMethod('GET');
header('Cache-Control: private, no-store');
$user = authenticateUser();
requirePermission($conn, $user, 'client_surveys.view');
requirePermission($conn, $user, 'client_surveys.ai.summarize');
$enabled = surveyAiReady();
jsonResponse([
    'status' => 'Success',
    'data' => [
        'enabled' => $enabled,
        'model' => $enabled ? envString('OPENAI_MODEL', 'gpt-4.1-mini') : null,
        'limits' => surveyAiQuotaUsage($conn),
        'max_links' => SURVEY_AI_MAX_LINKS,
        'max_responses' => SURVEY_AI_MAX_RESPONSES,
        'storage_note' => 'Summaries are not stored. Request metadata is retained for the configured period.',
    ],
]);
