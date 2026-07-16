<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/request.php';
require_once __DIR__ . '/../../../includes/surveys.php';
require_once __DIR__ . '/../../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Survey response ID');
$record = assertSurveyAccessible($conn, $authUser, $id);

$stmt = dbExecute(
    $conn,
    'UPDATE clients_survey_form SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ?, updatedAt = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL',
    'ii',
    [(int) $authUser['id'], $id]
);
$stmt->close();
writeAuditLog($conn, $authUser, 'client_surveys.deleted', 'client_survey', $id, [
    'reference' => $record['submission_reference'],
    'company' => $record['company'],
]);

jsonResponse(['status' => 'Success', 'message' => 'Client survey response deleted successfully.']);
