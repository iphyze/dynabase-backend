<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/permissions.php';
require_once __DIR__ . '/../../../../includes/surveyAi.php';

requireMethod('POST');
header('Cache-Control: private, no-store');
$actor = authenticateUser();
requirePermission($conn, $actor, 'client_surveys.view');
requirePermission($conn, $actor, 'client_surveys.ai.summarize');
if (!surveyAiReady()) {
    throw new RuntimeException('Survey AI summaries are not enabled or the API key is not configured.', 503);
}
$model = trim(envString('OPENAI_MODEL', 'gpt-4.1-mini'));
if (!preg_match('/^[A-Za-z0-9._-]{2,100}$/', $model)) throw new RuntimeException('The configured AI model is invalid.', 503);
$payload = readJsonBody();
$facts = surveyAiFactsForRequest($conn, $payload);
$inputJson = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (strlen($inputJson) > SURVEY_AI_MAX_INPUT_CHARS) throw new RuntimeException('Too much feedback was selected. Choose fewer links.', 422);
$requestId = surveyAiReserveQuota($conn, (int) $actor['id'], $facts['scope'], count($facts['responses']), count($facts['link_ids']), $model);
try {
    $result = surveyAiCallOpenAi($facts, $model);
    // A response may have been soft-deleted while the AI request was in flight.
    $ids = $facts['response_ids'];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $liveCount = dbScalarInt($conn,
        "SELECT COUNT(*) AS total FROM clients_survey_form WHERE id IN ({$marks}) AND deleted_at IS NULL",
        str_repeat('i', count($ids)), $ids);
    if ($liveCount !== count($ids)) throw new RuntimeException('Survey responses changed during analysis. Refresh and run a new summary.', 409);
    surveyAiComplete($conn, $requestId, true, $result['usage']);
    writeAuditLog($conn, $actor, 'client_surveys.ai_summarized', 'client_survey_ai_request', $requestId, [
        'scope' => $facts['scope'], 'response_count' => count($facts['responses']), 'link_count' => count($facts['link_ids']),
        'model' => $model,
    ]);
    try { surveyAiPurgeOldMetadata($conn); } catch (Throwable $cleanupError) {
        error_log('[Dynabase Survey AI] Metadata cleanup skipped: ' . $cleanupError->getMessage());
    }
    jsonResponse([
        'status' => 'Success',
        'message' => 'AI summary generated successfully.',
        'data' => [
            'summary' => $result['text'],
            'scope' => $facts['scope'],
            'response_count' => count($facts['responses']),
            'link_count' => count($facts['link_ids']),
            'model' => $model,
            'generated_at' => gmdate('c'),
            'limits' => surveyAiQuotaUsage($conn),
        ],
    ]);
} catch (Throwable $exception) {
    try { surveyAiComplete($conn, $requestId, false); } catch (Throwable $writeError) {
        error_log('[Dynabase Survey AI] Failed request status update: ' . $writeError->getMessage());
    }
    throw $exception;
}
