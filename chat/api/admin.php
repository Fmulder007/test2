<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/quota.php';
chat_require_method('POST');
chat_verify_csrf();
$admin=chat_require_admin();
$pdo=chat_db();
$action=(string)($_POST['action']??'');

function chat_admin_refuse_configured_admin(array $target): void
{
    if (chat_is_admin_email((string)$target['email'])) {
        $actor = chat_current_user();
        if ($actor) chat_audit('protected_admin_action_rejected', (int)($target['id'] ?? 0) ?: null, (string)$target['email'], (int)$actor['id'], (string)$actor['email'], ['target_nick'=>(string)($target['nick'] ?? ''),'attempted_action'=>'admin_account_change']);
        chat_json(['ok'=>false,'error'=>'Учётная запись администратора защищена. Чтобы изменить её права, владелец хостинга должен изменить settings.cfg.'],403);
    }
}

try {
    if ($action==='delete') {
        $id=max(0,(int)($_POST['message_id']??0));
        if(!$id) chat_json(['ok'=>false,'error'=>'Не указан ID сообщения.'],400);
        $pdo->beginTransaction();
        $st=$pdo->prepare('SELECT m.id,m.user_id,m.author_nick,m.body_json,m.is_deleted,u.email,u.nick FROM messages m JOIN users u ON u.id=m.user_id WHERE m.id=? FOR UPDATE');
        $st->execute([$id]);
        $msg=$st->fetch();
        if(!$msg){$pdo->rollBack();chat_json(['ok'=>false,'error'=>'Сообщение не найдено.'],404);}
        if((int)$msg['is_deleted']===1){$pdo->rollBack();chat_json(['ok'=>true,'message'=>'Сообщение уже удалено.']);}
        chat_redact_quotes_to_message($pdo,$id);
        $replacement=json_encode([['type'=>'text','text'=>'Удалено администратором']],JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $attBytesStmt=$pdo->prepare('SELECT COALESCE(SUM(size_bytes),0) FROM attachments WHERE message_id=?');
        $attBytesStmt->execute([$id]);
        $attachmentBytes=(int)$attBytesStmt->fetchColumn();
        $oldBodyBytes=strlen((string)$msg['body_json']);
        $pdo->prepare('UPDATE messages SET body_json=?,is_deleted=1,deleted_at_utc=?,deleted_by_user_id=?,deleted_by_email=?,is_pinned=0,pinned_at_utc=NULL WHERE id=?')->execute([$replacement,chat_now_utc(),(int)$admin['id'],(string)$admin['email'],$id]);
        $pdo->prepare('DELETE FROM attachments WHERE message_id=?')->execute([$id]);
        chat_storage_adjust($pdo,strlen((string)$replacement)-$oldBodyBytes-$attachmentBytes,-$attachmentBytes);
        $pdo->commit();
        chat_audit('message_deleted',(int)$msg['user_id'],(string)$msg['email'],(int)$admin['id'],(string)$admin['email'],['message_author_nick'=>((string)($msg['author_nick'] ?? '') !== '' ? (string)$msg['author_nick'] : (string)$msg['nick'])],$id);
        chat_json(['ok'=>true,'message'=>'Сообщение удалено администратором.']);
    }

    if($action==='pin' || $action==='unpin'){
        $id=max(0,(int)($_POST['message_id']??0));
        $st=$pdo->prepare('SELECT m.id,m.user_id,m.author_nick,m.is_deleted,u.email,u.nick FROM messages m JOIN users u ON u.id=m.user_id WHERE m.id=?');
        $st->execute([$id]);$msg=$st->fetch();
        if(!$msg || $msg['is_deleted']) chat_json(['ok'=>false,'error'=>'Сообщение не найдено или удалено.'],404);
        $pin=$action==='pin';
        $pdo->prepare('UPDATE messages SET is_pinned=?,pinned_at_utc=? WHERE id=?')->execute([$pin?1:0,$pin?chat_now_utc():null,$id]);
        chat_audit($pin?'message_pinned':'message_unpinned',(int)$msg['user_id'],(string)$msg['email'],(int)$admin['id'],(string)$admin['email'],['message_author_nick'=>((string)($msg['author_nick'] ?? '') !== '' ? (string)$msg['author_nick'] : (string)$msg['nick'])],(int)$id);
        chat_json(['ok'=>true,'message'=>$pin?'Сообщение закреплено.':'Закрепление снято.']);
    }

    if(in_array($action,['ban','unban','unro'],true)){
        $targetId=max(0,(int)($_POST['user_id']??0));
        if(!$targetId) chat_json(['ok'=>false,'error'=>'Не выбран пользователь.'],400);
        $st=$pdo->prepare("SELECT id,email,nick,ban_mode FROM users WHERE id=? AND account_status='active' LIMIT 1");
        $st->execute([$targetId]);$target=$st->fetch();
        if(!$target) chat_json(['ok'=>false,'error'=>'Активный пользователь не найден.'],404);
        chat_admin_refuse_configured_admin($target);
        if((int)$target['id']===(int)$admin['id']) chat_json(['ok'=>false,'error'=>'Нельзя изменить блокировку собственной учётной записи.'],403);
        if($action==='ban'){
            $mode=(string)($_POST['mode']??'BAN');
            if(!in_array($mode,['RO','BAN'],true))$mode='BAN';
            $pdo->prepare('UPDATE users SET ban_mode=?,banned_at_utc=?,banned_by_user_id=?,banned_by_email=? WHERE id=?')->execute([$mode,chat_now_utc(),(int)$admin['id'],(string)$admin['email'],$targetId]);
            $event=$mode==='RO'?'user_ro_set':'user_ban_set';
            $message=$mode==='RO'?$target['nick'].': включён режим только чтение.':$target['nick'].': установлен полный BAN.';
            chat_audit($event,$targetId,(string)$target['email'],(int)$admin['id'],(string)$admin['email'],['nick'=>$target['nick'],'mode'=>$mode]);
        } elseif($action==='unro') {
            if($target['ban_mode']!=='RO') chat_json(['ok'=>false,'error'=>'Пользователь не находится в режиме только чтение.'],409);
            $pdo->prepare("UPDATE users SET ban_mode='none',banned_at_utc=NULL,banned_by_user_id=NULL,banned_by_email=NULL WHERE id=?")->execute([$targetId]);
            $event='user_ro_removed';
            $message=$target['nick'].': режим только чтение снят.';
            chat_audit($event,$targetId,(string)$target['email'],(int)$admin['id'],(string)$admin['email'],['nick'=>$target['nick'],'mode'=>'none']);
        } else {
            $pdo->prepare("UPDATE users SET ban_mode='none',banned_at_utc=NULL,banned_by_user_id=NULL,banned_by_email=NULL WHERE id=?")->execute([$targetId]);
            $message=$target['nick'].': блокировка снята.';
            chat_audit('user_unbanned',$targetId,(string)$target['email'],(int)$admin['id'],(string)$admin['email'],['nick'=>$target['nick'],'mode'=>'none']);
        }
        chat_json(['ok'=>true,'message'=>$message]);
    }

    if($action==='delete_user'){
        $targetId=max(0,(int)($_POST['user_id']??0));
        if(!$targetId)chat_json(['ok'=>false,'error'=>'Не выбран пользователь.'],400);
        $blockEmail=!empty($_POST['block_email']) && (string)$_POST['block_email']==='1';
        $blockNick=!empty($_POST['block_nick']) && (string)$_POST['block_nick']==='1';
        $pdo->beginTransaction();
        $st=$pdo->prepare("SELECT id,email,email_key,nick,nick_key,ban_mode FROM users WHERE id=? AND account_status='active' FOR UPDATE");
        $st->execute([$targetId]);$target=$st->fetch();
        if(!$target){$pdo->rollBack();chat_json(['ok'=>false,'error'=>'Активный пользователь не найден.'],404);}
        if(chat_is_admin_email((string)$target['email'])){$pdo->rollBack();chat_audit('protected_admin_action_rejected',(int)$target['id'],(string)$target['email'],(int)$admin['id'],(string)$admin['email'],['target_nick'=>$target['nick'],'attempted_action'=>'delete_user']);chat_json(['ok'=>false,'error'=>'Учётная запись администратора защищена. Изменить её права можно только через settings.cfg.'],403);}
        if((int)$target['id']===(int)$admin['id']){$pdo->rollBack();chat_json(['ok'=>false,'error'=>'Нельзя удалить собственную учётную запись из текущей сессии.'],403);}
        $now=chat_now_utc();
        if($blockEmail){
            $pdo->prepare('INSERT INTO blocked_emails (email_key,email,blocked_at_utc,blocked_by_user_id,blocked_by_email) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE email=VALUES(email),blocked_at_utc=VALUES(blocked_at_utc),blocked_by_user_id=VALUES(blocked_by_user_id),blocked_by_email=VALUES(blocked_by_email)')->execute([(string)$target['email_key'],(string)$target['email'],$now,(int)$admin['id'],(string)$admin['email']]);
        }
        if($blockNick){
            $pdo->prepare('INSERT INTO blocked_nicks (nick_key,nick,blocked_at_utc,blocked_by_user_id,blocked_by_email) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE nick=VALUES(nick),blocked_at_utc=VALUES(blocked_at_utc),blocked_by_user_id=VALUES(blocked_by_user_id),blocked_by_email=VALUES(blocked_by_email)')->execute([(string)$target['nick_key'],(string)$target['nick'],$now,(int)$admin['id'],(string)$admin['email']]);
        }
        // Account is soft-deleted: messages, author snapshots, pins, quotes and audit history remain intact.
        $emailTombstone='deleted+'.$targetId.'+'.bin2hex(random_bytes(8)).'@invalid.invalid';
        $nickTombstone='__deleted_'.$targetId.'_'.bin2hex(random_bytes(8));
        $randomHash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET account_status='deleted',email_key=?,nick_key=?,password_hash=?,ban_mode='none',banned_at_utc=NULL,banned_by_user_id=NULL,banned_by_email=NULL WHERE id=?")->execute([$emailTombstone,$nickTombstone,$randomHash,$targetId]);
        $pdo->prepare('DELETE FROM auth_tokens WHERE user_id=?')->execute([$targetId]);
        $pdo->commit();
        chat_audit('user_deleted',$targetId,(string)$target['email'],(int)$admin['id'],(string)$admin['email'],['nick'=>$target['nick'],'email_blocked'=>$blockEmail,'nick_blocked'=>$blockNick]);
        $message='Пользователь «'.$target['nick'].'» удалён. История сообщений сохранена.';
        if($blockEmail)$message.=' Email заблокирован для регистрации.';
        if($blockNick)$message.=' Ник заблокирован для регистрации.';
        chat_json(['ok'=>true,'message'=>$message]);
    }

    chat_json(['ok'=>false,'error'=>'Неизвестное действие.'],400);
} catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('Chat admin action error: '.$e->getMessage());
    chat_audit('admin_action_error',(int)$admin['id'],(string)$admin['email'],(int)$admin['id'],(string)$admin['email'],['action'=>$action,'exception'=>substr($e->getMessage(),0,1000)]);
    chat_json(['ok'=>false,'error'=>'Не удалось выполнить действие администратора. Подробности записаны в журнале, если БД доступна.'],500);
}
