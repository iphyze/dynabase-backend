<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/request.php';
require_once __DIR__.'/../../includes/aiChat.php';
requireMethod('POST');$user=authenticateUser();
$p=readJsonBody();$id=filter_var($p['thread_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if ($id===false) throw new RuntimeException('Invalid conversation ID.',422);
aiChatThread($conn,(int)$user['id'],(int)$id);
$fileNames=dbFetchAll($conn,'SELECT storage_name FROM dynabase_ai_chat_files WHERE thread_id=?','i',[(int)$id]);
$stmt=dbExecute($conn,'DELETE FROM dynabase_ai_chat_threads WHERE id=? AND user_id=?','ii',[(int)$id,(int)$user['id']]);$stmt->close();
foreach ($fileNames as $file) @unlink(aiChatFilePath((string)$file['storage_name']));
writeAuditLog($conn,$user,'ai_chat.deleted','ai_chat_thread',(int)$id,[]);
jsonResponse(['status'=>'Success','message'=>'Conversation deleted.']);
