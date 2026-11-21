<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/aiChat.php';
require_once __DIR__ . '/../../includes/aiChatActions.php';
requireMethod('POST');
$user = authenticateUser();
$p = readJsonBody();
$messageId = filter_var($p['message_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$recordId = trim((string) ($p['record_id'] ?? ''));
if ($messageId === false) throw new RuntimeException('Invalid AI action message ID.', 422);
if ($recordId === '' || !ctype_digit($recordId) || (int) $recordId < 1) {
    throw new RuntimeException('Invalid saved record ID.', 422);
}
$review = $p['review'] ?? [];
if (!is_array($review)) throw new RuntimeException('Invalid review details.', 422);

// A message-row lock serializes confirmations; a replay never creates a second
// chat message. The actual domain record is checked under the same transaction.
$conn->begin_transaction();
$wasNew = false;
try {
    $row = dbFetchOne($conn,
        "SELECT m.thread_id,m.action_type,m.action_json,m.action_status,m.action_record_id
         FROM dynabase_ai_chat_messages m JOIN dynabase_ai_chat_threads t ON t.id=m.thread_id
         WHERE m.id=? AND m.role='assistant' AND t.user_id=? FOR UPDATE",
        'ii', [(int) $messageId, (int) $user['id']]);
    if (!$row) throw new RuntimeException('AI action not found or unavailable.', 404);
    $type = (string) ($row['action_type'] ?? '');
    $draft = json_decode((string) ($row['action_json'] ?? ''), true) ?: [];
    aiChatActionPermission($conn, $user, $type);
    $alreadyDone = $row['action_status'] === 'completed' && (string) $row['action_record_id'] === $recordId;
    if (!$alreadyDone && $row['action_status'] !== 'pending') {
        throw new RuntimeException('This AI action was already completed or dismissed.', 409);
    }
    if (!$alreadyDone) {
        $verified = aiChatVerifiedCreatedRecord($conn, $user, $type, $draft, (int) $recordId, $review);
        $stmt = dbExecute($conn,
            "UPDATE dynabase_ai_chat_messages SET action_status='completed',action_record_id=? WHERE id=? AND action_status='pending'",
            'si', [$recordId, (int) $messageId]);
        $updated = $stmt->affected_rows;
        $stmt->close();
        if ($updated !== 1) throw new RuntimeException('AI action already processed.', 409);
        $reply = aiChatActionSuccessText($verified);
        dbExecute($conn, "INSERT INTO dynabase_ai_chat_messages(thread_id,role,body) VALUES(?,'assistant',?)",
            'is', [(int) $row['thread_id'], $reply])->close();
        dbExecute($conn, 'UPDATE dynabase_ai_chat_threads SET updated_at=UTC_TIMESTAMP() WHERE id=?',
            'i', [(int) $row['thread_id']])->close();
        $wasNew = true;
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    throw $e;
}
if ($wasNew) {
    writeAuditLog($conn, $user, 'ai_chat.action_confirmed', $type, $recordId, [
        'message_id' => (int) $messageId, 'operation' => 'create',
    ]);
}
jsonResponse(['status' => 'Success', 'message' => 'AI action confirmed and saved to chat history.']);
