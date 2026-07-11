<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/projects.php';

requireMethod('POST');
$authUser = authenticateUser();
requireRole($authUser, [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN], 'Only Super Admins and Admins can manage tenders.');
$payload = readJsonBody();
$codes = parseProjectCodesFromPayload($payload);

$newStatus = array_key_exists('project_status', $payload) && cleanString($payload['project_status']) !== ''
    ? normalizeProjectStatus((string) $payload['project_status'])
    : null;
$newProgress = array_key_exists('progress', $payload) && cleanString($payload['progress']) !== ''
    ? normalizeProjectProgress((string) $payload['progress'])
    : null;

if ($newStatus === null && $newProgress === null) {
    throw new RuntimeException('Please provide at least one status/progress value to update.', 422);
}

$placeholders = projectCodesPlaceholders($codes);
$types = str_repeat('i', count($codes));
$params = $codes;
[$scopeSql, $types, $params] = projectScopeForBulk($authUser, 'p', $types, $params);

$accessible = dbFetchAll(
    $conn,
    "SELECT p.* FROM `project_info_table` p WHERE p.`code` IN ({$placeholders}) AND p.`record_status` = 'active'{$scopeSql}",
    $types,
    $params
);

if ($accessible === []) {
    throw new RuntimeException('No selected tenders are available for status update.', 404);
}

$accessibleCodes = array_map(static fn (array $row): int => (int) $row['code'], $accessible);
$accessiblePlaceholders = projectCodesPlaceholders($accessibleCodes);
$actorEmail = actorEmail($authUser);
$actorId = (int) $authUser['id'];

$setSql = [];
$updateTypes = '';
$updateParams = [];

if ($newStatus !== null) {
    $setSql[] = '`project_status` = ?';
    $updateTypes .= 's';
    $updateParams[] = $newStatus;
}

if ($newProgress !== null) {
    $setSql[] = '`progress` = ?';
    $updateTypes .= 's';
    $updateParams[] = $newProgress;
}

$setSql[] = '`updated_by` = ?';
$setSql[] = '`updated_by_id` = ?';
$setSql[] = '`updated_at` = CURRENT_TIMESTAMP';
$updateTypes .= 'si' . str_repeat('i', count($accessibleCodes));
$updateParams = array_merge($updateParams, [$actorEmail, $actorId], $accessibleCodes);

$conn->begin_transaction();
try {
    dbExecute(
        $conn,
        'UPDATE `project_info_table` SET ' . implode(', ', $setSql) . " WHERE `code` IN ({$accessiblePlaceholders})",
        $updateTypes,
        $updateParams
    )->close();

    if ($newProgress !== null) {
        foreach ($accessible as $row) {
            $row['progress'] = $newProgress;
            if ($newStatus !== null) {
                $row['project_status'] = $newStatus;
            }
            syncAwardedProjectCode($conn, $authUser, $row);
        }
    }

    writeAuditLog($conn, $authUser, 'project.bulk_status_updated', 'project', null, [
        'requested_codes' => $codes,
        'updated_codes' => $accessibleCodes,
        'updated_count' => count($accessibleCodes),
        'project_status' => $newStatus,
        'progress' => $newProgress,
    ]);

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

jsonResponse([
    'status' => 'Success',
    'message' => count($accessibleCodes) . ' tender(s) updated successfully.',
    'data' => [
        'updated_codes' => $accessibleCodes,
        'updated_count' => count($accessibleCodes),
        'project_status' => $newStatus,
        'progress' => $newProgress,
    ],
]);
