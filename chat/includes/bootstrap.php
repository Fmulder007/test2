<?php
declare(strict_types=1);

if (!defined('CHAT_BOOTSTRAPPED')) {
    define('CHAT_BOOTSTRAPPED', true);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
    require_once __DIR__ . '/config.php';
    $CHAT_CONFIG = chat_load_config();
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/communication.php';
    chat_validate_communication_protocol();
    require_once __DIR__ . '/security.php';
    chat_enforce_https();
    $zone = (string)chat_cfg('app', 'timezone', 'UTC');
    try { new DateTimeZone($zone); } catch (Throwable $e) { $zone = 'UTC'; }
    date_default_timezone_set($zone);
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_name((string)chat_cfg('app', 'session_name', 'LIGHTCHATSESSID'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => parse_url((string)chat_cfg('app', 'base_url', '/chat'), PHP_URL_PATH) ?: '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
    chat_enforce_communication_protocol_request();
    require_once __DIR__ . '/database.php';
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/audit.php';
    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/quota.php';
    chat_security_headers();
    chat_install_runtime_diagnostics();
}
