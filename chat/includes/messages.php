<?php
declare(strict_types=1);

function chat_user_attachments(PDO $pdo, int $messageId): array
{
    $stmt=$pdo->prepare("SELECT id,original_name,extension,mime_type,size_bytes,(thumbnail_data IS NOT NULL AND thumbnail_mime_type='image/png') AS has_thumbnail,thumbnail_width,thumbnail_height FROM attachments WHERE message_id=? ORDER BY id");
    $stmt->execute([$messageId]);
    $rows=$stmt->fetchAll();
    foreach($rows as &$r){
        $r['download_url']=chat_base_url('attachments/download.php').'?id='.(int)$r['id'];
        $r['size_bytes']=(int)$r['size_bytes'];
        $r['has_thumbnail']=(bool)($r['has_thumbnail']??false);
        if($r['has_thumbnail']){
            $r['thumbnail_width']=(int)$r['thumbnail_width'];$r['thumbnail_height']=(int)$r['thumbnail_height'];
            $r['thumbnail_url']=chat_base_url('attachments/thumbnail.php').'?id='.(int)$r['id'];
        }else{$r['thumbnail_width']=null;$r['thumbnail_height']=null;}
    }
    unset($r); return $rows;
}
function chat_message_plain(PDO $pdo, array $msg): string
{
    return chat_flatten_message_json((string)$msg['body_json'], (bool)$msg['is_deleted']);
}
function chat_visible_message_plain(PDO $pdo, array $msg): string
{
    if (!empty($msg['is_deleted'])) return 'Удалено администратором';
    $segments = json_decode((string)($msg['body_json'] ?? ''), true);
    if (!is_array($segments)) return '';
    $quoteIds = [];
    foreach ($segments as $segment) {
        if (is_array($segment) && ($segment['type'] ?? '') === 'quote') {
            $qid = (int)($segment['message_id'] ?? 0);
            if ($qid > 0) $quoteIds[] = $qid;
        }
    }
    $origins = [];
    $quoteIds = array_values(array_unique($quoteIds));
    if ($quoteIds) {
        $marks = implode(',', array_fill(0, count($quoteIds), '?'));
        $stmt = $pdo->prepare("SELECT m.id,m.created_at_utc,m.author_nick,m.is_deleted,u.nick FROM messages m LEFT JOIN users u ON u.id=m.user_id WHERE m.id IN ({$marks})");
        $stmt->execute($quoteIds);
        foreach ($stmt->fetchAll() as $origin) $origins[(int)$origin['id']] = $origin;
    }
    $out = '';
    foreach ($segments as $segment) {
        if (!is_array($segment)) continue;
        $type = (string)($segment['type'] ?? '');
        if ($type === 'text') $out .= (string)($segment['text'] ?? '');
        elseif ($type === 'mention') $out .= '@' . (string)($segment['nick'] ?? '');
        elseif ($type === 'quote') {
            $qid = (int)($segment['message_id'] ?? 0);
            $origin = $origins[$qid] ?? null;
            if (!$origin) $display = '|[Исходное сообщение недоступно]';
            else {
                $nick = (string)(((isset($origin['author_nick']) && (string)$origin['author_nick'] !== '') ? $origin['author_nick'] : ($origin['nick'] ?? 'Пользователь')));
                $excerpt = !empty($origin['is_deleted']) ? 'Удалено администратором' : (string)($segment['preview'] ?? 'Цитата');
                $display = '|[' . chat_format_quote_time((string)$origin['created_at_utc']) . '], ' . $nick . ' писал: ' . $excerpt;
            }
            $out .= "\n" . $display . "\n";
        }
    }
    return trim($out);
}
function chat_attachment_map(PDO $pdo, array $messages, array $viewer): array
{
    if (!$messages || ($viewer['ban_mode'] ?? 'none') === 'BAN') return [];
    $ids = array_values(array_unique(array_map(static fn($m) => (int)$m['id'], $messages)));
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id,message_id,original_name,extension,mime_type,size_bytes,(thumbnail_data IS NOT NULL AND thumbnail_mime_type='image/png') AS has_thumbnail,thumbnail_width,thumbnail_height FROM attachments WHERE message_id IN ({$marks}) ORDER BY id");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $messageId = (int)$row['message_id'];
        $row['id'] = (int)$row['id'];
        $row['size_bytes'] = (int)$row['size_bytes'];
        $row['download_url'] = chat_base_url('attachments/download.php') . '?id=' . $row['id'];
        $row['has_thumbnail'] = (bool)($row['has_thumbnail'] ?? false);
        if ($row['has_thumbnail']) {
            $row['thumbnail_width'] = (int)$row['thumbnail_width'];
            $row['thumbnail_height'] = (int)$row['thumbnail_height'];
            $row['thumbnail_url'] = chat_base_url('attachments/thumbnail.php') . '?id=' . $row['id'];
        } else {
            $row['thumbnail_width'] = null;
            $row['thumbnail_height'] = null;
        }
        $map[$messageId][] = $row;
    }
    return $map;
}
function chat_enrich_messages(PDO $pdo, array $messages, array $viewer): array
{
    $attachmentMap = chat_attachment_map($pdo, $messages, $viewer);
    $quoteIds = [];
    if (($viewer['ban_mode'] ?? 'none') !== 'BAN') {
        foreach ($messages as $message) {
            if (!empty($message['is_deleted'])) continue;
            $segments = json_decode((string)($message['body_json'] ?? ''), true);
            if (!is_array($segments)) continue;
            foreach ($segments as $segment) {
                if (is_array($segment) && ($segment['type'] ?? '') === 'quote') {
                    $qid = (int)($segment['message_id'] ?? 0);
                    if ($qid > 0) $quoteIds[] = $qid;
                }
            }
        }
    }
    $quoteOrigins = [];
    $quoteIds = array_values(array_unique($quoteIds));
    if ($quoteIds) {
        $marks = implode(',', array_fill(0, count($quoteIds), '?'));
        $stmt = $pdo->prepare("SELECT m.id,m.created_at_utc,m.author_nick,m.is_deleted,u.nick FROM messages m LEFT JOIN users u ON u.id=m.user_id WHERE m.id IN ({$marks})");
        $stmt->execute($quoteIds);
        foreach ($stmt->fetchAll() as $origin) $quoteOrigins[(int)$origin['id']] = $origin;
    }
    $out = [];
    foreach ($messages as $message) {
        $out[] = chat_enrich_message($pdo, $message, $viewer, $attachmentMap[(int)$message['id']] ?? [], $quoteOrigins);
    }
    return $out;
}
function chat_enrich_message(PDO $pdo, array $msg, array $viewer, array $prefetchedAttachments = [], ?array $prefetchedQuoteOrigins = null): array
{
    $id=(int)$msg['id'];
    $body=json_decode((string)$msg['body_json'],true); if(!is_array($body)) $body=[];
    $deleted=(bool)$msg['is_deleted'];
    $banRedacted=($viewer['ban_mode']??'none')==='BAN';
    if($banRedacted){$body=[['type'=>'text','text'=>'--------------------']];}
    elseif($deleted){$body=[['type'=>'text','text'=>'Удалено администратором']];}
    $attachments=[];
    if(!$banRedacted && !$deleted) $attachments=$prefetchedAttachments;
    if (!$banRedacted && !$deleted && $body) {
        $quoteIds = [];
        foreach ($body as $segment) {
            if (is_array($segment) && ($segment['type'] ?? '') === 'quote') {
                $qid = (int)($segment['message_id'] ?? 0);
                if ($qid > 0) $quoteIds[] = $qid;
            }
        }
        $quoteOrigins = $prefetchedQuoteOrigins ?? [];
        $quoteIds = array_values(array_unique($quoteIds));
        if ($prefetchedQuoteOrigins === null && $quoteIds) {
            $marks = implode(',', array_fill(0, count($quoteIds), '?'));
            $qst = $pdo->prepare("SELECT m.id,m.created_at_utc,m.author_nick,m.is_deleted,u.nick FROM messages m LEFT JOIN users u ON u.id=m.user_id WHERE m.id IN ({$marks})");
            $qst->execute($quoteIds);
            foreach ($qst->fetchAll() as $origin) $quoteOrigins[(int)$origin['id']] = $origin;
        }
        foreach ($body as &$segment) {
            if (!is_array($segment) || ($segment['type'] ?? '') !== 'quote') continue;
            $qid = (int)($segment['message_id'] ?? 0);
            $origin = $quoteOrigins[$qid] ?? null;
            if (!$origin) {
                $segment['quote_display'] = '|[Исходное сообщение недоступно]';
                continue;
            }
            $originNick = (string)(((isset($origin['author_nick']) && (string)$origin['author_nick'] !== '') ? $origin['author_nick'] : ($origin['nick'] ?? 'Пользователь')));
            $excerpt = (bool)$origin['is_deleted'] ? 'Удалено администратором' : (string)($segment['preview'] ?? 'Цитата');
            $segment['quote_display'] = '|[' . chat_format_quote_time((string)$origin['created_at_utc']) . '], ' . $originNick . ' писал: ' . $excerpt;
        }
        unset($segment);
    }
    $msg['body_segments']=$body;
    $msg['attachments']=$attachments;
    $msg['created_at_display']=chat_format_time((string)$msg['created_at_utc']);
    $msg['created_at_quote_display']=chat_format_quote_time((string)$msg['created_at_utc']);
    $msg['local_date']=chat_local_date_from_utc((string)$msg['created_at_utc']);
    $msg['author_nick']=(string)((isset($msg['author_nick']) && (string)$msg['author_nick']!=='') ? $msg['author_nick'] : ($msg['nick']??''));
    $msg['is_deleted']=(bool)$msg['is_deleted'];
    $msg['is_pinned']=(bool)$msg['is_pinned'];
    $msg['redacted']=$banRedacted;
    $msg['is_admin_view']=!empty($viewer['is_admin']);
    if(!empty($viewer['is_admin'])){
        $msg['deleted_by_email']=$msg['deleted_by_email']??null;
    }else{
        unset($msg['deleted_by_email'],$msg['deleted_by_user_id'],$msg['deleted_at_utc']);
    }
    unset($msg['body_json'], $msg['email']);
    return $msg;
}
function chat_add_notification_flags(PDO $pdo, array &$rows, int $viewerId, bool $allowNotifications = true): void
{
    if (!$rows) return;
    if (!$allowNotifications || $viewerId < 1) {
        foreach ($rows as &$row) $row['notify_me'] = false;
        unset($row);
        return;
    }
    $ids = array_values(array_unique(array_map(static fn($r) => (int)$r['id'], $rows)));
    if (!$ids) return;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$viewerId], $ids);
    $stmt = $pdo->prepare("SELECT message_id FROM message_notifications WHERE user_id=? AND message_id IN ({$placeholders})");
    $stmt->execute($params);
    $notified = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    foreach ($rows as &$row) $row['notify_me'] = isset($notified[(int)$row['id']]);
    unset($row);
}

