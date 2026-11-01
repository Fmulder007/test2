<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';

$user=chat_current_user();
if(!$user){http_response_code(401);exit('Требуется вход.');}
if(($user['ban_mode']??'none')==='BAN'){http_response_code(404);exit('Миниатюра недоступна.');}
$id=max(0,(int)($_GET['id']??0));
if(!$id){http_response_code(404);exit('Миниатюра не найдена.');}
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();

$pdo=chat_db();
$stmt=$pdo->prepare("SELECT a.thumbnail_data,a.thumbnail_mime_type,m.is_deleted FROM attachments a JOIN messages m ON m.id=a.message_id WHERE a.id=? LIMIT 1");
$stmt->execute([$id]);
$row=$stmt->fetch();
if(!$row || !empty($row['is_deleted']) || !is_string($row['thumbnail_data']) || $row['thumbnail_data']==='' || (string)$row['thumbnail_mime_type']!=='image/png'){
    http_response_code(404);exit('Миниатюра недоступна.');
}
header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header('Content-Length: '.strlen($row['thumbnail_data']));
header('Cache-Control: private, no-store');
echo $row['thumbnail_data'];
