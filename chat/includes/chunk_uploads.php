<?php
declare(strict_types=1);

/**
 * Временные вложения, передаваемые текстовыми URL-кодированными блоками.
 * Файлы находятся в системном временном каталоге вне публичного каталога сайта.
 */
const CHAT_UPLOAD_CHUNK_BYTES = 131072; // 128 КиБ исходных данных на один POST.
const CHAT_UPLOAD_EXPIRE_SECONDS = 86400; // Неотправленные загрузки удаляются через 24 часа.

function chat_upload_stage_directory(int $userId, string $sessionId): string
{
    if ($userId < 1 || $sessionId === '') {
        throw new RuntimeException('Не удалось определить пользовательскую сессию загрузки.');
    }
    $tempRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    if ($tempRoot === '') $tempRoot = DIRECTORY_SEPARATOR;
    // Добавляем отпечаток каталога приложения, чтобы несколько установок на одном хостинге не пересекались.
    $appKey = substr(hash('sha256', dirname(__DIR__)), 0, 12);
    $sessionKey = hash('sha256', $sessionId . "\0" . $userId);
    return $tempRoot . DIRECTORY_SEPARATOR . 'chat1_uploads_' . $appKey . '_' . $sessionKey;
}

function chat_upload_stage_paths(int $userId, string $sessionId, string $uploadId): array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/i', $uploadId)) {
        throw new InvalidArgumentException('Некорректный идентификатор загрузки.');
    }
    $dir = chat_upload_stage_directory($userId, $sessionId);
    return [
        'dir' => $dir,
        'part' => $dir . DIRECTORY_SEPARATOR . strtolower($uploadId) . '.part',
        'meta' => $dir . DIRECTORY_SEPARATOR . strtolower($uploadId) . '.json',
    ];
}

function chat_upload_stage_write_meta(string $path, array $meta): void
{
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('Не удалось сохранить параметры загрузки.');
    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, $json, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить параметры загрузки. Проверьте права на временный каталог.');
    }
    @chmod($temporary, 0600);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось завершить сохранение параметров загрузки.');
    }
    @chmod($path, 0600);
}

function chat_upload_stage_read_meta(string $path): array
{
    if (!is_file($path)) throw new RuntimeException('Загрузка не найдена или срок её хранения истёк. Выберите файл заново.');
    $raw = file_get_contents($path);
    $meta = $raw === false ? null : json_decode($raw, true);
    if (!is_array($meta)) throw new RuntimeException('Повреждены параметры временной загрузки. Выберите файл заново.');
    return $meta;
}

function chat_upload_stage_get(int $userId, string $sessionId, string $uploadId): array
{
    $paths = chat_upload_stage_paths($userId, $sessionId, $uploadId);
    $meta = chat_upload_stage_read_meta($paths['meta']);
    if ((int)($meta['user_id'] ?? 0) !== $userId ||
        !hash_equals(strtolower($uploadId), strtolower((string)($meta['upload_id'] ?? '')))) {
        throw new RuntimeException('Временная загрузка не принадлежит текущему пользователю.');
    }
    if (!is_file($paths['part'])) throw new RuntimeException('Файл загрузки отсутствует. Выберите файл заново.');
    $size = (int)($meta['size'] ?? 0);
    $currentSize = filesize($paths['part']);
    if ($size < 1 || $currentSize === false || $currentSize !== $size) {
        throw new RuntimeException('Файл загружен не полностью. Отправьте его ещё раз.');
    }
    return ['meta' => $meta, 'path' => $paths['part'], 'paths' => $paths];
}

function chat_upload_stage_remove(int $userId, string $sessionId, string $uploadId): void
{
    try { $paths = chat_upload_stage_paths($userId, $sessionId, $uploadId); }
    catch (Throwable $e) { return; }
    @unlink($paths['part']);
    @unlink($paths['meta']);
    @unlink($paths['meta'] . '.tmp');
}

function chat_upload_stage_cleanup_expired(int $userId, string $sessionId): void
{
    try { $currentDir = chat_upload_stage_directory($userId, $sessionId); }
    catch (Throwable $e) { return; }
    $tempRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    if ($tempRoot === '') $tempRoot = DIRECTORY_SEPARATOR;
    $appKey = substr(hash('sha256', dirname(__DIR__)), 0, 12);
    $pattern = $tempRoot . DIRECTORY_SEPARATOR . 'chat1_uploads_' . $appKey . '_*';
    $cutoff = time() - CHAT_UPLOAD_EXPIRE_SECONDS;

    // Удаляем просроченные загрузки всех сессий этой установки, чтобы после закрытой вкладки
    // временные файлы не занимали место бесконечно. Очистка запускается на старте новой загрузки.
    foreach (glob($pattern) ?: [] as $dir) {
        if (!is_dir($dir)) continue;
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $metaPath) {
            $raw = @file_get_contents($metaPath);
            $meta = $raw === false ? null : json_decode($raw, true);
            $created = is_array($meta) ? (int)($meta['created_at'] ?? 0) : 0;
            if ($created > 0 && $created >= $cutoff) continue;
            $base = substr($metaPath, 0, -5);
            @unlink($base . '.part');
            @unlink($metaPath);
            @unlink($metaPath . '.tmp');
        }
        // Снимаем также сиротские части после обрыва до сохранения метаданных.
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.part') ?: [] as $partPath) {
            $mtime = @filemtime($partPath);
            if ($mtime !== false && $mtime < $cutoff) @unlink($partPath);
        }
        @chmod($dir, 0700);
        if ($dir !== $currentDir && count(glob($dir . DIRECTORY_SEPARATOR . '*') ?: []) === 0) @rmdir($dir);
    }
}
