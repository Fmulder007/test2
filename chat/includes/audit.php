<?php
declare(strict_types=1);

function chat_audit(string $event, ?int $subjectUserId = null, ?string $subjectEmail = null, ?int $actorUserId = null, ?string $actorEmail = null, array $details = [], ?int $messageId = null): void
{
    try {
        $pdo = chat_db();
        $stmt = $pdo->prepare('INSERT INTO audit_log (created_at_utc,event_key,subject_user_id,actor_user_id,subject_email,actor_email,message_id,ip_address,user_agent,details_json) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $stmt->execute([chat_now_utc(), substr($event, 0, 80), $subjectUserId, $actorUserId, $subjectEmail, $actorEmail, $messageId, chat_ip(), chat_user_agent(), $json]);
        $days = chat_cfg_int('limits', 'audit_retention_days', 0);
        if ($days > 0) { $before = gmdate('Y-m-d H:i:s', time() - $days * 86400); $pdo->prepare('DELETE FROM audit_log WHERE created_at_utc < ?')->execute([$before]); }
    } catch (Throwable $e) {
        error_log('chat_audit_failed: ' . $e->getMessage());
    }
}
function chat_apply_audit_retention(): void
{
    $days = chat_cfg_int('limits', 'audit_retention_days', 0);
    if ($days <= 0) return;
    $before = gmdate('Y-m-d H:i:s', time() - $days * 86400);
    $stmt = chat_db()->prepare('DELETE FROM audit_log WHERE created_at_utc < ?');
    $stmt->execute([$before]);
}


/** Register request-level PHP diagnostics so warnings and fatal errors can be inspected
 * by administrators in the same journal, without exposing server details to visitors. */
function chat_install_runtime_diagnostics(): void
{
    static $installed = false;
    if ($installed) return;
    $installed = true;

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) return false;
        $captured = [E_WARNING, E_USER_WARNING, E_RECOVERABLE_ERROR, E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, E_CORE_WARNING, E_COMPILE_WARNING];
        if (!in_array($severity, $captured, true)) return false;
        if (!empty($GLOBALS['CHAT_AUDIT_DIAG_LOCK'])) return true;
        $GLOBALS['CHAT_AUDIT_DIAG_LOCK'] = true;
        try {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            $event = in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true) ? 'php_deprecation' : (in_array($severity, [E_NOTICE, E_USER_NOTICE], true) ? 'php_notice' : 'php_warning');
            chat_audit($event, $uid > 0 ? $uid : null, null, null, null, [
                'message' => substr($message, 0, 1200),
                'file' => basename($file),
                'line' => $line,
                'severity' => $severity,
            ]);
        } catch (Throwable $e) {
            error_log('Light Chat runtime diagnostic failed: ' . $e->getMessage());
        } finally {
            unset($GLOBALS['CHAT_AUDIT_DIAG_LOCK']);
        }
        // Keep warnings out of JSON responses and record them in the diagnostic journal.
        return true;
    });

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        if (!empty($GLOBALS['CHAT_AUDIT_DIAG_LOCK'])) return;
        $GLOBALS['CHAT_AUDIT_DIAG_LOCK'] = true;
        try {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            chat_audit('php_fatal_error', $uid > 0 ? $uid : null, null, null, null, [
                'message' => substr((string)$error['message'], 0, 1200),
                'file' => basename((string)$error['file']),
                'line' => (int)$error['line'],
                'severity' => (int)$error['type'],
            ]);
        } catch (Throwable $e) {
            error_log('Light Chat fatal diagnostic failed: ' . $e->getMessage());
        } finally {
            unset($GLOBALS['CHAT_AUDIT_DIAG_LOCK']);
        }
    });

    set_exception_handler(static function (Throwable $exception): void {
        error_log('Light Chat uncaught exception: ' . $exception->getMessage());
        try {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            chat_audit('php_uncaught_exception', $uid > 0 ? $uid : null, null, null, null, [
                'message' => substr($exception->getMessage(), 0, 1200),
                'file' => basename($exception->getFile()),
                'line' => $exception->getLine(),
            ]);
        } catch (Throwable $e) {
            error_log('Light Chat exception diagnostic failed: ' . $e->getMessage());
        }
        if (!headers_sent()) {
            http_response_code(500);
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            if (strpos($uri, '/api/') !== false || strpos($uri, '/auth/') !== false) {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo json_encode(['ok'=>false,'error'=>'Внутренняя ошибка сервера. Подробности доступны администратору в журнале.'], JSON_UNESCAPED_UNICODE);
            } else {
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Внутренняя ошибка сервера. Подробности доступны администратору в журнале.';
            }
        }
    });
}
