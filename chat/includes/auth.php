<?php
declare(strict_types=1);

function chat_current_user(bool $refresh = false): ?array
{
    static $cached = null;
    static $loaded = false;
    if ($loaded && !$refresh) return $cached;
    $loaded = true;
    $cached = null;
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id < 1) return null;
    $stmt = chat_db()->prepare("SELECT * FROM users WHERE id = ? AND account_status = 'active' LIMIT 1");
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) {
        unset($_SESSION['user_id']);
        return null;
    }
    $u['is_admin'] = chat_is_admin_email((string)$u['email']) && ($u['ban_mode'] ?? 'none') === 'none';
    $cached = $u;
    return $cached;
}
function chat_require_user(): array
{
    $u = chat_current_user();
    if (!$u) chat_json(['ok' => false, 'error' => 'Требуется вход в чат.'], 401);
    return $u;
}
function chat_require_admin(): array
{
    $u = chat_require_user();
    if (!$u['is_admin']) {
        if (function_exists('chat_audit')) {
            chat_audit('admin_access_denied', (int)$u['id'], (string)$u['email'], (int)$u['id'], (string)$u['email'], [
                'path' => basename((string)($_SERVER['SCRIPT_NAME'] ?? '')),
                'reason' => 'not_configured_admin',
            ]);
        }
        chat_json(['ok' => false, 'error' => 'Недостаточно прав.'], 403);
    }
    return $u;
}
function chat_login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function chat_logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}
function chat_login_limited(string $email): bool
{
    $pdo = chat_db();
    $window = max(1, chat_cfg_int('limits', 'login_attempts_window_minutes', 15));
    $limit = max(1, chat_cfg_int('limits', 'login_attempts_limit', 10));
    $from = gmdate('Y-m-d H:i:s', time() - $window * 60);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE event_key = 'login_failed' AND created_at_utc >= ? AND (ip_address = ? OR subject_email = ?)");
    $stmt->execute([$from, chat_ip(), $email]);
    return (int)$stmt->fetchColumn() < $limit;
}