function chat_query_messages(PDO $pdo, array $viewer, ?string $date=null, int $beforeId=0, int $afterId=0, int $targetId=0): array
{
    $limit=max(20,min(250,chat_cfg_int('limits','history_page_size',100)));
    $params=[]; $where=[];
    if($targetId>0){
        $st=$pdo->prepare('SELECT created_at_utc FROM messages WHERE id=?'); $st->execute([$targetId]); $target=$st->fetchColumn();
        if(!$target) chat_json(['ok'=>false,'error'=>'Исходное сообщение больше недоступно: оно было удалено из истории или очищено по квоте.'],404);
        $date=chat_local_date_from_utc((string)$target);
    }
    if($date===null || $date===''){
        $start=gmdate('Y-m-d H:i:s',time()-86400); $end=chat_now_utc();
    }else{
        if(!chat_valid_date($date)) chat_json(['ok'=>false,'error'=>'Неверная дата.'],400);
        [$start,$end]=chat_local_day_bounds_utc($date);
    }
    $where[]='m.created_at_utc >= ?'; $params[]=$start;
    $where[]='m.created_at_utc < ?'; $params[]=$end;
    if($afterId>0){$where[]='m.id > ?';$params[]=$afterId;$order='ASC';}
    elseif($targetId>0){
        $countStmt=$pdo->prepare('SELECT COUNT(*) FROM messages m WHERE '.implode(' AND ',$where).' AND m.id < ?');
        $countStmt->execute(array_merge($params,[$targetId])); $beforeCount=(int)$countStmt->fetchColumn();
        $offset=max(0,$beforeCount-(int)floor($limit/3));
        $sql='SELECT m.*,u.nick,u.email FROM messages m JOIN users u ON u.id=m.user_id WHERE '.implode(' AND ',$where).' ORDER BY m.id ASC LIMIT '.(int)$limit.' OFFSET '.(int)$offset;
        $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
        $latestId=$rows?max(array_map(static fn($r)=>(int)$r['id'],$rows)):0;
        chat_add_notification_flags($pdo,$rows,(int)$viewer['id'],($viewer['ban_mode']??'none')!=='BAN');
        return ['messages'=>chat_enrich_messages($pdo,$rows,$viewer),'has_older'=>$offset>0,'has_newer'=>$latestId<$targetId,'date'=>$date,'last_id'=>$latestId];
    }elseif($beforeId>0){$where[]='m.id < ?';$params[]=$beforeId;$order='DESC';}
    else {$order='DESC';}
    if($afterId>0){
        $sql='SELECT m.*,u.nick,u.email FROM messages m JOIN users u ON u.id=m.user_id WHERE '.implode(' AND ',$where).' ORDER BY m.id ASC LIMIT '.(int)$limit;
        $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
        chat_add_notification_flags($pdo,$rows,(int)$viewer['id'],($viewer['ban_mode']??'none')!=='BAN');
        return ['messages'=>chat_enrich_messages($pdo,$rows,$viewer),'has_older'=>false,'date'=>$date,'last_id'=>$rows?(int)end($rows)['id']:$afterId];
    }
    $sql='SELECT m.*,u.nick,u.email FROM messages m JOIN users u ON u.id=m.user_id WHERE '.implode(' AND ',$where).' ORDER BY m.id '.$order.' LIMIT '.(int)($limit+1);
    $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
    $hasMore=count($rows)>$limit;if($hasMore)array_pop($rows);
    if($order==='DESC')$rows=array_reverse($rows);
    $lastId=$rows?(int)end($rows)['id']:0;
    chat_add_notification_flags($pdo,$rows,(int)$viewer['id'],($viewer['ban_mode']??'none')!=='BAN');
    return ['messages'=>chat_enrich_messages($pdo,$rows,$viewer),'has_older'=>$beforeId>0?$hasMore:$hasMore,'date'=>$date,'last_id'=>$lastId];
}
function chat_pinned_size_label(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' Б';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1, ',', '') . ' КБ';
    return number_format($bytes / (1024 * 1024), 1, ',', '') . ' МБ';
}

