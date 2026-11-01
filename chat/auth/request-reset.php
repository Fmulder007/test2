<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
chat_require_method('POST'); chat_verify_csrf();
$email = chat_normalize_email((string)($_POST['email'] ?? ''));
if (!chat_email_valid($email)) { chat_audit('password_reset_validation_failed', null, $email !== '' && strlen($email) <= 254 ? $email : null, null, null, ['reason'=>'invalid_email']); chat_json(['ok'=>false,'error'=>'Укажите корректный email.'],400); }
$pdo=chat_db();
$interval=max(1,chat_cfg_int('limits','mail_resend_interval_seconds',60));
$stmt=$pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE event_key IN ('password_reset_requested','password_reset_request_received') AND subject_email=? AND created_at_utc>=?");
$stmt->execute([$email,gmdate('Y-m-d H:i:s',time()-$interval)]);
$recentRequests = (int)$stmt->fetchColumn();
if ($recentRequests===0) {
    $stmt=$pdo->prepare("SELECT * FROM users WHERE email_key=? AND account_status='active' LIMIT 1"); $stmt->execute([$email]); $u=$stmt->fetch();
    if ($u) {
        $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'; $newPassword='';
        for($i=0;$i<20;$i++) $newPassword.=$alphabet[random_int(0,strlen($alphabet)-1)];
        $token=bin2hex(random_bytes(32)); $mins=max(1,chat_cfg_int('limits','password_reset_token_minutes',30)); $now=chat_now_utc();
        $pdo->prepare("INSERT INTO auth_tokens (user_id,purpose,token_hash,payload_json,created_at_utc,expires_at_utc) VALUES (?,'reset_password',?,?,?,?)")
            ->execute([(int)$u['id'],hash('sha256',$token),json_encode(['password_hash'=>password_hash($newPassword,PASSWORD_DEFAULT)],JSON_UNESCAPED_UNICODE),$now,gmdate('Y-m-d H:i:s',time()+$mins*60)]);
        $newTokenId=(int)$pdo->lastInsertId();
        $link=chat_base_url('auth/confirm.php').'?token='.rawurlencode($token);
        $body="Для подтверждения нового пароля перейдите по ссылке:\n{$link}\n\nНовый пароль (20 символов):\n{$newPassword}\n\nОн станет действующим после подтверждения. До этого старый пароль продолжает работать. Если запрос не ваш, проигнорируйте письмо.";
        if (chat_send_mail($email,'Восстановление доступа к чату',$body)) {
            $pdo->prepare("UPDATE auth_tokens SET consumed_at_utc=? WHERE user_id=? AND purpose='reset_password' AND id<>? AND consumed_at_utc IS NULL")->execute([chat_now_utc(),(int)$u['id'],$newTokenId]);
            chat_audit('password_reset_requested',(int)$u['id'],$email,null,null,['result'=>'mail_sent']);
        } else {
            $pdo->prepare('DELETE FROM auth_tokens WHERE id=?')->execute([$newTokenId]);
            chat_audit('password_reset_mail_failed',(int)$u['id'],$email,null,null,['result'=>'mail_failed']);
        }
    }
    // Журналируем только запрос без раскрытия факта наличия учётной записи в ответе.
    chat_audit('password_reset_request_received',null,$email,null,null,['result'=>'processed']);
} else {
    chat_audit('password_reset_rate_limited', null, $email, null, null, ['result'=>'rate_limited','interval_seconds'=>$interval]);
}
chat_json(['ok'=>true,'message'=>'Если активная учётная запись с таким email существует, инструкции отправлены на почту.']);
