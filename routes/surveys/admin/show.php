<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/request.php';
require_once __DIR__ . '/../../../includes/surveys.php';
require_once __DIR__ . '/../../../includes/audit.php';

requireMethod('GET');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN]);
$id = requiredIntFromRequest('id');
$record = assertSurveyAccessible($conn, $authUser, $id);

if (($record['response_status'] ?? '') === 'submitted') {
    $stmt = dbExecute(
        $conn,
        "UPDATE clients_survey_form SET response_status = 'reviewed', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, updatedAt = CURRENT_TIMESTAMP WHERE id = ?",
        'ii',
        [(int) $authUser['id'], $id]
    );
    $stmt->close();
    $record = assertSurveyAccessible($conn, $authUser, $id);
    writeAuditLog($conn, $authUser, 'client_surveys.reviewed', 'client_survey', $id);
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Client survey response retrieved successfully.',
    'data' => $record,
]);
