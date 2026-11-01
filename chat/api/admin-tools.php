<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/quota.php';

$admin = chat_require_admin();
$pdo = chat_db();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($method !== 'GET') {
    chat_require_method('POST');
    chat_verify_csrf();
}
// Authentication and CSRF checks are complete; do not hold the session lock during storage scans.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

function chat_admin_tools_storage_response(PDO $pdo): array
{
    return ['ok'=>true,'status'=>chat_storage_status($pdo)];
}

try {
    if ($method === 'GET') {
        if ($action === 'storage_status') {
            chat_json(chat_admin_tools_storage_response($pdo));
        }
        if ($action === 'blocked_nicks') {
            $rows = $pdo->query('SELECT nick_key,nick,blocked_at_utc,blocked_by_email FROM blocked_nicks ORDER BY blocked_at_utc DESC,nick ASC LIMIT 1000')->fetchAll();
            $out = [];
            foreach ($rows as $row) $out[] = ['key'=>(string)$row['nick_key'],'value'=>(string)$row['nick'],'blocked_at_display'=>chat_format_time((string)$row['blocked_at_utc']),'blocked_by'=>(string)($row['blocked_by_email'] ?? '')];
            chat_json(['ok'=>true,'items'=>$out,'count'=>count($out)]);
        }
        if ($action === 'blocked_emails') {
            $rows = $pdo->query('SELECT email_key,email,blocked_at_utc,blocked_by_email FROM blocked_emails ORDER BY blocked_at_utc DESC,email ASC LIMIT 1000')->fetchAll();
            $out = [];
            foreach ($rows as $row) $out[] = ['key'=>(string)$row['email_key'],'value'=>(string)$row['email'],'blocked_at_display'=>chat_format_time((string)$row['blocked_at_utc']),'blocked_by'=>(string)($row['blocked_by_email'] ?? '')];
            chat_json(['ok'=>true,'items'=>$out,'count'=>count($out)]);
        }
        chat_json(['ok'=>false,'error'=>'Неизвестный раздел администрирования.'],400);
    }

    if ($action === 'storage_recalculate') {
        $state = chat_storage_recalculate($pdo);
        chat_audit('storage_recalculated', (int)$admin['id'], (string)$admin['email'], (int)$admin['id'], (string)$admin['email'], ['used_bytes'=>(int)$state['used_bytes'],'attachment_bytes'=>(int)$state['attachment_bytes']]);
        chat_json(['ok'=>true,'message'=>'Размер хранилища пересчитан.','status'=>chat_storage_status($pdo)]);
    }

    if ($action === 'storage_cleanup_start') {
        $state = chat_storage_state($pdo);
        $quota = chat_storage_quota_bytes();
        $target = (int)floor($quota * chat_storage_target_percent() / 100);
        if ((int)$state['used_bytes'] <= $target) {
            chat_storage_set_cleanup_active($pdo, false, false);
            chat_json(['ok'=>true,'active'=>false,'message'=>'Очистка не требуется: хранилище уже не превышает целевой уровень '.chat_storage_target_percent().'%.','status'=>chat_storage_status($pdo)]);
        }
        chat_storage_set_cleanup_active($pdo, true, false);
        chat_audit('storage_cleanup_manually_started', (int)$admin['id'], (string)$admin['email'], (int)$admin['id'], (string)$admin['email'], ['used_bytes'=>(int)$state['used_bytes']]);
        chat_json(['ok'=>true,'active'=>true,'message'=>'Постепенная очистка запущена.','status'=>chat_storage_status($pdo)]);
    }

    if ($action === 'storage_cleanup_stop') {
        // Wait for the current small batch to finish so its final state update cannot
        // accidentally overwrite the administrator's explicit pause.
        $lockName = 'lightchat_storage_cleanup';
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 30)');
        $lockStmt->execute([$lockName]);
        if ((int)$lockStmt->fetchColumn() !== 1) {
            chat_json(['ok'=>false,'error'=>'Очистка ещё выполняет текущую порцию. Повторите остановку через несколько секунд.'],409);
        }
        chat_storage_set_cleanup_active($pdo, false, true);
        chat_audit('storage_cleanup_manually_stopped', (int)$admin['id'], (string)$admin['email'], (int)$admin['id'], (string)$admin['email']);
        $status = chat_storage_status($pdo);
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
        chat_json(['ok'=>true,'active'=>false,'paused'=>true,'message'=>'Дальнейшие проходы очистки остановлены до снижения заполнения ниже порога.','status'=>$status]);
    }

    if ($action === 'remove_blocked_nick' || $action === 'remove_blocked_email') {
        $key = trim((string)($_POST['key'] ?? ''));
        if ($key === '') chat_json(['ok'=>false,'error'=>'Не указана запись для разблокировки.'],400);
        if ($action === 'remove_blocked_nick') {
            $find = $pdo->prepare('SELECT nick FROM blocked_nicks WHERE nick_key=?');
            $find->execute([$key]);
            $value = $find->fetchColumn();
            if ($value === false) chat_json(['ok'=>false,'error'=>'Запрещённый ник не найден.'],404);
            $del = $pdo->prepare('DELETE FROM blocked_nicks WHERE nick_key=?');
            $del->execute([$key]);
            chat_audit('blocked_nick_unblocked', null, null, (int)$admin['id'], (string)$admin['email'], ['nick'=>(string)$value]);
            chat_json(['ok'=>true,'message'=>'Запрет на ник «'.(string)$value.'» снят.']);
        }
        $find = $pdo->prepare('SELECT email FROM blocked_emails WHERE email_key=?');
        $find->execute([$key]);
        $value = $find->fetchColumn();
        if ($value === false) chat_json(['ok'=>false,'error'=>'Запрещённый email не найден.'],404);
        $del = $pdo->prepare('DELETE FROM blocked_emails WHERE email_key=?');
        $del->execute([$key]);
        chat_audit('blocked_email_unblocked', null, (string)$value, (int)$admin['id'], (string)$admin['email'], ['email'=>(string)$value]);
        chat_json(['ok'=>true,'message'=>'Запрет на email «'.(string)$value.'» снят.']);
    }

    chat_json(['ok'=>false,'error'=>'Неизвестная административная операция.'],400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Light Chat admin tools error: '.$e->getMessage());
    try { chat_audit('admin_tools_error', (int)$admin['id'], (string)$admin['email'], (int)$admin['id'], (string)$admin['email'], ['action'=>$action,'exception'=>substr($e->getMessage(),0,1000)]); } catch (Throwable $ignored) {}
    chat_json(['ok'=>false,'error'=>'Не удалось выполнить административную операцию. Подробности доступны в журнале.'],500);
}
