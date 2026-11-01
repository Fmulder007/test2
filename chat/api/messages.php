<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/messages.php';
$user=chat_require_user();
$pdo=chat_db();
$date=isset($_GET['date'])?(string)$_GET['date']:null;
$before=max(0,(int)($_GET['before_id']??0));
$after=max(0,(int)($_GET['after_id']??0));
$target=max(0,(int)($_GET['target_id']??0));
try{
    $data=chat_query_messages($pdo,$user,$date,$before,$after,$target);
    $data['ok']=true;
    $data['pinned']=($after===0 && $before===0 && $target===0)?chat_get_pinned($pdo,$user):[];
    chat_json($data);
}catch(Throwable $e){
    error_log('messages api: '.$e->getMessage());
    chat_audit('messages_api_error',(int)$user['id'],(string)$user['email'],null,null,['endpoint'=>'api/messages.php','exception'=>substr($e->getMessage(),0,1000)]);
    chat_json(['ok'=>false,'error'=>'Не удалось загрузить сообщения.'],500);
}
