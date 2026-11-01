<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
chat_require_method('POST');chat_verify_csrf();
$email=chat_normalize_email((string)($_POST['email']??''));if(!chat_email_valid($email))chat_json(['ok'=>false,'error'=>'Укажите корректный email.'],400);
$pdo=chat_db();$interval=max(1,chat_cfg_int('limits','mail_resend_interval_seconds',60));$from=gmdate('Y-m-d H:i:s',time()-$interval);
$stmt=$pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE event_key IN ('registration_requested','registration_resend_requested','registration_resend_mail_failed') AND subject_email=? AND created_at_utc>=?");$stmt->execute([$email,$from]);$allowed=(int)$stmt->fetchColumn()===0;
$stmt=$pdo->prepare("SELECT * FROM users WHERE email_key=? AND account_status='pending' LIMIT 1");$stmt->execute([$email]);$u=$stmt->fetch();
if($allowed && $u){
 $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';$password='';for($i=0;$i<20;$i++)$password.=$alphabet[random_int(0,strlen($alphabet)-1)];
 $token=bin2hex(random_bytes(32));$hours=max(1,chat_cfg_int('limits','registration_token_hours',24));$tokenHash=hash('sha256',$token);$now=chat_now_utc();$oldTokenIds=[];$newTokenId=0;
 $pdo->beginTransaction();
 try{
  $old=$pdo->prepare("SELECT id FROM auth_tokens WHERE user_id=? AND purpose='register' AND consumed_at_utc IS NULL AND expires_at_utc>=?");$old->execute([(int)$u['id'],$now]);$oldTokenIds=array_map('intval',$old->fetchAll(PDO::FETCH_COLUMN));
  $pdo->prepare("UPDATE auth_tokens SET consumed_at_utc=? WHERE user_id=? AND purpose='register' AND consumed_at_utc IS NULL")->execute([$now,(int)$u['id']]);
  $pdo->prepare("INSERT INTO auth_tokens (user_id,purpose,token_hash,payload_json,created_at_utc,expires_at_utc) VALUES (?,'register',?,?,?,?)")->execute([(int)$u['id'],$tokenHash,json_encode(['password_hash'=>password_hash($password,PASSWORD_DEFAULT)],JSON_UNESCAPED_UNICODE),$now,gmdate('Y-m-d H:i:s',time()+$hours*3600)]);
  $newTokenId=(int)$pdo->lastInsertId();$pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('resend registration setup failed: '.$e->getMessage());chat_json(['ok'=>false,'error'=>'Не удалось подготовить повторную отправку.'],500);}
 $link=chat_base_url('auth/verify.php').'?token='.rawurlencode($token);
 $body="Здравствуйте!\n\nДля подтверждения регистрации в чате перейдите по ссылке:\n{$link}\n\nВаш новый пароль (20 символов):\n{$password}\n\nНовый пароль станет действующим после подтверждения.\n\nЕсли вы не запрашивали регистрацию, проигнорируйте письмо.";
 if(chat_send_mail($email,'Повторное подтверждение регистрации в чате',$body)){
  chat_audit('registration_resend_requested',(int)$u['id'],$email,null,null,['result'=>'mail_sent']);
 }else{
  // При неудаче доставки восстанавливаем предыдущие действовавшие ссылки.
  $pdo->beginTransaction();
  try{
   $pdo->prepare('DELETE FROM auth_tokens WHERE id=?')->execute([$newTokenId]);
   if($oldTokenIds){$placeholders=implode(',',array_fill(0,count($oldTokenIds),'?'));$pdo->prepare("UPDATE auth_tokens SET consumed_at_utc=NULL WHERE id IN ({$placeholders})")->execute($oldTokenIds);}
   $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('resend registration restore failed: '.$e->getMessage());}
  chat_audit('registration_resend_mail_failed',(int)$u['id'],$email,null,null,['result'=>'mail_failed']);
 }
}
if(!$allowed && $u) chat_audit('registration_resend_rate_limited',(int)$u['id'],$email,null,null,['result'=>'rate_limited']);
chat_audit('registration_resend_request_received', $u ? (int)$u['id'] : null, $email, null, null, ['result'=>'processed']);
chat_json(['ok'=>true,'message'=>'Если для этого email ожидается подтверждение регистрации, письмо будет отправлено при соблюдении ограничения частоты.']);
