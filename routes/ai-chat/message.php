<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/request.php';
require_once __DIR__.'/../../includes/aiChat.php';
requireMethod('POST'); header('Cache-Control: private, no-store');
$user=authenticateUser();
if (!aiChatReady()) throw new RuntimeException('AI Assistant is disabled or its server-side API key is missing.',503);
$payload=readJsonBody();
$text=trim((string)($payload['message']??''));
if (strlen($text)>4000) throw new RuntimeException('Message cannot exceed 4,000 characters.',422);
$fileIds = $payload['file_ids'] ?? [];
if (!is_array($fileIds) || count($fileIds)>3) throw new RuntimeException('Up to three attachments can be sent per message.',422);
$fileIds = array_values(array_unique(array_map(static fn($v)=>(int)$v,$fileIds)));
if (!$text && !$fileIds) throw new RuntimeException('Please enter a message or attach a file.',422);
if ($text==='') $text='Please review the attached files and tell me what you can help with.';
$threadId=filter_var($payload['thread_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$userId=(int)$user['id'];
if ($fileIds && $threadId===false) throw new RuntimeException('Please upload the files to a conversation before sending.',422);
aiChatPrune($conn);
if ($threadId===false) {
    $title=trim(function_exists('mb_substr')?mb_substr(preg_replace('/\s+/u',' ',$text)??$text,0,80):substr(preg_replace('/\s+/u',' ',$text)??$text,0,80));
    $stmt=dbExecute($conn,'INSERT INTO dynabase_ai_chat_threads (user_id,title) VALUES (?,?)','is',[$userId,$title?:'New conversation']);
    $threadId=(int)$stmt->insert_id; $stmt->close();
} else { aiChatThread($conn,$userId,(int)$threadId); }
$threadId=(int)$threadId;
$attachedFiles=[];
foreach ($fileIds as $fileId) {
    if ($fileId<=0) throw new RuntimeException('Invalid attachment ID.',422);
    $row=aiChatOwnFile($conn,$fileId,$userId,$threadId);
    if ($row['kind']!=='upload') throw new RuntimeException('Generated files cannot be sent as uploaded attachments.',422);
    $attachedFiles[]=$row;
}
// Only files explicitly selected for this message are sent to the AI provider.
// Follow-up questions may reuse a previous attachment from the message-history UI.
$totalFileBytes=array_sum(array_map(static fn($file)=>(int)$file['byte_size'],$attachedFiles));
if ($totalFileBytes > 10*1024*1024) throw new RuntimeException('Combined attachments must be smaller than 10 MB per message.',422);
// Allow brief follow-ups (for example: "Check") to refer to the user's latest
// actual record question in this same user-owned thread. Do not use assistant
// guesses as lookup instructions or cross into another user's conversation.
$lookupText = $text;
if (!$fileIds && preg_match('/^(?:please\s+)?(?:check|verify|check it|please do|go ahead)[.!?\s]*$/iu', $text)) {
    $previousUser = dbFetchOne($conn, "SELECT body FROM dynabase_ai_chat_messages WHERE thread_id=? AND role='user' ORDER BY id DESC LIMIT 1", 'i', [$threadId]);
    if ($previousUser && aiChatReadIntent((string)$previousUser['body']) !== null) {
        $lookupText = (string)$previousUser['body'];
    }
}
// Server-owned, read-only lookups use the authenticated principal and the same
// view/ownership boundaries as the corresponding Dynabase modules.
// A message that includes attachments is treated as file analysis instead.
$readResult = $fileIds ? null : aiChatReadContext($conn, $user, $lookupText);
if ($readResult !== null && isset($readResult['reply'])) {
    $stmt = dbExecute($conn, "INSERT INTO dynabase_ai_chat_messages (thread_id,role,body) VALUES (?,'user',?)", 'is', [$threadId,$text]);
    $stmt->close();
    $reply = (string)$readResult['reply'];
    $stmt = dbExecute($conn, "INSERT INTO dynabase_ai_chat_messages (thread_id,role,body) VALUES (?,'assistant',?)", 'is', [$threadId,$reply]);
    $messageId = (int)$stmt->insert_id;
    $stmt->close();
    $stmt = dbExecute($conn, 'UPDATE dynabase_ai_chat_threads SET updated_at=UTC_TIMESTAMP() WHERE id=?', 'i', [$threadId]);
    $stmt->close();
    writeAuditLog($conn, $user, 'ai_chat.read_only_lookup', 'ai_chat_thread', $threadId, ['source'=>$readResult['source']??'scope-check']);
    jsonResponse(['status'=>'Success','data'=>['thread_id'=>$threadId,'message'=>['id'=>$messageId,'role'=>'assistant','body'=>$reply,'action'=>null,'files'=>[]],'limits'=>aiChatQuotaUsage($conn)]]);
    return;
}
$model=aiChatModel();
$requestId=aiChatReserveQuota($conn,$threadId,$userId,$model);
$allowedActionTypes = [
    'create_client' => userHasPermission($conn, $user, 'clients.create'),
    'create_tender' => userHasPermission($conn, $user, 'tenders.create'),
    'create_keyperson' => userHasPermission($conn, $user, 'keypersons.create'),
    'create_gift_list' => userHasPermission($conn, $user, 'gift_lists.create') || userHasPermission($conn, $user, 'gift_lists.edit'),
];
try {
    $stmt=dbExecute($conn,"INSERT INTO dynabase_ai_chat_messages (thread_id,role,body) VALUES (?,'user',?)",'is',[$threadId,$text]);
    $userMessageId=(int)$stmt->insert_id; $stmt->close();
    foreach ($fileIds as $fileId) {
        $stmt=dbExecute($conn,'UPDATE dynabase_ai_chat_files SET message_id=COALESCE(message_id, ?) WHERE id=? AND thread_id=? AND user_id=?','iiii',[$userMessageId,$fileId,$threadId,$userId]);$stmt->close();
    }
    $recent=dbFetchAll($conn,'SELECT role,body FROM dynabase_ai_chat_messages WHERE thread_id=? ORDER BY id DESC LIMIT 12','i',[$threadId]);
    $verifiedContext = isset($readResult['context']) && is_array($readResult['context']) ? $readResult['context'] : null;
    $answer=aiChatCallProvider(array_reverse($recent), $verifiedContext === null ? $allowedActionTypes : [], $model, $attachedFiles, $verifiedContext);
    // Read-only tools are never allowed to turn database facts or feedback into write drafts.
    if ($verifiedContext !== null) $answer['action'] = null;
    if ($verifiedContext !== null) {
        // Always show deterministic totals, regardless of model wording.
        $exact = (int)$verifiedContext['current_survey_link_count'].' currently stored survey link(s); '
               .(int)$verifiedContext['total_non_deleted_linked_responses'].' linked response(s); '
               .(int)$verifiedContext['total_non_deleted_unlinked_historical_responses'].' standalone/historical response(s).';
        $coverage = !empty($verifiedContext['sample_is_complete'])
            ? 'All linked responses were included in the feedback analysis.'
            : 'The feedback analysis covers only the most recent '.(int)$verifiedContext['sample_size'].' of '.(int)$verifiedContext['total_non_deleted_linked_responses'].' linked responses; it is not a complete review.';
        $answer['reply'] = "Verified Dynabase totals: {$exact}\n{$coverage}\n\n".$answer['reply'];
    }
    $action=$answer['action']; $type=$action['type']??null;
    $actionJson=$action?json_encode($action['fields'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null;
    $actionState=$action?'pending':null;
    $stmt=dbExecute($conn,"INSERT INTO dynabase_ai_chat_messages (thread_id,role,body,action_type,action_json,action_status) VALUES (?,'assistant',?,?,?,?)",'issss',[$threadId,$answer['reply'],$type,$actionJson,$actionState]);$messageId=(int)$stmt->insert_id;$stmt->close();
    $generatedFile=null;
    try { $generatedFile=aiChatWriteGenerated($conn,$userId,$threadId,$messageId,$answer['file']??null); }
    catch(Throwable $fileError) {
        error_log('[Dynabase AI Chat] File generation failed: '.$fileError->getMessage());
        $answer['reply'] .= "\n\nThe downloadable file could not be prepared. Please try another format.";
        $stmt=dbExecute($conn,"UPDATE dynabase_ai_chat_messages SET body=? WHERE id=?",'si',[$answer['reply'],$messageId]);$stmt->close();
    }
    if (!empty($answer['file']) && !$generatedFile && !str_contains($answer['reply'],'downloadable file could not')) {
        $answer['reply'] .= "\n\nA downloadable file was requested, but could not be generated in the supported format.";
        $stmt=dbExecute($conn,'UPDATE dynabase_ai_chat_messages SET body=? WHERE id=?','si',[$answer['reply'],$messageId]);$stmt->close();
    }
    $stmt=dbExecute($conn,'UPDATE dynabase_ai_chat_threads SET updated_at=UTC_TIMESTAMP() WHERE id=?','i',[$threadId]);$stmt->close();
    aiChatCompleteQuota($conn,$requestId,true,$answer['usage']);
    writeAuditLog($conn,$user,'ai_chat.message','ai_chat_thread',$threadId,['model'=>$model,'proposed_action'=>$type,'request_id'=>$requestId]);
    jsonResponse(['status'=>'Success','data'=>['thread_id'=>$threadId,'message'=>['id'=>$messageId,'role'=>'assistant','body'=>$answer['reply'],'action'=>$action?array_merge($action,['status'=>'pending']):null,'files'=>$generatedFile?[$generatedFile]:[]],'limits'=>aiChatQuotaUsage($conn)]]);
} catch(Throwable $e) {
    try { aiChatCompleteQuota($conn,$requestId,false); } catch(Throwable $logFailure) {error_log('[Dynabase AI] quota metadata write failed: '.$logFailure->getMessage());}
    throw $e;
}
