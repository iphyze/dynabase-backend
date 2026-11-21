<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/request.php';
require_once __DIR__.'/../../includes/aiChat.php';
requireMethod('GET');
header('Cache-Control: private, no-store');
$user=authenticateUser();
aiChatPrune($conn);
$rows=dbFetchAll($conn,'SELECT id,title,created_at,updated_at FROM dynabase_ai_chat_threads WHERE user_id=? ORDER BY updated_at DESC LIMIT 100','i',[(int)$user['id']]);
jsonResponse(['status'=>'Success','data'=>['threads'=>$rows,'enabled'=>aiChatReady(),'limits'=>aiChatQuotaUsage($conn),'retention_days'=>aiChatExpiryDays()]]);
