<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$message = 'Ссылка подтверждения недействительна или уже использована.';
$token = (string)($_GET['token'] ?? '');
$handled = false;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $pdo = chat_db();
    $stmt = $pdo->prepare("SELECT t.*,u.email,u.nick,u.account_status FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.purpose='register' LIMIT 1");
    $stmt->execute([hash('sha256',$token)]);
    $row = $stmt->fetch();
    if (!$row) {
        chat_audit('registration_confirmation_failed',null,null,null,null,['reason'=>'token_not_found']);
    } elseif ($row['consumed_at_utc'] !== null) {
        chat_audit('registration_confirmation_failed',(int)$row['user_id'],(string)$row['email'],null,null,['nick'=>$row['nick'],'reason'=>'token_already_used']);
    } elseif ((string)$row['expires_at_utc'] < chat_now_utc()) {
        chat_audit('registration_confirmation_failed',(int)$row['user_id'],(string)$row['email'],null,null,['nick'=>$row['nick'],'reason'=>'token_expired']);
    } elseif ($row['account_status'] !== 'pending') {
        chat_audit('registration_confirmation_failed',(int)$row['user_id'],(string)$row['email'],null,null,['nick'=>$row['nick'],'reason'=>'account_not_pending','account_status'=>$row['account_status']]);
    } else {
        $blockedEmail = $pdo->prepare('SELECT 1 FROM blocked_emails WHERE email_key=? LIMIT 1');
        $blockedEmail->execute([(string)$row['email']]);
        $blockedNick = $pdo->prepare('SELECT 1 FROM blocked_nicks WHERE nick_key=? LIMIT 1');
        $blockedNick->execute([chat_nick_key((string)$row['nick'])]);
        $emailIsBlocked = (bool)$blockedEmail->fetchColumn();
        $nickIsBlocked = (bool)$blockedNick->fetchColumn();
        if ($emailIsBlocked || $nickIsBlocked) {
            $pdo->beginTransaction();
            try {
                $uid = (int)$row['user_id'];
                $emailTombstone = 'deleted+' . $uid . '+' . bin2hex(random_bytes(8)) . '@invalid.invalid';
                $nickTombstone = '__deleted_' . $uid . '_' . bin2hex(random_bytes(8));
                $pdo->prepare("UPDATE users SET account_status='deleted',email_key=?,nick_key=? WHERE id=? AND account_status='pending'")->execute([$emailTombstone,$nickTombstone,$uid]);
                $pdo->prepare("UPDATE auth_tokens SET consumed_at_utc=? WHERE user_id=? AND purpose='register' AND consumed_at_utc IS NULL")->execute([chat_now_utc(),$uid]);
                $pdo->commit();
                $reason = $emailIsBlocked ? 'email_blocked_before_confirmation' : 'nick_blocked_before_confirmation';
                chat_audit('registration_confirmation_failed',$uid,(string)$row['email'],null,null,['nick'=>$row['nick'],'reason'=>$reason]);
                $message = 'Регистрация отклонена: email или ник заблокирован администратором.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                chat_audit('registration_confirmation_error',(int)$row['user_id'],(string)$row['email'],null,null,['exception'=>substr($e->getMessage(),0,1000),'reason'=>'blocked_pending_registration_cleanup_failed']);
                $message = 'Не удалось завершить проверку регистрации. Обратитесь к администратору.';
            }
        } else {
        $pdo->beginTransaction();
        try {
            $payload = json_decode((string)$row['payload_json'], true) ?: [];
            if (!empty($payload['password_hash'])) {
                $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([(string)$payload['password_hash'],(int)$row['user_id']]);
            }
            $pdo->prepare("UPDATE users SET account_status='active',email_verified_at_utc=? WHERE id=?")->execute([chat_now_utc(),(int)$row['user_id']]);
            $pdo->prepare("UPDATE auth_tokens SET consumed_at_utc=? WHERE user_id=? AND purpose='register' AND consumed_at_utc IS NULL")->execute([chat_now_utc(),(int)$row['user_id']]);
            $pdo->commit();
            chat_audit('registration_confirmed',(int)$row['user_id'],(string)$row['email'],null,null,['nick'=>$row['nick'],'result'=>'success']);
            $message = 'Email подтверждён. Теперь можно войти в чат с паролем из письма.';
            $handled = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Chat registration confirmation error: '.$e->getMessage());
            chat_audit('registration_confirmation_error',(int)$row['user_id'],(string)$row['email'],null,null,['exception'=>substr($e->getMessage(),0,1000)]);
            $message = 'Не удалось подтвердить регистрацию. Обратитесь к администратору.';
        }
        }
    }
} else {
    chat_audit('registration_confirmation_failed',null,null,null,null,['reason'=>'invalid_token_format']);
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Подтверждение — Чат</title><link rel="stylesheet" href="../assets/chat.css?v=1.0.4"></head><body class="standalone"><main class="simple-panel"><h1>Подтверждение</h1><p><?= chat_e($message) ?></p><p><a href="<?= chat_e(chat_base_url('/')) ?>">Перейти в чат</a></p></main></body></html>
