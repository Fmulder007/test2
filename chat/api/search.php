<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/messages.php';

$viewer = chat_require_user();
$pdo = chat_db();
$scope = (string)($_GET['scope'] ?? 'messages');
if (!in_array($scope, ['messages', 'journal'], true)) {
    chat_json(['ok' => false, 'error' => 'Неизвестный раздел поиска.'], 400);
}
if ($scope === 'journal' && empty($viewer['is_admin'])) {
    chat_json(['ok' => false, 'error' => 'Поиск по журналу доступен только администраторам.'], 403);
}
$q = trim((string)($_GET['q'] ?? ''));
if (function_exists('mb_substr')) $q = mb_substr($q, 0, 128, 'UTF-8'); else $q = substr($q, 0, 128);
$pinnedOnly = $scope === 'messages' && (string)($_GET['pinned'] ?? '') === '1';
$userId = $scope === 'journal' ? max(0, (int)($_GET['user_id'] ?? 0)) : 0;
$offset = max(0, min(1000000, (int)($_GET['offset'] ?? 0)));
$limit = max(10, min(100, chat_cfg_int('limits', 'search_page_size', 50)));
if ($q === '' && !$pinnedOnly) {
    chat_json(['ok' => false, 'error' => 'Введите текст для поиска.'], 400);
}

function chat_search_add_term(array &$terms, array &$params, string $sql, array $values): void
{
    $terms[] = '(' . $sql . ')';
    foreach ($values as $value) $params[] = $value;
}
function chat_search_date_range(string $q): ?array
{
    if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{2}|\d{4})$/', $q, $m)) return null;
    $year = (int)$m[3];
    if (strlen($m[3]) === 2) $year += ($year <= 69 ? 2000 : 1900);
    $date = sprintf('%04d-%02d-%02d', $year, (int)$m[2], (int)$m[1]);
    if (!chat_valid_date($date)) return null;
    return chat_local_day_bounds_utc($date);
}
function chat_search_timestamp_range(string $q): ?array
{
    $q = trim($q, " \t\n\r\0\x0B[]");
    $formats = [
        ['!d.m.Y - H:i:s', 'd.m.Y - H:i:s', '+1 second'],
        ['!d.m.y - H:i:s', 'd.m.y - H:i:s', '+1 second'],
        ['!d.m.Y - H:i', 'd.m.Y - H:i', '+1 minute'],
        ['!d.m.y - H:i', 'd.m.y - H:i', '+1 minute'],
    ];
    $zone = new DateTimeZone((string)chat_cfg('app', 'timezone', 'UTC'));
    $utc = new DateTimeZone('UTC');
    foreach ($formats as [$parseFormat, $displayFormat, $step]) {
        $dt = DateTimeImmutable::createFromFormat($parseFormat, $q, $zone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($dt === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) continue;
        if ($dt->format($displayFormat) !== $q) continue;
        $end = $dt->modify($step);
        return [$dt->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')];
    }
    return null;
}
function chat_search_utf8_length(string $text): int
{
    if (function_exists('mb_strlen')) return mb_strlen($text, 'UTF-8');
    if (function_exists('iconv')) { $n = @iconv_strlen($text, 'UTF-8'); if ($n !== false) return $n; }
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($chars) ? count($chars) : strlen($text);
}
function chat_search_utf8_substr(string $text, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) return mb_substr($text, $start, $length, 'UTF-8');
    if (function_exists('iconv')) { $v = @iconv_substr($text, $start, $length, 'UTF-8'); if ($v !== false) return $v; }
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($chars)) return implode('', array_slice($chars, $start, $length));
    // Invalid UTF-8 can occur only with legacy/corrupt records; avoid cutting a continuation byte.
    $slice = $length === null ? substr($text, $start) : substr($text, $start, $length * 4);
    return (string)(@iconv('UTF-8', 'UTF-8//IGNORE', $slice) ?: '');
}
function chat_search_excerpt(string $text, string $query, int $max = 220): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    if ($text === '') return '[Пустое сообщение]';
    // Keep this path UTF-8-safe too: pinned-only search has an empty query but still needs excerpts.
    $len = chat_search_utf8_length($text);
    if ($query === '' || !function_exists('mb_stripos')) {
        if ($len <= $max) return $text;
        return chat_search_utf8_substr($text, 0, max(1, $max - 1)) . '…';
    }
    $pos = mb_stripos($text, $query, 0, 'UTF-8');
    if ($pos === false) return $len > $max ? chat_search_utf8_substr($text, 0, max(1, $max - 1)) . '…' : $text;
    $start = max(0, $pos - (int)floor($max * 0.28));
    if ($start + $max > $len) $start = max(0, $len - $max);
    $snippet = chat_search_utf8_substr($text, $start, $max);
    if ($start > 0) $snippet = '…' . $snippet;
    if ($start + $max < $len) $snippet .= '…';
    return $snippet;
}
function chat_search_size_label(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' Б';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1, ',', '') . ' КБ';
    return number_format($bytes / 1048576, 1, ',', '') . ' МБ';
}

