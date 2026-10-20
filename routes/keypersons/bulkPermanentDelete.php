<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/permanentDeletion.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$ids = $payload['ids'] ?? null;
if (!is_array($ids)) {
    throw new RuntimeException('Select the key persons to permanently delete.', 422);
}
$count = permanentlyDeleteDirectoryRecords($conn, $authUser, 'keyperson', $ids);
jsonResponse(['status' => 'Success', 'message' => "{$count} key person(s) permanently deleted.", 'data' => ['deleted' => $count]]);
