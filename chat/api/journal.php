<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$admin = chat_require_admin();
$pdo = chat_db();
try { chat_apply_audit_retention(); } catch (Throwable $e) {}
$date = (string)($_GET['date'] ?? '');
$userId = max(0, (int)($_GET['user_id'] ?? 0));
$before = max(0, (int)($_GET['before_id'] ?? 0));
$target = max(0, (int)($_GET['target_id'] ?? 0));
$limit = max(20, min(200, chat_cfg_int('limits', 'history_page_size', 100)));
$where = [];
$params = [];
$targetTs = null;
if ($target > 0) {
    $targetStmt = $pdo->prepare('SELECT created_at_utc FROM audit_log WHERE id=?');
    $targetStmt->execute([$target]);
    $targetTs = $targetStmt->fetchColumn();
    if (!$targetTs) chat_json(['ok'=>false,'error'=>'Запись журнала больше недоступна.'], 404);
    $date = chat_local_date_from_utc((string)$targetTs);
}
if ($date === '') {
    $start = gmdate('Y-m-d H:i:s', time() - 86400);
    $end = chat_now_utc();
} else {
    if (!chat_valid_date($date)) chat_json(['ok'=>false,'error'=>'Неверная дата.'], 400);
    [$start, $end] = chat_local_day_bounds_utc($date);
}
$where[] = 'a.created_at_utc >= ?'; $params[] = $start;
$where[] = 'a.created_at_utc < ?'; $params[] = $end;
if ($userId > 0) {
    $userStmt = $pdo->prepare('SELECT email FROM users WHERE id=?');
    $userStmt->execute([$userId]);
    $userEmail = (string)($userStmt->fetchColumn() ?: '');
    $userClause = '(a.subject_user_id=? OR a.actor_user_id=?';
    $params[] = $userId; $params[] = $userId;
    if ($userEmail !== '') { $userClause .= ' OR a.subject_email=? OR a.actor_email=?'; $params[] = $userEmail; $params[] = $userEmail; }
    $userClause .= ')';
    $where[] = $userClause;
}
$whereSql = implode(' AND ', $where);
$from = ' FROM audit_log a LEFT JOIN users su ON su.id=a.subject_user_id LEFT JOIN users au ON au.id=a.actor_user_id ';
if ($target > 0) {
    $count = $pdo->prepare('SELECT COUNT(*) FROM audit_log a WHERE ' . $whereSql . ' AND a.id < ?');
    $count->execute(array_merge($params, [$target]));
    $beforeCount = (int)$count->fetchColumn();
    $offset = max(0, $beforeCount - (int)floor($limit / 3));
    $sql = 'SELECT a.*,su.nick AS subject_nick,au.nick AS actor_nick ' . $from . ' WHERE ' . $whereSql . ' ORDER BY a.id ASC LIMIT ' . $limit . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
    $hasMore = $offset > 0;
} else {
    if ($before > 0) { $where[] = 'a.id < ?'; $params[] = $before; $whereSql = implode(' AND ', $where); }
    $sql = 'SELECT a.*,su.nick AS subject_nick,au.nick AS actor_nick ' . $from . ' WHERE ' . $whereSql . ' ORDER BY a.id DESC LIMIT ' . ($limit + 1);
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);
    $rows = array_reverse($rows);
}
$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'=>(int)$r['id'],
        'created_at_display'=>chat_format_time((string)$r['created_at_utc']),
        'local_date'=>chat_local_date_from_utc((string)$r['created_at_utc']),
        'event_key'=>(string)$r['event_key'],
        'subject_user_id'=>$r['subject_user_id'] ? (int)$r['subject_user_id'] : null,
        'subject_nick'=>$r['subject_nick'] ?: null,
        'subject_email'=>$r['subject_email'],
        'actor_user_id'=>$r['actor_user_id'] ? (int)$r['actor_user_id'] : null,
        'actor_nick'=>$r['actor_nick'] ?: null,
        'actor_email'=>$r['actor_email'],
        'message_id'=>$r['message_id'] ? (int)$r['message_id'] : null,
        'ip_address'=>$r['ip_address'],
        'user_agent'=>$r['user_agent'],
        'details'=>json_decode((string)$r['details_json'], true) ?: [],
    ];
}
chat_json(['ok'=>true,'entries'=>$out,'has_older'=>$hasMore,'date'=>$date ?: null,'last_id'=>$out ? (int)end($out)['id'] : 0,'user_id'=>$userId ?: null,'target_id'=>$target ?: null]);