try {
    if ($scope === 'messages') {
        $where = [];
        $params = [];
        if ($pinnedOnly) $where[] = 'm.is_pinned = 1 AND m.is_deleted = 0';
        if ($q !== '') {
            $terms = [];
            $termParams = [];
            $banRedacted = (($viewer['ban_mode'] ?? 'none') === 'BAN');
            if (!$banRedacted) {
                chat_search_add_term($terms, $termParams, "LOCATE(LOWER(?), LOWER(COALESCE(CAST(JSON_EXTRACT(m.body_json, '$[*].text') AS CHAR), ''))) > 0", [$q]);
                chat_search_add_term($terms, $termParams, "LOCATE(LOWER(?), LOWER(COALESCE(CAST(JSON_EXTRACT(m.body_json, '$[*].nick') AS CHAR), ''))) > 0", [$q]);
                chat_search_add_term($terms, $termParams, "LOCATE(LOWER(?), LOWER(COALESCE(CAST(JSON_EXTRACT(m.body_json, '$[*].preview') AS CHAR), ''))) > 0", [$q]);
            }
            chat_search_add_term($terms, $termParams, "LOCATE(LOWER(?), LOWER(COALESCE(NULLIF(m.author_nick, ''), u.nick, ''))) > 0", [$q]);
            chat_search_add_term($terms, $termParams, "LOCATE(LOWER(?), LOWER(m.created_at_utc)) > 0", [$q]);
            chat_search_add_term($terms, $termParams, "LOCATE(LOWER(?), LOWER(DATE_FORMAT(m.created_at_utc, '%d.%m.%y - %H:%i:%s'))) > 0", [$q]);
            chat_search_add_term($terms, $termParams, "LOCATE(LOWER(?), LOWER(DATE_FORMAT(m.created_at_utc, '%d.%m.%Y - %H:%i:%s'))) > 0", [$q]);
            $tz = (string)chat_cfg('app', 'timezone', 'UTC');
            chat_search_add_term($terms, $termParams, "LOCATE(LOWER(?), LOWER(DATE_FORMAT(CONVERT_TZ(m.created_at_utc, '+00:00', ?), '%d.%m.%y - %H:%i:%s'))) > 0", [$q, $tz]);
            chat_search_add_term($terms, $termParams, "LOCATE(LOWER(?), LOWER(DATE_FORMAT(CONVERT_TZ(m.created_at_utc, '+00:00', ?), '%d.%m.%Y - %H:%i:%s'))) > 0", [$q, $tz]);
            $range = chat_search_date_range($q);
            if ($range) {
                $terms[] = '(m.created_at_utc >= ? AND m.created_at_utc < ?)';
                $termParams[] = $range[0]; $termParams[] = $range[1];
            }
            // Parse complete local date/time queries in PHP as a fallback when MySQL timezone tables are unavailable.
            $timeRange = chat_search_timestamp_range($q);
            if ($timeRange) {
                $terms[] = '(m.created_at_utc >= ? AND m.created_at_utc < ?)';
                $termParams[] = $timeRange[0]; $termParams[] = $timeRange[1];
            }
            $where[] = '(' . implode(' OR ', $terms) . ')';
            array_push($params, ...$termParams);
        }
        $whereSql = $where ? implode(' AND ', $where) : '1=1';
        $from = ' FROM messages m LEFT JOIN users u ON u.id = m.user_id ';
        $count = $pdo->prepare('SELECT COUNT(*)' . $from . ' WHERE ' . $whereSql);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $sql = 'SELECT m.id,m.user_id,m.author_nick,m.created_at_utc,m.body_json,m.is_deleted,m.is_pinned,u.nick ' . $from . ' WHERE ' . $whereSql . ' ORDER BY m.created_at_utc DESC,m.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rawRows = $st->fetchAll();
        chat_add_notification_flags($pdo, $rawRows, (int)$viewer['id'], ($viewer['ban_mode'] ?? 'none') !== 'BAN');
        $enriched = chat_enrich_messages($pdo, $rawRows, $viewer);
        $results = [];
        foreach ($enriched as $message) {
            $plain = chat_plain_text_from_segments($message['body_segments'] ?? []);
            if (($viewer['ban_mode'] ?? 'none') === 'BAN') {
                $snippet = '--------------------';
            } elseif (trim($plain) === '' && !empty($message['attachments'])) {
                $first = $message['attachments'][0];
                $snippet = '[Вложение: ' . (string)$first['original_name'] . ' · ' . chat_search_size_label((int)$first['size_bytes']) . ']';
                if (count($message['attachments']) > 1) $snippet .= ' + ещё ' . (count($message['attachments']) - 1) . ' файлов';
            } else {
                $snippet = chat_search_excerpt($plain, $q);
            }
            $results[] = [
                'id' => (int)$message['id'],
                'user_id' => (int)$message['user_id'],
                'author_nick' => (string)$message['author_nick'],
                'created_at_display' => (string)$message['created_at_display'],
                'created_at_quote_display' => (string)($message['created_at_quote_display'] ?? $message['created_at_display']),
                'local_date' => (string)$message['local_date'],
                'snippet' => $snippet,
                'is_deleted' => (bool)$message['is_deleted'],
                'is_pinned' => (bool)$message['is_pinned'],
                'notify_me' => !empty($message['notify_me']),
            ];
        }
        chat_json(['ok'=>true,'scope'=>'messages','query'=>$q,'pinned_only'=>$pinnedOnly,'results'=>$results,'total'=>$total,'offset'=>$offset,'limit'=>$limit,'has_more'=>($offset + count($results)) < $total]);
    }

    $where = [];
    $params = [];
    if ($userId > 0) {
        $ust = $pdo->prepare('SELECT email FROM users WHERE id=?');
        $ust->execute([$userId]);
        $userEmail = (string)($ust->fetchColumn() ?: '');
        $where[] = '(a.subject_user_id=? OR a.actor_user_id=?' . ($userEmail !== '' ? ' OR a.subject_email=? OR a.actor_email=?' : '') . ')';
        $params[] = $userId; $params[] = $userId;
        if ($userEmail !== '') { $params[] = $userEmail; $params[] = $userEmail; }
    }
    if ($q !== '') {
        $terms = [];
        $termParams = [];
        $searchCols = [
            'a.event_key', 'a.subject_email', 'a.actor_email', 'a.ip_address', 'a.user_agent',
            'a.details_json', 'CAST(a.subject_user_id AS CHAR)', 'CAST(a.actor_user_id AS CHAR)',
            'CAST(a.message_id AS CHAR)', 'su.nick', 'au.nick', 'a.created_at_utc',
            "DATE_FORMAT(a.created_at_utc, '%d.%m.%y - %H:%i:%s')",
            "DATE_FORMAT(a.created_at_utc, '%d.%m.%Y - %H:%i:%s')",
        ];
        foreach ($searchCols as $col) chat_search_add_term($terms, $termParams, "LOCATE(LOWER(?), LOWER(COALESCE(CAST({$col} AS CHAR), ''))) > 0", [$q]);
        $tz = (string)chat_cfg('app', 'timezone', 'UTC');
        chat_search_add_term($terms, $termParams, "LOCATE(LOWER(?), LOWER(DATE_FORMAT(CONVERT_TZ(a.created_at_utc, '+00:00', ?), '%d.%m.%y - %H:%i:%s'))) > 0", [$q, $tz]);
        chat_search_add_term($terms, $termParams, "LOCATE(LOWER(?), LOWER(DATE_FORMAT(CONVERT_TZ(a.created_at_utc, '+00:00', ?), '%d.%m.%Y - %H:%i:%s'))) > 0", [$q, $tz]);
        // Exact local-date searches work even when MySQL's named time-zone tables are unavailable.
        $range = chat_search_date_range($q);
        if ($range) {
            $terms[] = '(a.created_at_utc >= ? AND a.created_at_utc < ?)';
            $termParams[] = $range[0]; $termParams[] = $range[1];
        }
        $timeRange = chat_search_timestamp_range($q);
        if ($timeRange) {
            $terms[] = '(a.created_at_utc >= ? AND a.created_at_utc < ?)';
            $termParams[] = $timeRange[0]; $termParams[] = $timeRange[1];
        }
        $where[] = '(' . implode(' OR ', $terms) . ')';
        array_push($params, ...$termParams);
    }
    $whereSql = $where ? implode(' AND ', $where) : '1=1';
    $from = ' FROM audit_log a LEFT JOIN users su ON su.id=a.subject_user_id LEFT JOIN users au ON au.id=a.actor_user_id ';
    $count = $pdo->prepare('SELECT COUNT(*)' . $from . ' WHERE ' . $whereSql);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $sql = 'SELECT a.*,su.nick AS subject_nick,au.nick AS actor_nick ' . $from . ' WHERE ' . $whereSql . ' ORDER BY a.created_at_utc DESC,a.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    $results = [];
    foreach ($rows as $r) {
        $results[] = [
            'id'=>(int)$r['id'],
            'created_at_display'=>chat_format_time((string)$r['created_at_utc']),
            'created_at_full_display'=>chat_format_quote_time((string)$r['created_at_utc']),
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
    chat_json(['ok'=>true,'scope'=>'journal','query'=>$q,'results'=>$results,'total'=>$total,'offset'=>$offset,'limit'=>$limit,'has_more'=>($offset + count($results)) < $total]);
} catch (Throwable $e) {
    error_log('Light Chat search error: ' . $e->getMessage());
    chat_audit('search_error', (int)$viewer['id'], (string)$viewer['email'], null, null, ['scope'=>$scope,'exception'=>substr($e->getMessage(),0,800)]);
    chat_json(['ok'=>false,'error'=>'Не удалось выполнить поиск. Подробности доступны администратору в журнале.'], 500);
}
