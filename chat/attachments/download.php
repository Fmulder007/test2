<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
$user=chat_current_user();if(!$user){http_response_code(401);exit('Требуется вход.');}
$id=max(0,(int)($_GET['id']??0));if(!$id){http_response_code(404);exit('Файл не найден.');}
$pdo=chat_db();$stmt=$pdo->prepare('SELECT a.id,a.original_name,a.extension,a.mime_type,a.size_bytes,a.data,m.is_deleted,m.user_id AS author_id FROM attachments a JOIN messages m ON m.id=a.message_id WHERE a.id=? LIMIT 1');$stmt->execute([$id]);$row=$stmt->fetch();
if(!$row || $row['is_deleted'] || ($user['ban_mode']??'none')==='BAN'){http_response_code(404);exit('Файл недоступен.');}
$name=(string)$row['original_name'];$ascii=preg_replace('/[^A-Za-z0-9._-]/','_',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name) ?: 'download');if($ascii==='')$ascii='download';

// Inline-режим разрешён только по явному запросу от превью и только для безопасного списка растровых изображений.
// Обычная ссылка [Скачать] не содержит view=1 и продолжает принудительно скачивать файл.
$mime=(string)$row['mime_type'];
$extension=strtolower((string)$row['extension']);
$imageMimes=['image/jpeg','image/png','image/gif','image/webp'];
$imageExtensions=['jpg','jpeg','png','gif','webp'];
$viewInline=((string)($_GET['view']??'')==='1')
    && in_array($mime,$imageMimes,true)
    && in_array($extension,$imageExtensions,true);
$disposition=$viewInline?'inline':'attachment';
$contentType=$viewInline?$mime:'application/octet-stream';
header('Content-Type: '.$contentType);
header('X-Content-Type-Options: nosniff');
header('Content-Length: '.(int)$row['size_bytes']);
header("Content-Disposition: {$disposition}; filename=\"" . str_replace('"', '', $ascii) . "\"; filename*=UTF-8''" . rawurlencode($name));
header('Cache-Control: private, no-store');
echo $row['data'];
