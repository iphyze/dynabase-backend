<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/permanentDeletion.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$ids = $payload['ids'] ?? null;
if (!is_array($ids)) {
    throw new RuntimeException('Select the clients to permanently delete.', 422);
}
$count = permanentlyDeleteDirectoryRecords($conn, $authUser, 'client', $ids);
jsonResponse(['status' => 'Success', 'message' => "{$count} client(s) permanently deleted.", 'data' => ['deleted' => $count]]);
