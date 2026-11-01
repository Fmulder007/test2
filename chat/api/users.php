<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
$user=chat_require_user();
// The user identity and permissions are now in local variables; release the PHP session lock
// so this list request cannot stall parallel message/history requests from the same browser.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$pdo=chat_db();
$timeout=max(30,chat_cfg_int('app','presence_timeout_seconds',90));
$cutoff=gmdate('Y-m-d H:i:s',time()-$timeout);
$stmt=$pdo->prepare("SELECT u.id,u.email,u.nick,u.ban_mode,u.last_seen_at_utc,u.banned_at_utc,u.banned_by_user_id,u.banned_by_email,bu.nick AS banned_by_nick FROM users u LEFT JOIN users bu ON bu.id=u.banned_by_user_id WHERE u.account_status='active' ORDER BY CASE u.ban_mode WHEN 'none' THEN 0 WHEN 'RO' THEN 1 ELSE 2 END, CASE WHEN u.last_seen_at_utc>=? AND u.ban_mode='none' THEN 0 ELSE 1 END, u.nick_key ASC");
$stmt->execute([$cutoff]);
$rows=$stmt->fetchAll();
$out=[];
foreach($rows as $r){
    $online=($r['ban_mode']==='none' && $r['last_seen_at_utc'] && $r['last_seen_at_utc']>=$cutoff);
    $item=['id'=>(int)$r['id'],'nick'=>$r['nick'],'ban_mode'=>$r['ban_mode'],'online'=>$online,'last_seen_relative'=>chat_format_relative($r['last_seen_at_utc'])];
    if(!empty($user['is_admin'])){
        $item['banned_at_display']=$r['banned_at_utc']?chat_format_time($r['banned_at_utc']):null;
        $item['banned_by']=$r['banned_by_nick']?:$r['banned_by_email'];
        $item['is_admin_account']=chat_is_admin_email((string)$r['email']);
    }
    $out[]=$item;
}
chat_json(['ok'=>true,'users'=>$out,'current_user_id'=>(int)$user['id'],'current_ban_mode'=>$user['ban_mode'],'notification_mode'=>$user['notification_mode']??'MINE','is_admin'=>(bool)$user['is_admin']]);
