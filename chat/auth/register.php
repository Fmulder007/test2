<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
chat_require_method('POST'); chat_verify_csrf();
$email = chat_normalize_email((string)($_POST['email'] ?? ''));
$nickInput = (string)($_POST['nick'] ?? '');
if (!chat_email_valid($email)) { chat_audit('registration_validation_failed', null, strlen($email)<=254?$email:substr($email,0,254), null, null, ['field'=>'email','reason'=>'invalid_email']); chat_json(['ok'=>false,'error'=>'Укажите корректный email.'], 400); }
[$nickOk, $nick, $nickError] = chat_nick_validate($nickInput);
if (!$nickOk) { chat_audit('registration_validation_failed', null, $email, null, null, ['field'=>'nick','reason'=>$nickError]); chat_json(['ok'=>false,'error'=>$nickError], 400); }
$nickKey = chat_nick_key($nick);
$pdo = chat_db();
// Освобождаем просроченные неподтверждённые регистрации, чтобы они не занимали email/ник бесконечно.
$pendingHours=max(1,chat_cfg_int('limits','registration_token_hours',24));
$pdo->prepare("DELETE FROM users WHERE account_status='pending' AND created_at_utc < ?")->execute([gmdate('Y-m-d H:i:s',time()-($pendingHours+1)*3600)]);
$ipFrom = gmdate('Y-m-d H:i:s', time() - 3600);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE event_key='registration_requested' AND ip_address=? AND created_at_utc>=?");
$stmt->execute([chat_ip(), $ipFrom]);
if ((int)$stmt->fetchColumn() >= max(1, chat_cfg_int('limits','registrations_per_ip_per_hour',10))) { chat_audit('registration_rate_limited', null, $email, null, null, ['nick'=>$nick,'reason'=>'registrations_per_ip_per_hour']); chat_json(['ok'=>false,'error'=>'Слишком много запросов регистрации с этого адреса. Попробуйте позже.'], 429); }
$stmt = $pdo->prepare('SELECT 1 FROM blocked_emails WHERE email_key = ? LIMIT 1'); $stmt->execute([$email]);
if ($stmt->fetchColumn()) { chat_audit('registration_blocked_email', null, $email, null, null, ['nick'=>$nick,'reason'=>'email_blocked_by_admin']); chat_json(['ok'=>false,'error'=>'Регистрация с этим email запрещена администратором.'], 403); }
$stmt = $pdo->prepare('SELECT 1 FROM blocked_nicks WHERE nick_key = ? LIMIT 1'); $stmt->execute([$nickKey]);
if ($stmt->fetchColumn()) { chat_audit('registration_blocked_nick', null, $email, null, null, ['nick'=>$nick,'reason'=>'nick_blocked_by_admin']); chat_json(['ok'=>false,'error'=>'Использование этого ника при регистрации запрещено администратором.'], 403); }
$stmt = $pdo->prepare("SELECT id FROM users WHERE email_key = ? AND account_status IN ('pending','active') LIMIT 1"); $stmt->execute([$email]);
if ($stmt->fetch()) { chat_audit('registration_failed_email_exists', null, $email, null, null, ['nick'=>$nick,'reason'=>'email_exists']); chat_json(['ok'=>false,'error'=>'Этот email уже зарегистрирован или ожидает подтверждения.'], 409); }
$stmt = $pdo->prepare("SELECT id FROM users WHERE nick_key = ? AND account_status IN ('pending','active') LIMIT 1"); $stmt->execute([$nickKey]);
if ($stmt->fetch()) { chat_audit('registration_failed_nick_exists', null, $email, null, null, ['nick'=>$nick,'reason'=>'nick_exists']); chat_json(['ok'=>false,'error'=>'Этот ник уже занят. Выберите другой ник.'], 409); }
// Пароль генерируется до письма. В БД немедленно записывается только хеш.
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
$password = '';
for ($i=0; $i<20; $i++) $password .= $alphabet[random_int(0, strlen($alphabet)-1)];
$token = bin2hex(random_bytes(32)); $tokenHash = hash('sha256', $token);
$now = chat_now_utc(); $hours = max(1, chat_cfg_int('limits','registration_token_hours',24));
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO users (email,email_key,nick,nick_key,password_hash,account_status,created_at_utc) VALUES (?,?,?,?,?,'pending',?)");
    $stmt->execute([$email,$email,$nick,$nickKey,password_hash($password, PASSWORD_DEFAULT),$now]);
    $uid = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO auth_tokens (user_id,purpose,token_hash,payload_json,created_at_utc,expires_at_utc) VALUES (?,'register',?,?,?,?)");
    $stmt->execute([$uid,$tokenHash,null,$now,gmdate('Y-m-d H:i:s', time()+$hours*3600)]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException && (string)$e->getCode() === '23000') { chat_audit('registration_insert_conflict', null, $email, null, null, ['nick'=>$nick,'exception'=>$e->getMessage()]); chat_json(['ok'=>false,'error'=>'Email или ник уже занят.'],409); }
    error_log('Chat registration error: '.$e->getMessage());
    chat_audit('registration_error', null, $email, null, null, ['nick'=>$nick,'exception'=>substr($e->getMessage(),0,1000)]);
    chat_json(['ok'=>false,'error'=>'Не удалось создать регистрацию. Проверьте настройки БД.'],500);
}
// Журналируем попытку до отправки письма, чтобы ограничение регистрации
// продолжало работать и при ошибке почтового транспорта.
chat_audit('registration_requested',$uid,$email,null,null,['nick'=>$nick,'result'=>'pending']);
$link = chat_base_url('auth/verify.php') . '?token=' . rawurlencode($token);
$body = "Здравствуйте!\n\nДля подтверждения регистрации в чате перейдите по ссылке:\n{$link}\n\nВаш пароль (20 символов):\n{$password}\n\nПароль станет действующим после перехода по ссылке. Не пересылайте это письмо другим лицам.\n\nЕсли вы не запрашивали регистрацию, проигнорируйте это письмо.";
if (!chat_send_mail($email,'Подтверждение регистрации в чате',$body)) {
    chat_audit('registration_mail_failed',$uid,$email,null,null,['result'=>'mail_failed']);
    $pdo->prepare("DELETE FROM users WHERE id=? AND account_status='pending'")->execute([$uid]);
    chat_json(['ok'=>false,'error'=>'Письмо не отправлено. Проверьте настройки почты и повторите регистрацию.'],500);
}
chat_json(['ok'=>true,'message'=>'Письмо с паролем и ссылкой подтверждения отправлено. Проверьте почту.']);