function chat_get_pinned(PDO $pdo, array $viewer): array
{
    $count=max(1,min(50,chat_cfg_int('limits','pinned_count',5)));
    $rows=$pdo->query("SELECT m.*,u.nick,u.email FROM messages m LEFT JOIN users u ON u.id=m.user_id WHERE m.is_pinned=1 AND m.is_deleted=0 ORDER BY m.pinned_at_utc DESC,m.id DESC LIMIT {$count}")->fetchAll();
    // Берём последние N закреплений, но показываем их от старых к новым.
    $rows = array_reverse($rows);
    $result=[];
    $notifyMap=[];
    if (($viewer['ban_mode']??'none')!=='BAN' && !empty($viewer['id']) && $rows) {
        $ids=array_values(array_unique(array_map(static fn($r)=>(int)$r['id'],$rows)));
        if($ids){
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $notifyStmt=$pdo->prepare("SELECT message_id FROM message_notifications WHERE user_id=? AND message_id IN ({$marks})");
            $notifyStmt->execute(array_merge([(int)$viewer['id']],$ids));
            $notifyMap=array_fill_keys(array_map('intval',$notifyStmt->fetchAll(PDO::FETCH_COLUMN)),true);
        }
    }
    $attStmt=$pdo->prepare('SELECT original_name,size_bytes FROM attachments WHERE message_id=? ORDER BY id');
    foreach($rows as $m){
        $body=json_decode((string)$m['body_json'],true)?:[];
        $plain=trim(chat_plain_text_from_segments($body));
        $preview=chat_truncate_one_line($plain,max(10,chat_cfg_int('limits','pinned_preview_max_chars',100)));
        $banRedacted=($viewer['ban_mode']??'none')==='BAN';
        if($banRedacted){
            $preview='--------------------';
        } elseif($preview==='') {
            $attStmt->execute([(int)$m['id']]);
            $files=$attStmt->fetchAll();
            if($files){
                $first=$files[0];
                $preview='[Вложение: '.(string)$first['original_name'].' · '.chat_pinned_size_label((int)$first['size_bytes']).']';
                $remaining=count($files)-1;
                if($remaining>0)$preview.=' + ещё '.$remaining.' файлов';
            } else {
                $preview='[Пустое сообщение]';
            }
        }
        $result[]=[
            'id'=>(int)$m['id'],
            'author_nick'=>(string)((isset($m['author_nick']) && (string)$m['author_nick']!=='') ? $m['author_nick'] : $m['nick']),
            'preview'=>$preview,
            'created_at_display'=>chat_format_time((string)$m['created_at_utc']),
            'local_date'=>chat_local_date_from_utc((string)$m['created_at_utc']),
            'notify_me'=>isset($notifyMap[(int)$m['id']])
        ];
    }
    return $result;
}
