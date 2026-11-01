<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$message = 'Ссылка подтверждения недействительна, просрочена или уже использована.';
$token = (string)($_GET['token'] ?? '');

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    chat_audit('confirmation_failed', null, null, null, null, ['reason' => 'invalid_token_format', 'purpose' => 'reset_or_nick']);
} else {
    try {
        $pdo = chat_db();
        // Load the token even when consumed/expired so the journal can record the actual failure reason.
        $stmt = $pdo->prepare("SELECT t.*,u.email,u.nick,u.nick_key,u.account_status FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.purpose IN ('reset_password','change_nick') LIMIT 1");
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();

        if (!$row) {
            chat_audit('confirmation_failed', null, null, null, null, ['reason' => 'token_not_found', 'purpose' => 'reset_or_nick']);
        } elseif ($row['consumed_at_utc'] !== null) {
            chat_audit('confirmation_failed', (int)$row['user_id'], (string)$row['email'], null, null, ['reason' => 'token_already_used', 'purpose' => $row['purpose']]);
        } elseif ((string)$row['expires_at_utc'] < chat_now_utc()) {
            chat_audit('confirmation_failed', (int)$row['user_id'], (string)$row['email'], null, null, ['reason' => 'token_expired', 'purpose' => $row['purpose']]);
        } elseif ($row['account_status'] !== 'active') {
            chat_audit('confirmation_failed', (int)$row['user_id'], (string)$row['email'], null, null, ['reason' => 'account_not_active', 'purpose' => $row['purpose'], 'account_status' => $row['account_status']]);
        } else {
            $payload = json_decode((string)$row['payload_json'], true) ?: [];
            $type = (string)$row['purpose'];
            $success = false;
            $failureEvent = '';
            $failureDetails = [];
            $pdo->beginTransaction();
            try {
                if ($type === 'reset_password') {
                    if (!empty($payload['password_hash'])) {
                        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([(string)$payload['password_hash'], (int)$row['user_id']]);
                        $success = true;
                        $message = 'Новый пароль подтверждён и теперь действует. Используйте пароль из письма при следующем входе.';
                    } else {
                        $failureEvent = 'password_reset_confirmation_failed';
                        $failureDetails = ['reason' => 'password_payload_missing'];
                        $message = 'Данные нового пароля повреждены. Запросите восстановление ещё раз.';
                    }
                } elseif ($type === 'change_nick' && isset($payload['nick'], $payload['nick_key'])) {
                    $blocked = $pdo->prepare('SELECT 1 FROM blocked_nicks WHERE nick_key=? LIMIT 1');
                    $blocked->execute([(string)$payload['nick_key']]);
                    $isBlocked = (bool)$blocked->fetchColumn();
                    $check = $pdo->prepare("SELECT id FROM users WHERE nick_key=? AND id<>? AND account_status IN ('pending','active') LIMIT 1");
                    $check->execute([(string)$payload['nick_key'], (int)$row['user_id']]);
                    $isTaken = (bool)$check->fetch();
                    if (!$isBlocked && !$isTaken) {
                        $oldNick = (string)$row['nick'];
                        $pdo->prepare('UPDATE users SET nick=?,nick_key=? WHERE id=?')->execute([(string)$payload['nick'], (string)$payload['nick_key'], (int)$row['user_id']]);
                        $success = true;
                        $message = 'Ник успешно изменён на «' . (string)$payload['nick'] . '».';
                    } else {
                        $pdo->prepare('UPDATE auth_tokens SET consumed_at_utc=? WHERE id=?')->execute([chat_now_utc(), (int)$row['id']]);
                        $failureEvent = $isBlocked ? 'nick_change_blocked_nick' : 'nick_change_failed_nick_exists';
                        $failureDetails = ['new_nick' => (string)$payload['nick'], 'reason' => $isBlocked ? 'blocked_before_confirmation' : 'nick_exists_at_confirmation'];
                        $message = $isBlocked ? 'Этот ник заблокирован администратором. Старый ник остался без изменений.' : 'Этот ник теперь занят. Старый ник остался без изменений.';
                    }
                } else {
                    $failureEvent = 'confirmation_failed';
                    $failureDetails = ['reason' => 'invalid_confirmation_payload', 'purpose' => $type];
                    $pdo->prepare('UPDATE auth_tokens SET consumed_at_utc=? WHERE id=?')->execute([chat_now_utc(), (int)$row['id']]);
                    $message = 'Данные ссылки подтверждения повреждены. Запросите действие повторно.';
                }

                if ($success) {
                    $pdo->prepare('UPDATE auth_tokens SET consumed_at_utc=? WHERE user_id=? AND purpose=? AND consumed_at_utc IS NULL')->execute([chat_now_utc(), (int)$row['user_id'], $type]);
                }
                $pdo->commit();

                if ($success && $type === 'reset_password') {
                    chat_audit('password_reset_confirmed', (int)$row['user_id'], (string)$row['email'], (int)$row['user_id'], (string)$row['email'], ['result' => 'success']);
                } elseif ($success && $type === 'change_nick') {
                    chat_audit('nick_changed', (int)$row['user_id'], (string)$row['email'], (int)$row['user_id'], (string)$row['email'], ['old_nick' => $oldNick ?? (string)$row['nick'], 'new_nick' => (string)$payload['nick'], 'result' => 'success']);
                } elseif ($failureEvent !== '') {
                    chat_audit($failureEvent, (int)$row['user_id'], (string)$row['email'], (int)$row['user_id'], (string)$row['email'], $failureDetails);
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Chat confirmation failure: ' . $e->getMessage());
                chat_audit('confirmation_apply_error', (int)$row['user_id'], (string)$row['email'], null, null, ['purpose' => $type, 'exception' => substr($e->getMessage(), 0, 1000)]);
                $message = 'Не удалось применить изменения. Подробности доступны администратору в журнале.';
            }
        }
    } catch (Throwable $e) {
        error_log('Chat confirmation handler failure: ' . $e->getMessage());
        chat_audit('confirmation_apply_error', null, null, null, null, ['exception' => substr($e->getMessage(), 0, 1000)]);
        $message = 'Не удалось обработать ссылку. Подробности доступны администратору в журнале.';
    }
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Подтверждение — Чат</title><link rel="stylesheet" href="../assets/chat.css?v=1.0.4"></head><body class="standalone"><main class="simple-panel"><h1>Подтверждение</h1><p><?= chat_e($message) ?></p><p><a href="<?= chat_e(chat_base_url('/')) ?>">Перейти в чат</a></p></main></body></html>
