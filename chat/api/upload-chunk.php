<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/chunk_uploads.php';

chat_require_method('POST');
if (chat_communication_protocol() !== 2) {
    chat_json(['ok' => false, 'error' => 'Блочная загрузка доступна только при communication.protocol = 2.'], 409);
}
chat_verify_csrf();
$user = chat_require_user();
if (($user['ban_mode'] ?? 'none') !== 'none') {
    chat_json(['ok' => false, 'error' => 'Вы не можете загружать вложения при действующей блокировке.'], 403);
}
$userId = (int)$user['id'];
$sessionId = session_id();
$action = (string)($_POST['action'] ?? '');

try {
    if ($action === 'start') {
        chat_upload_stage_cleanup_expired($userId, $sessionId);
        $name = chat_clean_filename((string)($_POST['file_name'] ?? ''));
        $size = filter_var($_POST['file_size'] ?? null, FILTER_VALIDATE_INT);
        $maxFile = max(1, chat_cfg_int('limits', 'attachment_max_bytes', 10485760));
        if (!is_int($size) || $size < 1 || $size > $maxFile) {
            chat_json(['ok' => false, 'error' => 'Файл пустой или превышает лимит размера (' . $maxFile . ' байт).'], 400);
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, chat_setting_list('files', 'allowed_extensions'), true)) {
            chat_json(['ok' => false, 'error' => 'Тип файла ' . ($ext !== '' ? '.' . $ext : 'без расширения') . ' не разрешён.'], 400);
        }

        $uploadId = strtolower((string)($_POST['upload_id'] ?? ''));
        if (!preg_match('/\A[a-f0-9]{32}\z/', $uploadId)) {
            chat_json(['ok' => false, 'error' => 'Некорректный идентификатор загрузки.'], 400);
        }
        $dir = chat_upload_stage_directory($userId, $sessionId);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            chat_json(['ok' => false, 'error' => 'Сервер не может создать временный каталог для вложения.'], 500);
        }
        @chmod($dir, 0700);
        if (!is_writable($dir)) {
            chat_json(['ok' => false, 'error' => 'Временный каталог для вложений недоступен для записи.'], 500);
        }
        $paths = chat_upload_stage_paths($userId, $sessionId, $uploadId);

        // Идемпотентный start: повтор того же запроса продолжает существующую загрузку.
        if (is_file($paths['meta'])) {
            $existingMeta = chat_upload_stage_read_meta($paths['meta']);
            if ((int)($existingMeta['user_id'] ?? 0) !== $userId ||
                !hash_equals($uploadId, strtolower((string)($existingMeta['upload_id'] ?? ''))) ||
                (int)($existingMeta['size'] ?? 0) !== $size ||
                (string)($existingMeta['name'] ?? '') !== $name ||
                !is_file($paths['part'])) {
                chat_json(['ok' => false, 'error' => 'Идентификатор уже занят другой загрузкой.'], 409);
            }
            $currentSize = filesize($paths['part']);
            if ($currentSize === false || $currentSize > $size) {
                chat_upload_stage_remove($userId, $sessionId, $uploadId);
                chat_json(['ok' => false, 'error' => 'Повреждён временный файл. Начните загрузку заново.'], 409);
            }
            chat_json(['ok' => true, 'upload_id' => $uploadId, 'chunk_size' => CHAT_UPLOAD_CHUNK_BYTES, 'next_offset' => $currentSize]);
        }

        // Ограничиваем число и суммарный размер временных файлов для этой сессии.
        $maxCount = max(1, chat_cfg_int('limits', 'attachment_max_count', 3));
        $maxAll = max($maxFile, chat_cfg_int('limits', 'attachments_per_message_max_bytes', 20971520));
        $activeCount = 0;
        $activeBytes = 0;
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $metaPath) {
            $saved = json_decode((string)@file_get_contents($metaPath), true);
            if (!is_array($saved) || (int)($saved['user_id'] ?? 0) !== $userId) continue;
            $activeCount++;
            $activeBytes += max(0, (int)($saved['size'] ?? 0));
        }
        if ($activeCount >= $maxCount) {
            chat_json(['ok' => false, 'error' => 'В одном сообщении допускается не более ' . $maxCount . ' вложений.'], 400);
        }
        if ($activeBytes + $size > $maxAll) {
            chat_json(['ok' => false, 'error' => 'Суммарный размер вложений превышает лимит сообщения.'], 400);
        }

        $handle = @fopen($paths['part'], 'xb');
        if ($handle === false) {
            chat_json(['ok' => false, 'error' => 'Не удалось создать временный файл вложения.'], 500);
        }
        fclose($handle);
        @chmod($paths['part'], 0600);
        try {
            chat_upload_stage_write_meta($paths['meta'], [
                'upload_id' => $uploadId,
                'user_id' => $userId,
                'name' => $name,
                'size' => $size,
                'chunk_size' => CHAT_UPLOAD_CHUNK_BYTES,
                'created_at' => time(),
            ]);
        } catch (Throwable $e) {
            @unlink($paths['part']);
            throw $e;
        }
        chat_json(['ok' => true, 'upload_id' => $uploadId, 'chunk_size' => CHAT_UPLOAD_CHUNK_BYTES, 'next_offset' => 0]);
    }

    if ($action === 'chunk') {
        $uploadId = strtolower((string)($_POST['upload_id'] ?? ''));
        if (!preg_match('/\A[a-f0-9]{32}\z/', $uploadId)) {
            chat_json(['ok' => false, 'error' => 'Некорректный идентификатор загрузки.'], 400);
        }
        $offset = filter_var($_POST['offset'] ?? null, FILTER_VALIDATE_INT);
        $encoded = (string)($_POST['chunk_data'] ?? '');
        if (!is_int($offset) || $offset < 0 || $encoded === '' || strlen($encoded) > (int)ceil(CHAT_UPLOAD_CHUNK_BYTES * 4 / 3) + 16) {
            chat_json(['ok' => false, 'error' => 'Некорректные параметры блока загрузки.'], 400);
        }
        $paths = chat_upload_stage_paths($userId, $sessionId, $uploadId);
        $meta = chat_upload_stage_read_meta($paths['meta']);
        if ((int)($meta['user_id'] ?? 0) !== $userId ||
            !hash_equals($uploadId, strtolower((string)($meta['upload_id'] ?? '')))) {
            chat_json(['ok' => false, 'error' => 'Загрузка не найдена для текущего пользователя.'], 404);
        }
        $declaredSize = (int)($meta['size'] ?? 0);
        if ($declaredSize < 1 || $offset >= $declaredSize) {
            chat_json(['ok' => false, 'error' => 'Блок находится за пределами файла.'], 400);
        }
        $chunk = base64_decode($encoded, true);
        if ($chunk === false) chat_json(['ok' => false, 'error' => 'Не удалось декодировать переданный блок файла.'], 400);
        $expectedLength = min(CHAT_UPLOAD_CHUNK_BYTES, $declaredSize - $offset);
        if (strlen($chunk) !== $expectedLength) {
            chat_json(['ok' => false, 'error' => 'Неверный размер блока файла.'], 400);
        }

        $currentSize = is_file($paths['part']) ? filesize($paths['part']) : false;
        if ($currentSize === false) chat_json(['ok' => false, 'error' => 'Временный файл загрузки не найден.'], 404);

        // Повтор последнего блока после потерянного ответа безопасен: данные сравниваются с уже записанными.
        if ($currentSize === $offset + $expectedLength) {
            $handle = @fopen($paths['part'], 'rb');
            if ($handle !== false) {
                fseek($handle, $offset);
                $alreadyWritten = fread($handle, $expectedLength);
                fclose($handle);
                if (is_string($alreadyWritten) && hash_equals($alreadyWritten, $chunk)) {
                    chat_json(['ok' => true, 'next_offset' => $currentSize, 'complete' => ($currentSize === $declaredSize)]);
                }
            }
        }
        if ($currentSize !== $offset) {
            chat_json(['ok' => false, 'error' => 'Блок пришёл не по порядку. Перезапустите отправку вложения.', 'expected_offset' => $currentSize], 409);
        }

        $handle = @fopen($paths['part'], 'c+b');
        if ($handle === false) chat_json(['ok' => false, 'error' => 'Не удалось открыть временный файл для записи.'], 500);
        try {
            if (fseek($handle, $offset) !== 0) throw new RuntimeException('Не удалось перейти к позиции записи.');
            $written = 0;
            $length = strlen($chunk);
            while ($written < $length) {
                $n = fwrite($handle, substr($chunk, $written));
                if ($n === false || $n === 0) throw new RuntimeException('Не удалось записать полный блок файла.');
                $written += $n;
            }
            fflush($handle);
        } finally {
            fclose($handle);
        }
        @chmod($paths['part'], 0600);
        $next = $offset + $expectedLength;
        chat_json(['ok' => true, 'next_offset' => $next, 'complete' => ($next === $declaredSize)]);
    }

    if ($action === 'abort') {
        $uploadId = strtolower((string)($_POST['upload_id'] ?? ''));
        if (!preg_match('/\A[a-f0-9]{32}\z/', $uploadId)) {
            chat_json(['ok' => false, 'error' => 'Некорректный идентификатор загрузки.'], 400);
        }
        chat_upload_stage_remove($userId, $sessionId, $uploadId);
        chat_json(['ok' => true]);
    }

    chat_json(['ok' => false, 'error' => 'Неизвестная операция загрузки.'], 400);
} catch (Throwable $e) {
    error_log('Chat chunk upload error: ' . $e->getMessage());
    chat_json(['ok' => false, 'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Ошибка загрузки вложения.'], 500);
}
