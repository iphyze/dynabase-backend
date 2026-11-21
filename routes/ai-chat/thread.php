<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/request.php';
require_once __DIR__.'/../../includes/aiChat.php';
requireMethod('GET'); header('Cache-Control: private, no-store');
$user=authenticateUser();
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if ($id===false) throw new RuntimeException('Invalid conversation ID.',422);
$thread=aiChatThread($conn,(int)$user['id'],(int)$id);
jsonResponse(['status'=>'Success','data'=>['thread'=>$thread,'messages'=>aiChatMessages($conn,(int)$id)]]);
