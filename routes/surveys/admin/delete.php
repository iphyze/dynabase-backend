<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/request.php';
require_once __DIR__ . '/../../../includes/surveys.php';
require_once __DIR__ . '/../../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Survey response ID');
requirePermission($conn, $authUser, 'client_surveys.delete');
$record = assertSurveyAccessible($conn, $authUser, $id);
if (isset($payload['invitation_id'])) {
    $linkId = requiredIntFromPayload($payload, 'invitation_id', 'Survey link ID');
    if ((int) ($record['invitation_id'] ?? 0) !== $linkId) {
        throw new RuntimeException('This response does not belong to the selected survey link.', 409);
    }
}

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
