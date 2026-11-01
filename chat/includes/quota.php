<?php
declare(strict_types=1);

/** Return the current logical storage counters. Requires MIGRATION-1.1.0.sql. */
function chat_storage_state(PDO $pdo, bool $forUpdate = false): array
{
    $sql = 'SELECT id, used_bytes, attachment_bytes, is_initialized, cleanup_active, cleanup_paused, last_recalculated_at_utc, last_cleanup_at_utc, last_cleanup_deleted_count, last_cleanup_freed_bytes FROM chat_storage_state WHERE id=1' . ($forUpdate ? ' FOR UPDATE' : '');
    $row = $pdo->query($sql)->fetch();
    if (!$row || (int)$row['is_initialized'] !== 1) {
        throw new RuntimeException('Счётчик хранилища не инициализирован. Выполните MIGRATION-1.1.0.sql.');
    }
    foreach (['used_bytes','attachment_bytes','is_initialized','cleanup_active','cleanup_paused','last_cleanup_deleted_count','last_cleanup_freed_bytes'] as $key) {
        $row[$key] = (int)$row[$key];
    }
    return $row;
}

/** Logical bytes: message JSON bodies plus original attachment byte sizes. */
function chat_storage_used_bytes(PDO $pdo): int
{
    return (int)chat_storage_state($pdo)['used_bytes'];
}

function chat_storage_adjust(PDO $pdo, int $usedDelta, int $attachmentDelta = 0): void
{
    if ($usedDelta === 0 && $attachmentDelta === 0) return;
    // Avoid unsigned arithmetic with negative bound parameters in MySQL 5.7.
    $usedExpr = $usedDelta < 0 ? 'GREATEST(0, used_bytes - ?)' : 'used_bytes + ?';
    $attachmentExpr = $attachmentDelta < 0 ? 'GREATEST(0, attachment_bytes - ?)' : 'attachment_bytes + ?';
    $stmt = $pdo->prepare('UPDATE chat_storage_state SET used_bytes=' . $usedExpr . ', attachment_bytes=' . $attachmentExpr . ' WHERE id=1 AND is_initialized=1');
    $stmt->execute([$usedDelta < 0 ? abs($usedDelta) : $usedDelta, $attachmentDelta < 0 ? abs($attachmentDelta) : $attachmentDelta]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->query('SELECT is_initialized FROM chat_storage_state WHERE id=1')->fetchColumn();
        if ((int)$check !== 1) throw new RuntimeException('Счётчик хранилища не инициализирован. Выполните MIGRATION-1.1.0.sql.');
    }
}

function chat_storage_quota_bytes(): int
{
    return max(1048576, chat_cfg_int('limits', 'storage_quota_bytes', 1073741824));
}

function chat_storage_start_percent(): int
{
    return min(99, max(10, chat_cfg_int('limits', 'storage_cleanup_start_percent', 90)));
}

function chat_storage_target_percent(): int
{
    return max(1, min(chat_storage_start_percent() - 1, max(1, chat_cfg_int('limits', 'storage_cleanup_target_percent', 80))));
}

/** One explicit full reconciliation. It takes a row lock, so message writes cannot race the count. */
function chat_storage_recalculate(PDO $pdo): array
{
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) $pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM chat_storage_state WHERE id=1 FOR UPDATE')->fetchColumn();
        $totals = $pdo->query("SELECT COALESCE((SELECT SUM(OCTET_LENGTH(body_json)) FROM messages),0) AS message_bytes, COALESCE((SELECT SUM(size_bytes) FROM attachments),0) AS attachment_bytes")->fetch();
        $messageBytes = max(0, (int)($totals['message_bytes'] ?? 0));
        $attachmentBytes = max(0, (int)($totals['attachment_bytes'] ?? 0));
        $stmt = $pdo->prepare('UPDATE chat_storage_state SET used_bytes=?, attachment_bytes=?, is_initialized=1, last_recalculated_at_utc=UTC_TIMESTAMP() WHERE id=1');
        $stmt->execute([$messageBytes + $attachmentBytes, $attachmentBytes]);
        if ($startedTransaction) $pdo->commit();
        return chat_storage_state($pdo);
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function chat_storage_database_allocated_bytes(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'");
    return max(0, (int)$stmt->fetchColumn());
}

function chat_storage_status(PDO $pdo): array
{
    $state = chat_storage_state($pdo);
    $quota = chat_storage_quota_bytes();
    $used = (int)$state['used_bytes'];
    return [
        'used_bytes' => $used,
        'attachment_bytes' => (int)$state['attachment_bytes'],
        'quota_bytes' => $quota,
        'used_percent' => $quota > 0 ? round($used * 100 / $quota, 2) : 0,
        'database_allocated_bytes' => chat_storage_database_allocated_bytes($pdo),
        'cleanup_active' => (bool)$state['cleanup_active'],
        'cleanup_paused' => (bool)$state['cleanup_paused'],
        'last_recalculated_at_display' => $state['last_recalculated_at_utc'] ? chat_format_time((string)$state['last_recalculated_at_utc']) : 'Ещё не выполнялся',
        'last_cleanup_at_display' => $state['last_cleanup_at_utc'] ? chat_format_time((string)$state['last_cleanup_at_utc']) : 'Ещё не выполнялась',
        'last_cleanup_deleted_count' => (int)$state['last_cleanup_deleted_count'],
        'last_cleanup_freed_bytes' => (int)$state['last_cleanup_freed_bytes'],
        'cleanup_start_percent' => chat_storage_start_percent(),
        'cleanup_target_percent' => chat_storage_target_percent(),
    ];
}

