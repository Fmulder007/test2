<?php
declare(strict_types=1);

function chat_now_utc(): string { return gmdate('Y-m-d H:i:s'); }
function chat_base_url(string $path = ''): string
{
    $base = rtrim((string)chat_cfg('app', 'base_url', ''), '/');
    return $base . ($path !== '' ? '/' . ltrim($path, '/') : '');
}
function chat_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function chat_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function chat_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
        if (function_exists('chat_audit')) chat_audit('http_method_rejected', isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, null, null, null, ['expected'=>$method,'actual'=>(string)($_SERVER['REQUEST_METHOD']??'GET'),'path'=>basename((string)($_SERVER['SCRIPT_NAME']??''))]);
        chat_json(['ok' => false, 'error' => 'Недопустимый метод запроса.'], 405);
    }
}
function chat_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
}
function chat_verify_csrf(): void
{
    $got = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($got === '' || $expected === '' || !hash_equals($expected, $got)) {
        if (function_exists('chat_audit')) chat_audit('csrf_rejected', isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, null, null, null, ['path'=>basename((string)($_SERVER['SCRIPT_NAME']??'')),'method'=>(string)($_SERVER['REQUEST_METHOD']??'GET')]);
        chat_json(['ok' => false, 'error' => 'Сессия формы устарела. Обновите страницу и повторите.'], 403);
    }
}
function chat_normalize_email(string $email): string
{
    return strtolower(trim($email));
}
function chat_email_valid(string $email): bool
{
    return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
function chat_nick_key(string $nick): string
{
    if (function_exists('mb_convert_case') && defined('MB_CASE_FOLD')) return mb_convert_case($nick, MB_CASE_FOLD, 'UTF-8');
    return mb_strtolower($nick, 'UTF-8');
}
function chat_nick_validate(string $nick): array
{
    if (!function_exists('mb_strlen')) return [false, '', 'На сервере требуется расширение PHP mbstring.'];
    $length = mb_strlen($nick, 'UTF-8');
    if ($length < 2 || $length > 32) return [false, $nick, 'Ник должен содержать от 2 до 32 символов.'];
    if (preg_match('/\s/u', $nick)) return [false, $nick, 'Пробелы и переносы строк в нике запрещены.'];
    if (preg_match('/[\p{Cc}\p{Cf}\p{Cs}\p{Co}\p{Cn}]/u', $nick)) return [false, $nick, 'Ник содержит недопустимые управляющие или невидимые символы.'];
    return [true, $nick, ''];
}
function chat_format_time(?string $utc): string
{
    if (!$utc) return '—';
    try {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        $zone = (string)chat_cfg('app', 'timezone', 'UTC');
        $dt = $dt->setTimezone(new DateTimeZone($zone));
        return $dt->format('d.m.y - H:i:s');
    } catch (Throwable $e) { return $utc; }
}
function chat_format_quote_time(?string $utc): string
{
    if (!$utc) return '—';
    try {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        $zone = (string)chat_cfg('app', 'timezone', 'UTC');
        return $dt->setTimezone(new DateTimeZone($zone))->format('d.m.Y - H:i:s');
    } catch (Throwable $e) { return $utc; }
}
function chat_format_relative(?string $utc): string
{
    if (!$utc) return 'давно';
    $then = strtotime($utc . ' UTC');
    if (!$then) return 'давно';
    $diff = max(0, time() - $then);
    if ($diff < 60) return 'менее минуты назад';
    if ($diff < 3600) return (int)floor($diff / 60) . ' мин назад';
    if ($diff < 86400) return (int)floor($diff / 3600) . ' ч назад';
    return (int)floor($diff / 86400) . ' дн назад';
}
function chat_valid_date(string $date): bool
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}
function chat_local_day_bounds_utc(string $date): array
{
    $tz = new DateTimeZone((string)chat_cfg('app', 'timezone', 'UTC'));
    $startLocal = new DateTimeImmutable($date . ' 00:00:00', $tz);
    $endLocal = $startLocal->modify('+1 day');
    $utc = new DateTimeZone('UTC');
    return [$startLocal->setTimezone($utc)->format('Y-m-d H:i:s'), $endLocal->setTimezone($utc)->format('Y-m-d H:i:s')];
}
function chat_local_date_from_utc(string $utc): string
{
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone((string)chat_cfg('app', 'timezone', 'UTC')))->format('Y-m-d');
}
function chat_ip(): string
{
    // Не доверяем X-Forwarded-For без явно настроенного доверенного reverse proxy.
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}
function chat_user_agent(): string { return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512); }
function chat_admin_emails(): array
{
    $raw = (string)chat_cfg('admins', 'emails', '');
    $items = array_filter(array_map(static fn($v) => chat_normalize_email(trim($v)), explode(',', $raw)));
    return array_values(array_unique($items));
}
function chat_is_admin_email(string $email): bool { return in_array(chat_normalize_email($email), chat_admin_emails(), true); }
function chat_setting_list(string $section, string $key): array
{
    $raw = (string)chat_cfg($section, $key, '');
    return array_values(array_filter(array_map(static fn($v) => strtolower(trim($v)), explode(',', $raw))));
}
function chat_plain_text_from_segments(array $segments): string
{
    $out = '';
    foreach ($segments as $segment) {
        if (!is_array($segment)) continue;
        $type = $segment['type'] ?? '';
        if ($type === 'text') $out .= (string)($segment['text'] ?? '');
        elseif ($type === 'mention') $out .= '@' . (string)($segment['nick'] ?? '');
        elseif ($type === 'quote') $out .= (string)($segment['preview'] ?? '');
    }
    return $out;
}
function chat_flatten_message_json(string $json, bool $deleted = false): string
{
    if ($deleted) return 'Удалено администратором';
    $segments = json_decode($json, true);
    if (!is_array($segments)) return '';
    // Отражаем видимый текст браузера, включая оформление цитат, чтобы
    // проверка выделенного фрагмента совпадала с тем, что выбрал пользователь.
    $out = '';
    foreach ($segments as $segment) {
        if (!is_array($segment)) continue;
        $type = $segment['type'] ?? '';
        if ($type === 'text') $out .= (string)($segment['text'] ?? '');
        elseif ($type === 'mention') $out .= '@' . (string)($segment['nick'] ?? '');
        elseif ($type === 'quote') $out .= "\n«" . (string)($segment['preview'] ?? '') . "»\n";
    }
    return trim($out);
}
function chat_truncate_one_line(string $text, int $max): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    if ($max < 1) return '';
    if (mb_strlen($text, 'UTF-8') <= $max) return $text;
    return rtrim(mb_substr($text, 0, max(1, $max - 1), 'UTF-8')) . '…';
}
function chat_clean_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]+/', '_', $name) ?? 'attachment';
    $name = trim($name, ". \t\n\r\0\x0B");
    if ($name === '') $name = 'attachment';
    if (mb_strlen($name, 'UTF-8') > 200) $name = mb_substr($name, 0, 200, 'UTF-8');
    return $name;
}
