<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';require_once dirname(__DIR__).'/includes/messages.php';require_once dirname(__DIR__).'/includes/attachments.php';require_once dirname(__DIR__).'/includes/chunk_uploads.php';
chat_require_method('POST');chat_verify_csrf();$user=chat_require_user();
if(($user['ban_mode']??'none')!=='none')chat_json(['ok'=>false,'error'=>'Вы не можете отправлять сообщения при действующей блокировке.'],403);
// Запоминаем ID сессии до снятия блокировки: он нужен для поиска временных частей файлов.
$uploadSessionId = session_id();
// Do not hold the PHP session lock during file validation and DB writes.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$pdo=chat_db();$interval=max(1,chat_cfg_int('limits','message_interval_seconds',5));
$raw=(string)($_POST['segments']??'');$segments=json_decode($raw,true);if(!is_array($segments)||count($segments)>100)chat_json(['ok'=>false,'error'=>'Некорректное содержимое сообщения.'],400);
$clean=[];$plainCount=0;$notifications=[];$maxChars=max(100,chat_cfg_int('limits','message_max_chars',4000));
foreach($segments as $seg){if(!is_array($seg))continue;$type=(string)($seg['type']??'');
 if($type==='text'){$text=(string)($seg['text']??'');if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$text))chat_json(['ok'=>false,'error'=>'Сообщение содержит управляющие символы.'],400);if($text!==''){$clean[]=['type'=>'text','text'=>$text];$plainCount+=mb_strlen($text,'UTF-8');}}
 elseif($type==='mention'){$targetId=(int)($seg['user_id']??0);$st=$pdo->prepare("SELECT id,nick FROM users WHERE id=? AND account_status='active' LIMIT 1");$st->execute([$targetId]);$target=$st->fetch();if(!$target)chat_json(['ok'=>false,'error'=>'Упомянутый пользователь не найден.'],400);$clean[]=['type'=>'mention','user_id'=>$targetId,'nick'=>(string)$target['nick']];$plainCount+=mb_strlen('@'.$target['nick'],'UTF-8');if($targetId!==(int)$user['id'])$notifications[$targetId]=true;}
 elseif($type==='quote'){$messageId=(int)($seg['message_id']??0);$st=$pdo->prepare('SELECT m.id,m.user_id,m.body_json,m.is_deleted,m.created_at_utc,m.author_nick,u.nick FROM messages m LEFT JOIN users u ON u.id=m.user_id WHERE m.id=?');$st->execute([$messageId]);$origin=$st->fetch();if(!$origin)chat_json(['ok'=>false,'error'=>'Исходное сообщение цитаты больше недоступно.'],400);$source=chat_visible_message_plain($pdo,$origin);$selected=(string)($seg['selected_text']??'');if($selected!==''){$pos=mb_strpos($source,$selected,0,'UTF-8');if($pos===false)chat_json(['ok'=>false,'error'=>'Выделенный фрагмент не соответствует исходному сообщению. Обновите историю и повторите.'],400);$quoteText=$selected;}else{$quoteText=$source;}if(trim($quoteText)==='')$quoteText='[сообщение без текста]';$preview=chat_truncate_one_line($quoteText,max(10,chat_cfg_int('limits','quote_max_chars',80)));$clean[]=['type'=>'quote','message_id'=>$messageId,'preview'=>$preview];$plainCount+=mb_strlen($preview,'UTF-8');if((int)$origin['user_id']!==(int)$user['id'])$notifications[(int)$origin['user_id']]=true;}
}
if($plainCount>$maxChars)chat_json(['ok'=>false,'error'=>'Сообщение длиннее установленного предела ('.$maxChars.' символов).'],400);
// Автоматические @упоминания из обычного текста также вызывают режим «МОЁ».
$fullText=chat_plain_text_from_segments($clean);$users=$pdo->query("SELECT id,nick FROM users WHERE account_status='active'")->fetchAll();
foreach($users as $u){$nick=(string)$u['nick'];$pattern='/(?<![\p{L}\p{N}_])@'.preg_quote($nick,'/').'(?![\p{L}\p{N}_])/iu';if((int)$u['id']!==(int)$user['id'] && @preg_match($pattern,$fullText))$notifications[(int)$u['id']]=true;}
$maxCount=max(1,chat_cfg_int('limits','attachment_max_count',3));$maxFile=max(1,chat_cfg_int('limits','attachment_max_bytes',10485760));$maxAll=max($maxFile,chat_cfg_int('limits','attachments_per_message_max_bytes',20971520));
$thumbnailMaxSide=max(16,min(1024,chat_cfg_int('files','thumbnail_max_side_px',128)));
$files=[];$total=0;$f=$_FILES['attachments']??null;
$uploadIdsRaw=(string)($_POST['upload_ids']??'[]');
$uploadIds=json_decode($uploadIdsRaw,true);
if(!is_array($uploadIds))chat_json(['ok'=>false,'error'=>'Некорректный список загруженных вложений.'],400);
$uploadIds=array_values($uploadIds);
if(count($uploadIds)>$maxCount)chat_json(['ok'=>false,'error'=>'В одном сообщении допускается не более '.$maxCount.' вложений.'],400);
if(count($uploadIds)>0 && $f && isset($f['name']) && is_array($f['name'])){
 chat_json(['ok'=>false,'error'=>'Нельзя смешивать старую загрузку файлов и блочную загрузку. Обновите страницу.'],400);
}
if($f && isset($f['name']) && is_array($f['name'])){
 $selectedCount=0;foreach($f['name'] as $candidateName){if((string)$candidateName!=='')$selectedCount++;}
 if($selectedCount>$maxCount)chat_json(['ok'=>false,'error'=>'В одном сообщении допускается не более '.$maxCount.' вложений.'],400);
 $count=0;foreach($f['name'] as $i=>$name){if((string)$name==='' || (int)$f['error'][$i]===UPLOAD_ERR_NO_FILE)continue;$count++;$err=(int)$f['error'][$i];if($err!==UPLOAD_ERR_OK)chat_json(['ok'=>false,'error'=>'Ошибка загрузки вложения (код '.$err.').'],400);$tmp=(string)$f['tmp_name'][$i];$size=(int)$f['size'][$i];if(!is_uploaded_file($tmp)||$size<=0||$size>$maxFile)chat_json(['ok'=>false,'error'=>'Файл пустой, недоступен или превышает лимит размера.'],400);$name=chat_clean_filename((string)$name);$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));if(!in_array($ext,chat_setting_list('files','allowed_extensions'),true))chat_json(['ok'=>false,'error'=>'Тип файла .'.$ext.' не разрешён.'],400);$fi=new finfo(FILEINFO_MIME_TYPE);$mime=strtolower((string)$fi->file($tmp));if(!in_array($mime,chat_setting_list('files','allowed_mimes'),true))chat_json(['ok'=>false,'error'=>'Фактический тип файла не разрешён.'],400);$pairs=[];foreach(explode(',',(string)chat_cfg('files','extension_mime_map','')) as $pair){$parts=explode(':',trim($pair),2);if(count($parts)===2)$pairs[strtolower(trim($parts[0]))]=array_map('strtolower',array_map('trim',explode('|',$parts[1])));}if(!isset($pairs[$ext])||!in_array($mime,$pairs[$ext],true))chat_json(['ok'=>false,'error'=>'Расширение и содержимое файла не соответствуют разрешённому типу.'],400);$total+=$size;if($total>$maxAll)chat_json(['ok'=>false,'error'=>'Суммарный размер вложений превышает лимит сообщения.'],400);$thumbnail=null;if(str_starts_with($mime,'image/'))$thumbnail=chat_generate_thumbnail_png($tmp,$mime,$thumbnailMaxSide);$files[]=['tmp'=>$tmp,'name'=>$name,'ext'=>$ext,'mime'=>$mime,'size'=>$size,'thumbnail'=>$thumbnail];}
 if(count($files)>$maxCount)chat_json(['ok'=>false,'error'=>'В одном сообщении допускается не более '.$maxCount.' вложений.'],400);
}
// Новые клиенты передают файлы отдельными URL-кодированными блоками и передают сюда только ID загрузок.
$stagedUploadIds=[];
foreach($uploadIds as $uploadId){
 if(!is_string($uploadId) || !preg_match('/\A[a-f0-9]{32}\z/i',$uploadId))chat_json(['ok'=>false,'error'=>'Некорректный идентификатор вложения.'],400);
 $uploadId=strtolower($uploadId);
 if(in_array($uploadId,$stagedUploadIds,true))chat_json(['ok'=>false,'error'=>'Одно и то же вложение указано несколько раз.'],400);
 try{$staged=chat_upload_stage_get((int)$user['id'],$uploadSessionId,$uploadId);}
 catch(Throwable $e){chat_json(['ok'=>false,'error'=>$e->getMessage()],400);}
 $tmp=$staged['path'];$meta=$staged['meta'];$size=(int)($meta['size']??0);
 if($size<=0 || $size>$maxFile || !is_file($tmp) || filesize($tmp)!==$size){
  chat_json(['ok'=>false,'error'=>'Файл загружен не полностью или превышает допустимый размер. Отправьте его ещё раз.'],400);
 }
 $name=chat_clean_filename((string)($meta['name']??''));$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
 if(!in_array($ext,chat_setting_list('files','allowed_extensions'),true))chat_json(['ok'=>false,'error'=>'Тип файла .'.$ext.' не разрешён.'],400);
 $fi=new finfo(FILEINFO_MIME_TYPE);$mime=strtolower((string)$fi->file($tmp));
 if(!in_array($mime,chat_setting_list('files','allowed_mimes'),true))chat_json(['ok'=>false,'error'=>'Фактический тип файла не разрешён.'],400);
 $pairs=[];foreach(explode(',',(string)chat_cfg('files','extension_mime_map','')) as $pair){$parts=explode(':',trim($pair),2);if(count($parts)===2)$pairs[strtolower(trim($parts[0]))]=array_map('strtolower',array_map('trim',explode('|',$parts[1])));}
 if(!isset($pairs[$ext])||!in_array($mime,$pairs[$ext],true))chat_json(['ok'=>false,'error'=>'Расширение и содержимое файла не соответствуют разрешённому типу.'],400);
 $total+=$size;if($total>$maxAll)chat_json(['ok'=>false,'error'=>'Суммарный размер вложений превышает лимит сообщения.'],400);
 $thumbnail=null;if(str_starts_with($mime,'image/'))$thumbnail=chat_generate_thumbnail_png($tmp,$mime,$thumbnailMaxSide);
 $files[]=['tmp'=>$tmp,'name'=>$name,'ext'=>$ext,'mime'=>$mime,'size'=>$size,'thumbnail'=>$thumbnail,'staged'=>true,'upload_id'=>$uploadId];
 $stagedUploadIds[]=$uploadId;
}
if(count($files)>$maxCount)chat_json(['ok'=>false,'error'=>'В одном сообщении допускается не более '.$maxCount.' вложений.'],400);
if(trim(chat_plain_text_from_segments($clean))==='' && count($files)===0)chat_json(['ok'=>false,'error'=>'Введите текст или добавьте вложение.'],400);
$bodyJson=json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);if($bodyJson===false)chat_json(['ok'=>false,'error'=>'Не удалось обработать сообщение.'],400);
try{
 $pdo->beginTransaction();
 // Блокировка строки пользователя делает антифлуд атомарным даже при параллельных запросах.
 $lock=$pdo->prepare('SELECT id,ban_mode,account_status,last_message_at_utc FROM users WHERE id=? FOR UPDATE');$lock->execute([(int)$user['id']]);$lockedUser=$lock->fetch();
 if(!$lockedUser || $lockedUser['account_status']!=='active' || $lockedUser['ban_mode']!=='none'){$pdo->rollBack();chat_json(['ok'=>false,'error'=>'Учётная запись заблокирована или недоступна для отправки.'],403);}
 $last=$lockedUser['last_message_at_utc']??null;
 if($last && (time()-strtotime((string)$last.' UTC'))<$interval){$wait=max(1,$interval-(time()-strtotime((string)$last.' UTC')));$pdo->rollBack();chat_json(['ok'=>false,'error'=>'Антифлуд: следующую отправку можно выполнить через '.$wait.' сек.'],429);}
 // Thumbnail BLOBs are deliberately excluded from logical-quota calculations.
 $storageState=chat_storage_state($pdo,true);
 $incomingBytes=strlen($bodyJson)+$total;
 $quota=chat_storage_quota_bytes();
 $threshold=(int)floor($quota*chat_storage_start_percent()/100);
 // An earlier pause (for example because only pinned messages remained) is cleared once usage
 // has fallen below the trigger; the next crossing of 90% can then start cleanup again.
 if((int)$storageState['cleanup_paused']===1 && (int)$storageState['used_bytes']<$threshold){
   $pdo->exec('UPDATE chat_storage_state SET cleanup_paused=0 WHERE id=1');
   $storageState['cleanup_paused']=0;
 }
 if((int)$storageState['used_bytes']+$incomingBytes>$quota){
   // If this upload would exceed the quota, start cleanup even if current usage is below 90%.
   // Respect an explicit administrator pause until usage later falls below the normal trigger.
   $shouldStartCleanup = (int)$storageState['cleanup_paused'] !== 1;
   $pdo->rollBack();
   if($shouldStartCleanup){try{$pdo->exec('UPDATE chat_storage_state SET cleanup_active=1 WHERE id=1 AND cleanup_paused=0');}catch(Throwable $ignored){}}
   $errorMessage = $shouldStartCleanup
     ? 'Хранилище достигло квоты. Запущена постепенная очистка; повторите отправку через несколько секунд.'
     : 'Хранилище достигло квоты, но очистка остановлена администратором. Повторите попытку позже.';
   chat_json(['ok'=>false,'error'=>$errorMessage,'cleanup_needed'=>$shouldStartCleanup],507);
 }
 $createdAt=chat_now_utc();
 $stmt=$pdo->prepare('INSERT INTO messages (user_id,author_nick,created_at_utc,body_json) VALUES (?,?,?,?)');$stmt->execute([(int)$user['id'],(string)$user['nick'],$createdAt,$bodyJson]);$messageId=(int)$pdo->lastInsertId();
 $pdo->prepare('UPDATE users SET last_message_at_utc=? WHERE id=?')->execute([$createdAt,(int)$user['id']]);
 $att=$pdo->prepare('INSERT INTO attachments (message_id,original_name,extension,mime_type,size_bytes,data,thumbnail_data,thumbnail_mime_type,thumbnail_width,thumbnail_height,uploaded_at_utc) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
 foreach($files as $file){
   $data=file_get_contents($file['tmp']);if($data===false)throw new RuntimeException('Не удалось прочитать загруженное вложение.');
   $thumb=$file['thumbnail']??null;
   $att->bindValue(1,$messageId,PDO::PARAM_INT);$att->bindValue(2,$file['name'],PDO::PARAM_STR);$att->bindValue(3,$file['ext'],PDO::PARAM_STR);$att->bindValue(4,$file['mime'],PDO::PARAM_STR);$att->bindValue(5,$file['size'],PDO::PARAM_INT);$att->bindValue(6,$data,PDO::PARAM_LOB);
   $att->bindValue(7,is_array($thumb)?$thumb['data']:null,is_array($thumb)?PDO::PARAM_LOB:PDO::PARAM_NULL);
   $att->bindValue(8,is_array($thumb)?$thumb['mime']:null,is_array($thumb)?PDO::PARAM_STR:PDO::PARAM_NULL);
   $att->bindValue(9,is_array($thumb)?$thumb['width']:null,is_array($thumb)?PDO::PARAM_INT:PDO::PARAM_NULL);
   $att->bindValue(10,is_array($thumb)?$thumb['height']:null,is_array($thumb)?PDO::PARAM_INT:PDO::PARAM_NULL);
   $att->bindValue(11,chat_now_utc(),PDO::PARAM_STR);$att->execute();
 }
 $notify=$pdo->prepare('INSERT IGNORE INTO message_notifications (message_id,user_id) VALUES (?,?)');foreach(array_keys($notifications) as $targetId){if($targetId!==(int)$user['id'])$notify->execute([$messageId,$targetId]);}
 // Keep message JSON + original attachment byte counts transactionally in the single storage row.
 chat_storage_adjust($pdo,$incomingBytes,$total);
 $usedAfter=(int)$storageState['used_bytes']+$incomingBytes;
 $threshold=(int)floor($quota*chat_storage_start_percent()/100);
 if($usedAfter >= $threshold){$pdo->exec('UPDATE chat_storage_state SET cleanup_active=1 WHERE id=1 AND cleanup_paused=0');}
 else {$pdo->exec('UPDATE chat_storage_state SET cleanup_paused=0 WHERE id=1 AND used_bytes < '.$threshold);}
 $cleanupNeeded=($usedAfter >= $threshold || ((int)$storageState['cleanup_active']===1 && $usedAfter>(int)floor($quota*chat_storage_target_percent()/100))) && (int)$storageState['cleanup_paused']!==1;
 $pdo->commit();
 // После успешной записи оригинала и миниатюры удаляем временные файлы загрузки.
 foreach($stagedUploadIds as $uploadId){chat_upload_stage_remove((int)$user['id'],$uploadSessionId,$uploadId);}
 // Cleanup happens in separate small requests after the user-visible send has succeeded.
 chat_json(['ok'=>true,'message_id'=>$messageId,'message'=>'Сообщение отправлено.','cleanup_needed'=>$cleanupNeeded]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Chat send error: '.$e->getMessage());chat_audit('message_send_error',(int)$user['id'],(string)$user['email'],(int)$user['id'],(string)$user['email'],['endpoint'=>'api/send.php','exception'=>substr($e->getMessage(),0,1000)]);chat_json(['ok'=>false,'error'=>'Не удалось сохранить сообщение. Проверьте лимиты PHP/MySQL и настройки хранилища.'],500);}
