<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/quota.php';
chat_require_method('POST');
chat_verify_csrf();
$user = chat_require_user();
if (($user['ban_mode'] ?? 'none') !== 'none') {
    chat_json(['ok'=>false,'error'=>'Очистка недоступна при действующей блокировке.'],403);
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
try {
    $result = chat_storage_cleanup_step(chat_db(), max(0, (int)($_POST['protect_message_id'] ?? 0)));
    // Detailed logical storage usage is displayed only in the administrator panel.
    if (empty($user['is_admin'])) unset($result['used_bytes']);
    if (!empty($result['started'])) {
        chat_audit('storage_cleanup_started', null, null, (int)$user['id'], (string)$user['email'], ['source'=>'automatic']);
    }
    if (empty($result['active']) && !empty($result['deleted_count'])) {
        chat_audit('storage_cleanup_finished', null, null, (int)$user['id'], (string)$user['email'], ['deleted_count'=>(int)$result['deleted_count'],'freed_bytes'=>(int)$result['freed_bytes']]);
    }
    chat_json(['ok'=>true] + $result);
} catch (Throwable $e) {
    error_log('Chat cleanup step error: ' . $e->getMessage());
    chat_audit('storage_cleanup_error', (int)$user['id'], (string)$user['email'], (int)$user['id'], (string)$user['email'], ['exception'=>substr($e->getMessage(),0,1000)]);
    chat_json(['ok'=>false,'error'=>'Не удалось выполнить очередной шаг очистки. Подробности записаны в журнал.'],500);
}
