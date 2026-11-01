<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
chat_require_method('POST');
chat_verify_csrf();
$email = chat_normalize_email((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
if (!chat_email_valid($email) || $password === '') {
    chat_audit('login_validation_failed', null, $email !== '' ? $email : null, null, null, ['reason'=>'missing_or_invalid_credentials']);
    chat_json(['ok'=>false,'error'=>'Укажите email и пароль.'],400);
}
$pdo = chat_db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE email_key=? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();
if (!chat_login_limited($email)) {
    chat_audit('login_rate_limited', $user ? (int)$user['id'] : null, $email, null, null, ['reason'=>'too_many_attempts','limit'=>chat_cfg_int('limits','login_attempts_limit',10),'window_minutes'=>chat_cfg_int('limits','login_attempts_window_minutes',15)]);
    chat_json(['ok'=>false,'error'=>'Слишком много попыток входа. Попробуйте позже.'],429);
}
if (!$user) {
    chat_audit('login_failed', null, $email, null, null, ['result'=>'failed','reason'=>'unknown_email']);
    chat_json(['ok'=>false,'error'=>'Неверный email или пароль, либо регистрация ещё не подтверждена.'],401);
}
if ($user['account_status'] !== 'active') {
    $reason = $user['account_status'] === 'pending' ? 'account_not_confirmed' : 'account_deleted_or_unavailable';
    chat_audit('login_failed',(int)$user['id'],$email,null,null,['result'=>'failed','reason'=>$reason,'account_status'=>$user['account_status']]);
    chat_json(['ok'=>false,'error'=>'Неверный email или пароль, либо регистрация ещё не подтверждена.'],401);
}
if (!password_verify($password,(string)$user['password_hash'])) {
    chat_audit('login_failed',(int)$user['id'],$email,null,null,['result'=>'failed','reason'=>'wrong_password']);
    chat_json(['ok'=>false,'error'=>'Неверный email или пароль, либо регистрация ещё не подтверждена.'],401);
}
chat_login_user($user);
$stmt = $pdo->prepare('UPDATE users SET last_login_at_utc=?,last_seen_at_utc=? WHERE id=?');
$stmt->execute([chat_now_utc(),chat_now_utc(),(int)$user['id']]);
chat_audit('login_success',(int)$user['id'],$email,(int)$user['id'],$email,['result'=>'success']);
chat_json(['ok'=>true,'message'=>'Вход выполнен.','redirect'=>chat_base_url('/')]);
