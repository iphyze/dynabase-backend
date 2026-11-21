<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/aiChat.php';

requireMethod('POST');
header('Cache-Control: private, no-store');
$user = authenticateUser();
$payload = readJsonBody();
$threadId = filter_var($payload['thread_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_int($threadId)) {
    throw new RuntimeException('Invalid conversation ID.', 422);
}

if (!isset($payload['title']) || !is_string($payload['title'])) {
    throw new RuntimeException('Conversation name is required.', 422);
}
$title = trim($payload['title']);
if ($title === '' || preg_match('//u', $title) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $title)) {
    throw new RuntimeException('Enter a valid conversation name without line breaks.', 422);
}
$length = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : preg_match_all('/./us', $title);
if (!is_int($length) || $length > 180) {
    throw new RuntimeException('Conversation name must be 180 characters or fewer.', 422);
}

$userId = (int) $user['id'];
$thread = aiChatThread($conn, $userId, $threadId);
if ((string) $thread['title'] !== $title) {
    // Ownership is checked again in the write to prevent cross-account updates.
    $stmt = dbExecute($conn,
        'UPDATE dynabase_ai_chat_threads SET title=?, updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?',
        'sii', [$title, $threadId, $userId]
    );
    $changed = $stmt->affected_rows;
    $stmt->close();
    if ($changed < 1) throw new RuntimeException('Conversation not found.', 404);
    // Do not place conversation text in audit metadata.
    writeAuditLog($conn, $user, 'ai_chat.renamed', 'ai_chat_thread', $threadId);
}

$updated = aiChatThread($conn, $userId, $threadId);
jsonResponse(['status' => 'Success', 'message' => 'Conversation renamed.', 'data' => ['thread' => $updated]]);
