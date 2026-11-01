<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
chat_require_method('POST'); chat_verify_csrf();
$user=chat_current_user(); if(!$user) chat_json(['ok'=>false,'error'=>'Войдите в чат.'],401);
if (($user['ban_mode'] ?? 'none') !== 'none') chat_json(['ok'=>false,'error'=>'Смена ника недоступна при блокировке.'],403);
[$ok,$nick,$err]=chat_nick_validate((string)($_POST['nick']??'')); if(!$ok) { chat_audit('nick_change_validation_failed',(int)$user['id'],(string)$user['email'],(int)$user['id'],(string)$user['email'],['reason'=>$err]); chat_json(['ok'=>false,'error'=>$err],400); }
$key=chat_nick_key($nick); $pdo=chat_db();
$stmt=$pdo->prepare('SELECT 1 FROM blocked_nicks WHERE nick_key=? LIMIT 1'); $stmt->execute([$key]);
if($stmt->fetchColumn()){chat_audit('nick_change_blocked_nick',(int)$user['id'],(string)$user['email'],(int)$user['id'],(string)$user['email'],['new_nick'=>$nick,'reason'=>'nick_blocked_by_admin']);chat_json(['ok'=>false,'error'=>'Использование этого ника запрещено администратором.'],403);}
$stmt=$pdo->prepare("SELECT id FROM users WHERE nick_key=? AND account_status IN ('pending','active') AND id<>? LIMIT 1"); $stmt->execute([$key,(int)$user['id']]);
if($stmt->fetch()){chat_audit('nick_change_failed_nick_exists',(int)$user['id'],(string)$user['email'],(int)$user['id'],(string)$user['email'],['new_nick'=>$nick,'reason'=>'nick_exists']);chat_json(['ok'=>false,'error'=>'Этот ник уже занят. Выберите другой ник.'],409);}
if($key===$user['nick_key']) { chat_audit('nick_change_validation_failed',(int)$user['id'],(string)$user['email'],(int)$user['id'],(string)$user['email'],['reason'=>'same_as_current_nick']); chat_json(['ok'=>false,'error'=>'Новый ник совпадает с текущим.'],400); }
$interval=max(1,chat_cfg_int('limits','mail_resend_interval_seconds',60));
$stmt=$pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE event_key='nick_change_requested' AND subject_user_id=? AND created_at_utc>=?");
$stmt->execute([(int)$user['id'],gmdate('Y-m-d H:i:s',time()-$interval)]);
if((int)$stmt->fetchColumn()>0) { chat_audit('nick_change_rate_limited',(int)$user['id'],(string)$user['email'],(int)$user['id'],(string)$user['email'],['interval_seconds'=>$interval]); chat_json(['ok'=>false,'error'=>'Письмо недавно отправлено. Подождите перед повторной попыткой.'],429); }
$token=bin2hex(random_bytes(32)); $mins=max(1,chat_cfg_int('limits','nick_change_token_minutes',30)); $now=chat_now_utc();
$pdo->prepare("INSERT INTO auth_tokens (user_id,purpose,token_hash,payload_json,created_at_utc,expires_at_utc) VALUES (?,'change_nick',?,?,?,?)")
    ->execute([(int)$user['id'],hash('sha256',$token),json_encode(['nick'=>$nick,'nick_key'=>$key],JSON_UNESCAPED_UNICODE),$now,gmdate('Y-m-d H:i:s',time()+$mins*60)]);
$newTokenId=(int)$pdo->lastInsertId();
$link=chat_base_url('auth/confirm.php').'?token='.rawurlencode($token);
$body="Для подтверждения смены ника на «{$nick}» перейдите по ссылке:\n{$link}\n\nДо подтверждения действует старый ник. Если запрос не ваш, проигнорируйте письмо.";
// Регистрируем запрос до отправки, чтобы ошибки почты тоже подчинялись антифлуду.
chat_audit('nick_change_requested',(int)$user['id'],(string)$user['email'],(int)$user['id'],(string)$user['email'],['old_nick'=>$user['nick'],'new_nick'=>$nick,'result'=>'pending_mail']);
if(!chat_send_mail((string)$user['email'],'Подтверждение смены ника',$body)){ $pdo->prepare('DELETE FROM auth_tokens WHERE id=?')->execute([$newTokenId]); chat_audit('nick_change_mail_failed',(int)$user['id'],(string)$user['email'],(int)$user['id'],(string)$user['email'],['new_nick'=>$nick,'result'=>'mail_failed']); chat_json(['ok'=>false,'error'=>'Не удалось отправить письмо. Проверьте настройки почты.'],500); }
$pdo->prepare("UPDATE auth_tokens SET consumed_at_utc=? WHERE user_id=? AND purpose='change_nick' AND id<>? AND consumed_at_utc IS NULL")->execute([chat_now_utc(),(int)$user['id'],$newTokenId]);
chat_json(['ok'=>true,'message'=>'Ссылка подтверждения отправлена на вашу почту.']);
