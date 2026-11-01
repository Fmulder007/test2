<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
$user=chat_require_user();
$pdo=chat_db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    chat_json([
        'ok' => true,
        'notification_mode' => $user['notification_mode'] ?? 'MINE',
        'dark_theme' => !empty($user['dark_theme']) ? 1 : 0,
    ]);
}

chat_require_method('POST');
chat_verify_csrf();

$hasNotification = array_key_exists('notification_mode', $_POST);
$hasTheme = array_key_exists('dark_theme', $_POST);
if (!$hasNotification && !$hasTheme) {
    chat_json(['ok' => false, 'error' => 'Не указаны настройки для сохранения.'], 400);
}

$updates = [];
$values = [];
if ($hasNotification) {
    $mode = strtoupper((string)$_POST['notification_mode']);
    if (!in_array($mode, ['ALL', 'MINE', 'NONE'], true)) {
        chat_json(['ok' => false, 'error' => 'Неизвестный режим уведомлений.'], 400);
    }
    $updates[] = 'notification_mode = ?';
    $values[] = $mode;
}
if ($hasTheme) {
    $rawTheme = (string)$_POST['dark_theme'];
    if (!in_array($rawTheme, ['0', '1'], true)) {
        chat_json(['ok' => false, 'error' => 'Неизвестное значение темы оформления.'], 400);
    }
    $darkTheme = (int)$rawTheme;
    $updates[] = 'dark_theme = ?';
    $values[] = $darkTheme;
}

$values[] = (int)$user['id'];
$pdo->prepare('UPDATE users SET '.implode(', ', $updates).' WHERE id = ?')->execute($values);

if ($hasNotification) {
    chat_audit('notification_mode_changed', (int)$user['id'], (string)$user['email'], (int)$user['id'], (string)$user['email'], ['mode' => $mode]);
}
if ($hasTheme) {
    chat_audit('dark_theme_changed', (int)$user['id'], (string)$user['email'], (int)$user['id'], (string)$user['email'], ['enabled' => (bool)$darkTheme]);
}

chat_json([
    'ok' => true,
    'notification_mode' => $hasNotification ? $mode : ($user['notification_mode'] ?? 'MINE'),
    'dark_theme' => $hasTheme ? $darkTheme : (!empty($user['dark_theme']) ? 1 : 0),
]);
