<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/request.php';
require_once __DIR__.'/../../includes/aiChat.php';
requireMethod('POST');
header('Cache-Control: private, no-store');
$user=authenticateUser();
if (!aiChatReady()) throw new RuntimeException('AI Assistant is unavailable.',503);
if (empty($_FILES['file']) || !is_array($_FILES['file'])) throw new RuntimeException('Choose a file to attach.',422);
[$name,$ext,$mime,$size]=aiChatValidateUpload($_FILES['file']);
$userId=(int)$user['id'];
$threadRaw=$_POST['thread_id']??null;
$threadId=$threadRaw===null || $threadRaw==='' ? null : filter_var($threadRaw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if ($threadId===false) throw new RuntimeException('Invalid conversation ID.',422);
if ($threadId!==null) aiChatThread($conn,$userId,(int)$threadId);
aiChatPrune($conn);
$userUsage=dbFetchOne($conn,'SELECT COUNT(*) AS files, COALESCE(SUM(byte_size),0) AS bytes FROM dynabase_ai_chat_files WHERE user_id=?','i',[$userId]);
if ((int)$userUsage['files']>=300 || (int)$userUsage['bytes'] + $size > 100*1024*1024) {
    throw new RuntimeException('Your AI file storage allowance (100 MB or 300 files) has been reached. Delete old conversations to free space.',422);
}
if ($threadId!==null) {
    $row=dbFetchOne($conn,'SELECT COUNT(*) AS total FROM dynabase_ai_chat_files WHERE thread_id=?','i',[(int)$threadId]);
    if ((int)($row['total']??0)>=20) throw new RuntimeException('Conversation file limit reached (20 files). Start a new chat.',422);
}
$storage=bin2hex(random_bytes(32));
$path=aiChatFilePath($storage);
if (!move_uploaded_file((string)$_FILES['file']['tmp_name'],$path)) throw new RuntimeException('Could not store your attachment.',503);
@chmod($path,0600);
try {
    if ($threadId===null) {
        $stmt=dbExecute($conn,"INSERT INTO dynabase_ai_chat_threads (user_id,title) VALUES (?,'Files for analysis')",'i',[$userId]);
        $threadId=(int)$stmt->insert_id; $stmt->close();
    }
    $stmt=dbExecute($conn,"INSERT INTO dynabase_ai_chat_files (thread_id,user_id,kind,original_name,storage_name,extension,mime_type,byte_size) VALUES (?,?,'upload',?,?,?,?,?)",'iissssi',[(int)$threadId,$userId,$name,$storage,$ext,$mime,$size]);
    $id=(int)$stmt->insert_id; $stmt->close();
    $stmt=dbExecute($conn,'UPDATE dynabase_ai_chat_threads SET updated_at=UTC_TIMESTAMP() WHERE id=?','i',[(int)$threadId]);$stmt->close();
    writeAuditLog($conn,$user,'ai_chat.file_uploaded','ai_chat_thread',(int)$threadId,['file_id'=>$id,'extension'=>$ext,'size'=>$size]);
    jsonResponse(['status'=>'Success','data'=>['thread_id'=>(int)$threadId,'file'=>['id'=>$id,'name'=>$name,'size'=>$size,'extension'=>$ext,'kind'=>'upload']]]);
} catch(Throwable $e) { @unlink($path); throw $e; }