function chat_storage_set_cleanup_active(PDO $pdo, bool $active, ?bool $paused = null): void
{
    if ($paused === null) {
        $stmt = $pdo->prepare('UPDATE chat_storage_state SET cleanup_active=? WHERE id=1 AND is_initialized=1');
        $stmt->execute([$active ? 1 : 0]);
    } else {
        $stmt = $pdo->prepare('UPDATE chat_storage_state SET cleanup_active=?, cleanup_paused=? WHERE id=1 AND is_initialized=1');
        $stmt->execute([$active ? 1 : 0, $paused ? 1 : 0]);
    }
    if ($stmt->rowCount() === 0) {
        $check = $pdo->query('SELECT is_initialized FROM chat_storage_state WHERE id=1')->fetchColumn();
        if ((int)$check !== 1) throw new RuntimeException('Счётчик хранилища не инициализирован. Выполните MIGRATION-1.1.0.sql.');
    }
}

/**
 * Perform at most one small cleanup batch. This is called after a successful send,
 * on the next foreground activity, or manually by an administrator. MySQL GET_LOCK
 * ensures that only one request cleans at a time.
 */
function chat_storage_cleanup_step(PDO $pdo, int $protectMessageId = 0): array
{
    $batchSize = max(1, min(20, chat_cfg_int('limits', 'storage_cleanup_batch_messages', 5)));
    $interval = max(1, min(60, chat_cfg_int('limits', 'storage_cleanup_interval_seconds', 3)));
    $lockName = 'lightchat_storage_cleanup';
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lockStmt->execute([$lockName]);
    $hasLock = (int)$lockStmt->fetchColumn() === 1;
    if (!$hasLock) {
        return ['active' => true, 'busy' => true, 'started' => false, 'deleted_count' => 0, 'freed_bytes' => 0, 'interval_seconds' => $interval, 'message' => 'Очистка уже выполняется.'];
    }

    try {
        $quota = chat_storage_quota_bytes();
        $start = (int)floor($quota * chat_storage_start_percent() / 100);
        $target = (int)floor($quota * chat_storage_target_percent() / 100);
        $state = chat_storage_state($pdo);
        $used = (int)$state['used_bytes'];
        $active = (bool)$state['cleanup_active'];
        $paused = (bool)$state['cleanup_paused'];
        $started = false;

        // Only a manual stop sets cleanup_paused. A temporary lack of eligible rows must not pause
        // cleanup permanently: a later send can add an old-enough unpinned candidate.
        if ($paused && $used < $start) {
            chat_storage_set_cleanup_active($pdo, false, false);
            $paused = false;
            $active = false;
        }
        if ($paused) {
            return ['active' => false, 'paused' => true, 'busy' => false, 'started' => false, 'deleted_count' => 0, 'freed_bytes' => 0, 'interval_seconds' => $interval, 'used_bytes' => $used, 'message' => 'Очистка остановлена администратором.'];
        }
        if (!$active && $used >= $start) {
            chat_storage_set_cleanup_active($pdo, true, false);
            $active = true;
            $started = true;
        }
        if (!$active) {
            return ['active' => false, 'busy' => false, 'started' => false, 'deleted_count' => 0, 'freed_bytes' => 0, 'interval_seconds' => $interval, 'used_bytes' => $used, 'message' => 'Очистка не требуется: хранилище ниже порога.'];
        }
        // Enforce a global interval between batches, even if several users have the chat open.
        // GET_LOCK serializes batches; this timestamp also prevents back-to-back batches.
        if (!empty($state['last_cleanup_at_utc'])) {
            $lastCleanupEpoch = strtotime((string)$state['last_cleanup_at_utc'] . ' UTC');
            if ($lastCleanupEpoch !== false) {
                $elapsed = max(0, time() - $lastCleanupEpoch);
                if ($elapsed < $interval) {
                    return ['active' => true, 'throttled' => true, 'busy' => false, 'started' => $started, 'deleted_count' => 0, 'freed_bytes' => 0, 'interval_seconds' => $interval, 'used_bytes' => $used, 'message' => 'Очистка ожидает следующего интервала.'];
                }
            }
        }
        if ($used <= $target) {
            chat_storage_set_cleanup_active($pdo, false, false);
            return ['active' => false, 'busy' => false, 'started' => $started, 'deleted_count' => 0, 'freed_bytes' => 0, 'interval_seconds' => $interval, 'used_bytes' => $used, 'message' => 'Очистка завершена.'];
        }

        // Candidate selection is deliberately small. Actual rows are locked in a transaction below.
        $sql = 'SELECT id FROM messages WHERE is_pinned=0';
        if ($protectMessageId > 0) {
            $stmt = $pdo->prepare($sql . ' AND id<>? ORDER BY created_at_utc ASC,id ASC LIMIT ' . $batchSize);
            $stmt->execute([$protectMessageId]);
            $candidates = $stmt->fetchAll();
        } else {
            $candidates = $pdo->query($sql . ' ORDER BY created_at_utc ASC,id ASC LIMIT ' . $batchSize)->fetchAll();
        }

        if (!$candidates) {
            // No eligible rows in this pass is not a manual pause. A later message may create
            // an unpinned candidate, so a future threshold-triggered pass must remain possible.
            $pdo->beginTransaction();
            $pdo->exec('UPDATE chat_storage_state SET cleanup_active=0,last_cleanup_at_utc=UTC_TIMESTAMP(),last_cleanup_deleted_count=0,last_cleanup_freed_bytes=0 WHERE id=1');
            $pdo->commit();
            return ['active' => false, 'paused' => false, 'busy' => false, 'started' => $started, 'blocked_by_pins' => true, 'deleted_count' => 0, 'freed_bytes' => 0, 'interval_seconds' => $interval, 'used_bytes' => $used, 'message' => 'Нет доступных для удаления незакреплённых сообщений. Закреплённые сообщения сохранены.'];
        }

        $pdo->beginTransaction();
        $deletedCount = 0;
        $freedBytes = 0;
        $freedAttachments = 0;
        $bodyStmt = $pdo->prepare('SELECT body_json FROM messages WHERE id=? AND is_pinned=0 FOR UPDATE');
        $attStmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes),0) FROM attachments WHERE message_id=?');
        $deleteStmt = $pdo->prepare('DELETE FROM messages WHERE id=? AND is_pinned=0');
        foreach ($candidates as $candidate) {
            $id = (int)$candidate['id'];
            $bodyStmt->execute([$id]);
            $body = $bodyStmt->fetchColumn();
            if ($body === false) continue;
            chat_redact_quotes_to_message($pdo, $id);
            $bodyStmt->execute([$id]);
            $body = $bodyStmt->fetchColumn();
            if ($body === false) continue;
            $bodyBytes = strlen((string)$body);
            $attStmt->execute([$id]);
            $attachmentBytes = (int)$attStmt->fetchColumn();
            $deleteStmt->execute([$id]);
            if ($deleteStmt->rowCount() > 0) {
                $delta = $bodyBytes + $attachmentBytes;
                chat_storage_adjust($pdo, -$delta, -$attachmentBytes);
                $freedBytes += $delta;
                $freedAttachments += $attachmentBytes;
                $deletedCount++;
            }
        }

        $after = chat_storage_state($pdo);
        $newUsed = (int)$after['used_bytes'];
        $stillActive = $deletedCount > 0 && $newUsed > $target;
        $meta = $pdo->prepare('UPDATE chat_storage_state SET cleanup_active=?,cleanup_paused=0,last_cleanup_at_utc=UTC_TIMESTAMP(),last_cleanup_deleted_count=?,last_cleanup_freed_bytes=? WHERE id=1');
        $meta->execute([$stillActive ? 1 : 0, $deletedCount, $freedBytes]);
        $pdo->commit();
        return ['active' => $stillActive, 'paused' => false, 'busy' => false, 'started' => $started, 'deleted_count' => $deletedCount, 'freed_bytes' => $freedBytes, 'attachment_bytes_freed' => $freedAttachments, 'used_bytes' => $newUsed, 'interval_seconds' => $interval, 'message' => $stillActive ? 'Очистка продолжается.' : 'Очистка завершена.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        try { $release = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lockName]); } catch (Throwable $ignored) {}
    }
}

function chat_redact_quotes_to_message(PDO $pdo, int $targetId): void
{
    // Preserve the link to the source message, but remove its saved excerpt when the source is deleted.
    $stmt = $pdo->prepare('SELECT id, body_json FROM messages WHERE is_deleted = 0 AND body_json LIKE ?');
    $stmt->execute(['%"message_id":' . $targetId . ',%']);
    $update = $pdo->prepare('UPDATE messages SET body_json = ? WHERE id = ?');
    while ($row = $stmt->fetch()) {
        $segments = json_decode((string)$row['body_json'], true);
        if (!is_array($segments)) continue;
        $changed = false;
        foreach ($segments as &$seg) {
            if (is_array($seg) && ($seg['type'] ?? '') === 'quote' && (int)($seg['message_id'] ?? 0) === $targetId) {
                $seg['preview'] = 'Сообщение удалено администратором';
                $changed = true;
            }
        }
        unset($seg);
        if ($changed) {
            $json = json_encode($segments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($json)) continue;
            $delta = strlen($json) - strlen((string)$row['body_json']);
            $update->execute([$json, (int)$row['id']]);
            chat_storage_adjust($pdo, $delta, 0);
        }
    }
}
