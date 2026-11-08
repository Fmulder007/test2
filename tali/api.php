<?php
declare(strict_types=1);

/**
 * TALI VIDEO — API
 * PHP 8.5 + PDO SQLite.
 *
 * Профиль выбирается по позывному без пароля.
 * Cookie нужна только для запоминания выбранного профиля в браузере.
 * Видео раздаёт Nginx напрямую; этот файл обслуживает только API.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

const TALI_DB = '/var/db/tali/tali.sqlite';
const TALI_MEDIA_ROOT = '/data/video';
const TALI_PROFILE_COOKIE = 'tali_profile';
const TALI_REMEMBER_COOKIE = 'tali_remember';
const TALI_REMEMBER_SECONDS = 30 * 24 * 60 * 60;
const TALI_MAX_NICKNAME_LENGTH = 32;

const TALI_VIDEO_EXTENSIONS = [
    'mp4', 'm4v', 'mov', 'webm', 'ogv', 'ogg',
    'avi', 'mkv', 'mpg', 'mpeg', 'm2v', 'ts',
    'mts', 'm2ts', 'wmv', 'flv', '3gp', 'vob'
];

/* --------------------------------------------------------------------------
 * Общие функции ответа и запросов
 * ----------------------------------------------------------------------- */

function apiResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function apiError(string $message, int $status = 400): never
{
    apiResponse(['ok' => false, 'error' => $message], $status);
}

function readRequestData(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function requestValue(array $input, string $key, mixed $default = null): mixed
{
    if (array_key_exists($key, $input)) {
        return $input[$key];
    }

    return $_GET[$key] ?? $default;
}

function utcNow(): string
{
    return gmdate('Y-m-d H:i:s');
}

/* --------------------------------------------------------------------------
 * SQLite и схема БД
 * ----------------------------------------------------------------------- */

function database(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . TALI_DB, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS schema_version (
    version INTEGER PRIMARY KEY,
    applied_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nickname TEXT NOT NULL COLLATE NOCASE UNIQUE,

    email TEXT DEFAULT NULL,
    email_verified_at TEXT DEFAULT NULL,
    password_hash TEXT DEFAULT NULL,
    recovery_token_hash TEXT DEFAULT NULL,
    recovery_token_expires_at TEXT DEFAULT NULL,

    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    last_seen_at TEXT DEFAULT NULL,
    status TEXT NOT NULL DEFAULT 'active',

    extra_1 TEXT DEFAULT NULL,
    extra_2 TEXT DEFAULT NULL,
    extra_3 TEXT DEFAULT NULL,
    extra_4 TEXT DEFAULT NULL,
    extra_5 TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS watch_positions (
    user_id INTEGER NOT NULL,
    media_path TEXT NOT NULL,
    position_seconds REAL NOT NULL DEFAULT 0,
    duration_seconds REAL NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, media_path),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS watch_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    media_path TEXT NOT NULL,
    last_watched_at TEXT NOT NULL,
    completed INTEGER NOT NULL DEFAULT 0,
    UNIQUE (user_id, media_path),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_history_user_date
    ON watch_history(user_id, last_watched_at DESC);

CREATE TABLE IF NOT EXISTS playlists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_playlists_user
    ON playlists(user_id, updated_at DESC);

CREATE TABLE IF NOT EXISTS playlist_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    playlist_id INTEGER NOT NULL,
    media_path TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    added_at TEXT NOT NULL,
    UNIQUE (playlist_id, media_path),
    FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_playlist_items_order
    ON playlist_items(playlist_id, sort_order, id);
SQL);

    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO schema_version (version, applied_at) VALUES (1, :applied_at)'
    );
    $stmt->execute(['applied_at' => utcNow()]);

    return $pdo;
}

/* --------------------------------------------------------------------------
 * Cookie и выбранный профиль
 * ----------------------------------------------------------------------- */

function setProfileCookies(int $userId, bool $remember): void
{
    $expires = $remember ? time() + TALI_REMEMBER_SECONDS : 0;
    $options = [
        'expires' => $expires,
        'path' => '/',
        'secure' => false, // Домашний HTTP-сайт; при переходе на HTTPS поменяем на true.
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    setcookie(TALI_PROFILE_COOKIE, (string)$userId, $options);
    setcookie(TALI_REMEMBER_COOKIE, $remember ? '1' : '0', $options);
}

function clearProfileCookies(): void
{
    $options = [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    setcookie(TALI_PROFILE_COOKIE, '', $options);
    setcookie(TALI_REMEMBER_COOKIE, '', $options);
}

function currentUser(PDO $pdo): ?array
{
    $rawId = $_COOKIE[TALI_PROFILE_COOKIE] ?? '';
    if (!is_string($rawId) || !ctype_digit($rawId) || (int)$rawId < 1) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT id, nickname, status FROM users WHERE id = :id LIMIT 1"
    );
    $stmt->execute(['id' => (int)$rawId]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        return null;
    }

    return $user;
}

function renewProfileCookies(array $user): void
{
    $remember = ($_COOKIE[TALI_REMEMBER_COOKIE] ?? '0') === '1';
    setProfileCookies((int)$user['id'], $remember);
}

function touchUser(PDO $pdo, array $user): void
{
    $stmt = $pdo->prepare(
        'UPDATE users SET last_seen_at = :seen WHERE id = :id'
    );
    $stmt->execute(['seen' => utcNow(), 'id' => (int)$user['id']]);
}

/**
 * Настройки интерфейса хранятся в резервном поле users.extra_1.
 * JSON-объект допускает дальнейшее расширение без изменения схемы БД.
 */
function readUserExtraSettings(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT extra_1 FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $raw = $stmt->fetchColumn();

    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $settings = json_decode($raw, true);
    return is_array($settings) ? $settings : [];
}

function userPreferences(PDO $pdo, int $userId): array
{
    $settings = readUserExtraSettings($pdo, $userId);

    return [
        'sort_by' => in_array($settings['sort_by'] ?? null, ['name', 'modified', 'size'], true)
            ? $settings['sort_by'] : 'name',
        'sort_direction' => in_array($settings['sort_direction'] ?? null, ['asc', 'desc'], true)
            ? $settings['sort_direction'] : 'asc',
    ];
}

function saveUserPreferences(PDO $pdo, int $userId, array $input): array
{
    $settings = readUserExtraSettings($pdo, $userId);

    $sortBy = $input['sort_by'] ?? $settings['sort_by'] ?? 'name';
    $sortDirection = $input['sort_direction'] ?? $settings['sort_direction'] ?? 'asc';

    if (!in_array($sortBy, ['name', 'modified', 'size'], true)) {
        apiError('Некорректный столбец сортировки');
    }
    if (!in_array($sortDirection, ['asc', 'desc'], true)) {
        apiError('Некорректное направление сортировки');
    }

    // Обновляем только известные ключи, остальные будущие настройки сохраняем.
    $settings['sort_by'] = $sortBy;
    $settings['sort_direction'] = $sortDirection;

    $json = json_encode(
        $settings,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    $stmt = $pdo->prepare(
        'UPDATE users SET extra_1 = :extra_1, updated_at = :updated_at WHERE id = :id'
    );
    $stmt->execute([
        'extra_1' => $json,
        'updated_at' => utcNow(),
        'id' => $userId,
    ]);

    return [
        'sort_by' => $sortBy,
        'sort_direction' => $sortDirection,
    ];
}

/* --------------------------------------------------------------------------
 * Проверка путей видеотеки
 * ----------------------------------------------------------------------- */

function mediaRoot(): string
{
    $root = realpath(TALI_MEDIA_ROOT);
    if ($root === false || !is_dir($root) || !is_readable($root)) {
        throw new RuntimeException('Видеотека недоступна');
    }

    return rtrim($root, DIRECTORY_SEPARATOR);
}

function resolveMediaPath(string $relativePath): ?string
{
    if (str_contains($relativePath, "\0") || str_contains($relativePath, '\\')) {
        return null;
    }

    $relativePath = trim($relativePath, '/');
    if ($relativePath !== '') {
        foreach (explode('/', $relativePath) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
        }
    }

    $root = mediaRoot();
    $candidate = $root;

    if ($relativePath !== '') {
        $candidate .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    $real = realpath($candidate);
    if ($real === false) {
        return null;
    }

    if ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $real;
}

function isVideoFile(string $filename): bool
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, TALI_VIDEO_EXTENSIONS, true);
}

function mediaUrl(string $relativePath): string
{
    $segments = explode('/', $relativePath);
    return '/video/' . implode('/', array_map('rawurlencode', $segments));
}

function validVideo(string $relativePath): ?string
{
    $fullPath = resolveMediaPath($relativePath);
    if ($fullPath === null || !is_file($fullPath) || !is_readable($fullPath)) {
        return null;
    }

    return isVideoFile($fullPath) ? $fullPath : null;
}

function validMediaRelativePath(mixed $value): string
{
    if (!is_string($value) || $value === '' || strlen($value) > 2048) {
        apiError('Некорректный путь к видео');
    }

    if (validVideo($value) === null) {
        apiError('Видео не найдено или формат не поддерживается сайтом', 404);
    }

    return trim($value, '/');
}

/* --------------------------------------------------------------------------
 * Формирование списка каталога
 * ----------------------------------------------------------------------- */

function browseDirectory(PDO $pdo, array $user, string $relativePath): array
{
    $directory = resolveMediaPath($relativePath);
    if ($directory === null || !is_dir($directory) || !is_readable($directory)) {
        apiError('Каталог не найден', 404);
    }

    $root = mediaRoot();
    $items = [];

    $entries = scandir($directory);
    if ($entries === false) {
        apiError('Не удалось прочитать каталог', 500);
    }

    foreach ($entries as $name) {
        if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
            continue;
        }

        $fullPath = $directory . DIRECTORY_SEPARATOR . $name;

        // Не показываем символические ссылки, чтобы каталог не выходил за видеотеку.
        if (is_link($fullPath)) {
            continue;
        }

        $realPath = realpath($fullPath);
        if ($realPath === false ||
            ($realPath !== $root && !str_starts_with($realPath, $root . DIRECTORY_SEPARATOR))) {
            continue;
        }

        $isDirectory = is_dir($realPath);
        $isFile = is_file($realPath);

        if (!$isDirectory && (!$isFile || !isVideoFile($name))) {
            continue;
        }

        $itemRelative = $relativePath === ''
            ? $name
            : $relativePath . '/' . $name;

        $items[] = [
            'name' => $name,
            'type' => $isDirectory ? 'directory' : 'video',
            'path' => $itemRelative,
            'url' => $isFile ? mediaUrl($itemRelative) : null,
            'size' => $isFile ? (int)(filesize($realPath) ?: 0) : null,
            'modified' => (int)(filemtime($realPath) ?: 0),
        ];
    }

    usort($items, static function (array $a, array $b): int {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'directory' ? -1 : 1;
        }

        return strnatcasecmp($a['name'], $b['name']);
    });

    // Добавляем сохранённые позиции для видео текущего каталога.
    $positionStmt = $pdo->prepare(
        'SELECT media_path, position_seconds, duration_seconds, updated_at
         FROM watch_positions WHERE user_id = :user_id'
    );
    $positionStmt->execute(['user_id' => (int)$user['id']]);

    $positions = [];
    foreach ($positionStmt->fetchAll() as $position) {
        $positions[$position['media_path']] = [
            'position' => (float)$position['position_seconds'],
            'duration' => (float)$position['duration_seconds'],
            'updated_at' => $position['updated_at'],
        ];
    }

    foreach ($items as &$item) {
        if ($item['type'] === 'video') {
            $item['watch'] = $positions[$item['path']] ?? null;
        }
    }
    unset($item);

    return [
        'path' => $relativePath,
        'parent' => ($relativePath === '') ? null : ((dirname($relativePath) === '.') ? '' : dirname($relativePath)),
        'items' => $items,
    ];
}

/* --------------------------------------------------------------------------
 * История и позиции
 * ----------------------------------------------------------------------- */

function savePosition(PDO $pdo, array $user, array $input): array
{
    $mediaPath = validMediaRelativePath($input['path'] ?? null);
    $position = filter_var($input['position'] ?? null, FILTER_VALIDATE_FLOAT);
    $duration = filter_var($input['duration'] ?? 0, FILTER_VALIDATE_FLOAT);

    if ($position === false || $duration === false ||
        !is_finite((float)$position) || !is_finite((float)$duration) ||
        $position < 0 || $duration < 0) {
        apiError('Некорректная позиция воспроизведения');
    }

    $position = (float)$position;
    $duration = (float)$duration;
    if ($duration > 0) {
        $position = min($position, $duration);
    }

    $completed = $duration > 0 && $position >= max(0, $duration - 15);
    $now = utcNow();

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO watch_positions (
    user_id, media_path, position_seconds, duration_seconds, updated_at
) VALUES (
    :user_id, :media_path, :position, :duration, :updated_at
)
ON CONFLICT(user_id, media_path) DO UPDATE SET
    position_seconds = excluded.position_seconds,
    duration_seconds = excluded.duration_seconds,
    updated_at = excluded.updated_at
SQL);
        $stmt->execute([
            'user_id' => (int)$user['id'],
            'media_path' => $mediaPath,
            'position' => $position,
            'duration' => $duration,
            'updated_at' => $now,
        ]);

        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO watch_history (user_id, media_path, last_watched_at, completed)
VALUES (:user_id, :media_path, :watched_at, :completed)
ON CONFLICT(user_id, media_path) DO UPDATE SET
    last_watched_at = excluded.last_watched_at,
    completed = excluded.completed
SQL);
        $stmt->execute([
            'user_id' => (int)$user['id'],
            'media_path' => $mediaPath,
            'watched_at' => $now,
            'completed' => $completed ? 1 : 0,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'path' => $mediaPath,
        'position' => $position,
        'duration' => $duration,
        'completed' => $completed,
    ];
}

function getHistory(PDO $pdo, array $user, int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->prepare(
        'SELECT h.media_path, h.last_watched_at, h.completed,
                p.position_seconds, p.duration_seconds
         FROM watch_history h
         LEFT JOIN watch_positions p
           ON p.user_id = h.user_id AND p.media_path = h.media_path
         WHERE h.user_id = :user_id
         ORDER BY h.last_watched_at DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':user_id', (int)$user['id'], PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['url'] = mediaUrl($row['media_path']);
        $row['completed'] = (bool)$row['completed'];
        $row['position_seconds'] = $row['position_seconds'] === null ? 0.0 : (float)$row['position_seconds'];
        $row['duration_seconds'] = $row['duration_seconds'] === null ? 0.0 : (float)$row['duration_seconds'];
    }
    unset($row);

    return $rows;
}

/* --------------------------------------------------------------------------
 * Плейлисты
 * ----------------------------------------------------------------------- */

function ownedPlaylist(PDO $pdo, array $user, int $playlistId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, created_at, updated_at
         FROM playlists WHERE id = :id AND user_id = :user_id LIMIT 1'
    );
    $stmt->execute([
        'id' => $playlistId,
        'user_id' => (int)$user['id'],
    ]);

    $playlist = $stmt->fetch();
    if (!$playlist) {
        apiError('Плейлист не найден', 404);
    }

    return $playlist;
}

function playlistItems(PDO $pdo, int $playlistId, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT pi.id, pi.media_path, pi.sort_order, pi.added_at,
                wp.position_seconds, wp.duration_seconds
         FROM playlist_items pi
         LEFT JOIN watch_positions wp
           ON wp.media_path = pi.media_path AND wp.user_id = :user_id
         WHERE pi.playlist_id = :playlist_id
         ORDER BY pi.sort_order, pi.id'
    );
    $stmt->execute([
        'playlist_id' => $playlistId,
        'user_id' => $userId,
    ]);

    $items = $stmt->fetchAll();
    foreach ($items as &$item) {
        $item['url'] = mediaUrl($item['media_path']);
        $fullPath = resolveMediaPath($item['media_path']);
        $item['size'] = ($fullPath !== null && is_file($fullPath))
            ? (int)(filesize($fullPath) ?: 0) : null;
        $item['modified'] = ($fullPath !== null && is_file($fullPath))
            ? (int)(filemtime($fullPath) ?: 0) : 0;
        $item['id'] = (int)$item['id'];
        $item['sort_order'] = (int)$item['sort_order'];
        $item['position_seconds'] = $item['position_seconds'] === null
            ? 0.0 : (float)$item['position_seconds'];
        $item['duration_seconds'] = $item['duration_seconds'] === null
            ? 0.0 : (float)$item['duration_seconds'];
    }
    unset($item);

    return $items;
}

function listPlaylists(PDO $pdo, array $user): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, created_at, updated_at
         FROM playlists WHERE user_id = :user_id
         ORDER BY name COLLATE NOCASE, id'
    );
    $stmt->execute(['user_id' => (int)$user['id']]);

    $result = [];
    foreach ($stmt->fetchAll() as $playlist) {
        $playlist['id'] = (int)$playlist['id'];
        $playlist['items'] = playlistItems($pdo, $playlist['id'], (int)$user['id']);
        $result[] = $playlist;
    }

    return $result;
}

/* --------------------------------------------------------------------------
 * VLC: отправка снимка плейлиста в общий эфир
 * ----------------------------------------------------------------------- */

function vlcReadUntil($socket, string $marker, int $limit = 65536): string
{
    $buffer = '';
    while (strlen($buffer) < $limit && !str_contains($buffer, $marker)) {
        $char = fread($socket, 1);
        if ($char === false || $char === '') {
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                throw new RuntimeException('Истекло время ожидания ответа VLC');
            }
            if (feof($socket)) {
                throw new RuntimeException('VLC закрыл соединение управления');
            }
            continue;
        }
        $buffer .= $char;
    }
    if (!str_contains($buffer, $marker)) {
        throw new RuntimeException('Не получен ожидаемый ответ VLC');
    }
    return $buffer;
}

function vlcCommand($socket, string $command): string
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('Не удалось отправить команду VLC');
    }
    $response = vlcReadUntil($socket, '>');
    if (preg_match('/(?:^|\\n)\\s*(?:error|unable|failed)\\b/i', $response)) {
        throw new RuntimeException('VLC отклонил команду: ' . trim($response));
    }
    return $response;
}

function broadcastPlaylist(PDO $pdo, array $user, mixed $playlistId, mixed $orderedItemIds = null): array
{
    $id = filter_var($playlistId, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        apiError('Некорректный номер плейлиста');
    }
    ownedPlaylist($pdo, $user, (int)$id);
    $storedItems = playlistItems($pdo, (int)$id, (int)$user['id']);
    if (!$storedItems) {
        apiError('Нельзя отправить в эфир пустой плейлист');
    }
    if (count($storedItems) > 500) {
        apiError('В плейлисте слишком много файлов (максимум 500)');
    }

    // Клиент передаёт ID в текущем порядке отображения (с учётом сортировки).
    // Проверяем, что это полный и точный набор элементов данного плейлиста.
    $items = $storedItems;
    if ($orderedItemIds !== null) {
        if (!is_array($orderedItemIds) || count($orderedItemIds) !== count($storedItems)) {
            apiError('Получен неполный или некорректный порядок плейлиста');
        }

        $itemsById = [];
        foreach ($storedItems as $storedItem) {
            $itemsById[(string)(int)$storedItem['id']] = $storedItem;
        }

        $orderedItems = [];
        $seenIds = [];
        foreach ($orderedItemIds as $rawItemId) {
            $itemId = filter_var($rawItemId, FILTER_VALIDATE_INT);
            if ($itemId === false || $itemId < 1) {
                apiError('Некорректный элемент в порядке плейлиста');
            }

            $key = (string)$itemId;
            if (!isset($itemsById[$key]) || isset($seenIds[$key])) {
                apiError('Порядок содержит чужой или повторяющийся элемент плейлиста');
            }

            $seenIds[$key] = true;
            $orderedItems[] = $itemsById[$key];
        }

        if (count($orderedItems) !== count($storedItems)) {
            apiError('Получен неполный порядок плейлиста');
        }

        $items = $orderedItems;
    }

    $paths = [];
    foreach ($items as $item) {
        $path = validMediaRelativePath($item['media_path'] ?? null);
        if (preg_match('/[\\x00-\\x1F\\x7F]/', $path)) {
            apiError('В имени файла есть недопустимые управляющие символы');
        }
        $paths[] = $path;
    }

    $configPath = '/usr/local/etc/tali-vlc/tali-api.php';
    if (!is_file($configPath) || !is_readable($configPath)) {
        apiError('Не настроено соединение TALI с VLC: отсутствует /usr/local/etc/tali-vlc/tali-api.php', 503);
    }
    $config = require $configPath;
    $password = is_array($config) ? ($config['password'] ?? '') : '';
    if (!is_string($password) || $password === '') {
        apiError('Не задан пароль управления VLC в tali-api.php', 503);
    }

    $errno = 0;
    $error = '';
    $socket = @stream_socket_client('tcp://127.0.0.1:4212', $errno, $error, 5, STREAM_CLIENT_CONNECT);
    if ($socket === false) {
        apiError('Не удалось подключиться к VLC: ' . ($error !== '' ? $error : 'порт 4212 недоступен'), 503);
    }
    stream_set_timeout($socket, 5);
    try {
        vlcReadUntil($socket, 'Password:');
        if (fwrite($socket, $password . "\r\n") === false) {
            throw new RuntimeException('Не удалось передать пароль VLC');
        }
        vlcReadUntil($socket, '>');

        // Сохраняем VLM-канал и HTTP-выход. Заменяем только список входов.
        // Для одного файла добавляем второй такой же вход: VLM должен
        // сначала уйти с текущего индекса, иначе play 1 может стать no-op.
        $needsTemporaryInput = count($paths) === 1;
        $vlcPaths = $paths;
        if ($needsTemporaryInput) {
            // Для одноэлементного списка временно добавляем второй слот,
            // чтобы сменить индекс активного входа в VLC.
            $vlcPaths[] = $vlcPaths[0];
        }

        vlcCommand($socket, 'setup tali inputdel all');
        foreach ($vlcPaths as $path) {
            $escapedPath = str_replace(['\\\\', '"'], ['\\\\\\\\', '\\"'], '/data/video/' . $path);
            vlcCommand($socket, 'setup tali input "file://' . $escapedPath . '"');
        }
        vlcCommand($socket, 'setup tali loop');

        // Сначала уходим на слот 2, затем включаем первый файл нового списка.
        // Сам broadcast и sout-keep не останавливаются.
        vlcCommand($socket, 'control tali play 2');
        vlcCommand($socket, 'control tali play 1');

        if ($needsTemporaryInput) {
            // Удаляем временный слот: в VLM остаётся ровно один реальный вход.
            vlcCommand($socket, 'setup tali inputdeln 2');
        }
    } catch (Throwable $e) {
        fclose($socket);
        apiError('Не удалось запустить эфир VLC: ' . $e->getMessage(), 503);
    }
    fclose($socket);
    return ['playlist_id' => (int)$id, 'items' => count($paths)];
}

/* --------------------------------------------------------------------------
 * Выполнение API
 * ----------------------------------------------------------------------- */

try {
    $pdo = database();
    $input = readRequestData();
    $action = (string)requestValue($input, 'action', '');

    if ($action === 'login') {
        $nickname = trim((string)($input['nickname'] ?? ''));
        if ($nickname === '' || preg_match('/[\x00-\x1F\x7F]/u', $nickname)) {
            apiError('Укажи имя или позывной');
        }

        preg_match_all('/./us', $nickname, $characters);
        if (count($characters[0]) > TALI_MAX_NICKNAME_LENGTH) {
            apiError('Имя не должно быть длиннее 32 символов');
        }

        $remember = array_key_exists('remember', $input)
            ? (bool)$input['remember']
            : true;
        $now = utcNow();

        $stmt = $pdo->prepare(
            'SELECT id, nickname FROM users WHERE nickname = :nickname COLLATE NOCASE LIMIT 1'
        );
        $stmt->execute(['nickname' => $nickname]);
        $user = $stmt->fetch();

        if (!$user) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (nickname, created_at, updated_at, last_seen_at)
                     VALUES (:nickname, :created_at, :updated_at, :last_seen_at)'
                );
                $stmt->execute([
                    'nickname' => $nickname,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'last_seen_at' => $now,
                ]);
                $user = [
                    'id' => (int)$pdo->lastInsertId(),
                    'nickname' => $nickname,
                ];
            } catch (PDOException $e) {
                // Если два браузера одновременно создали один позывной, читаем победившую запись.
                $stmt = $pdo->prepare(
                    'SELECT id, nickname FROM users WHERE nickname = :nickname COLLATE NOCASE LIMIT 1'
                );
                $stmt->execute(['nickname' => $nickname]);
                $user = $stmt->fetch();
                if (!$user) {
                    throw $e;
                }
            }
        }

        $stmt = $pdo->prepare('UPDATE users SET last_seen_at = :seen WHERE id = :id');
        $stmt->execute(['seen' => $now, 'id' => (int)$user['id']]);
        setProfileCookies((int)$user['id'], $remember);

        apiResponse([
            'ok' => true,
            'user' => [
                'id' => (int)$user['id'],
                'nickname' => $user['nickname'],
            ],
            'preferences' => userPreferences($pdo, (int)$user['id']),
            'remember' => $remember,
        ]);
    }

    if ($action === 'logout') {
        clearProfileCookies();
        apiResponse(['ok' => true]);
    }

    $user = currentUser($pdo);
    if (!$user) {
        clearProfileCookies();
        apiError('Сначала выбери профиль', 401);
    }

    renewProfileCookies($user);
    touchUser($pdo, $user);

    switch ($action) {
        case 'me':
            apiResponse([
                'ok' => true,
                'user' => [
                    'id' => (int)$user['id'],
                    'nickname' => $user['nickname'],
                ],
                'preferences' => userPreferences($pdo, (int)$user['id']),
                'remember' => ($_COOKIE[TALI_REMEMBER_COOKIE] ?? '0') === '1',
            ]);

        case 'touch':
            apiResponse(['ok' => true]);

        case 'save_preferences':
            $preferences = $input['preferences'] ?? null;
            if (!is_array($preferences)) {
                apiError('Некорректные настройки пользователя');
            }
            $savedPreferences = saveUserPreferences($pdo, (int)$user['id'], $preferences);
            apiResponse(['ok' => true, 'preferences' => $savedPreferences]);

        case 'browse':
            $path = (string)requestValue($input, 'path', '');
            $listing = browseDirectory($pdo, $user, $path);
            apiResponse(['ok' => true] + $listing);

        case 'save_position':
            apiResponse(['ok' => true, 'saved' => savePosition($pdo, $user, $input)]);

        case 'history':
            $limit = (int)requestValue($input, 'limit', 100);
            apiResponse(['ok' => true, 'items' => getHistory($pdo, $user, $limit)]);

        case 'playlists':
            apiResponse(['ok' => true, 'items' => listPlaylists($pdo, $user)]);

        case 'playlist_broadcast':
            apiResponse(['ok' => true, 'broadcast' => broadcastPlaylist(
                $pdo,
                $user,
                $input['playlist_id'] ?? null,
                $input['item_ids'] ?? null
            )]);

        case 'playlist_create':
            $name = trim((string)($input['name'] ?? ''));
            if ($name === '' || mb_strlen_safe($name) > 80) {
                apiError('Название плейлиста должно содержать от 1 до 80 символов');
            }

            $now = utcNow();
            $stmt = $pdo->prepare(
                'INSERT INTO playlists (user_id, name, created_at, updated_at)
                 VALUES (:user_id, :name, :created_at, :updated_at)'
            );
            $stmt->execute([
                'user_id' => (int)$user['id'],
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $id = (int)$pdo->lastInsertId();
            apiResponse(['ok' => true, 'id' => $id, 'name' => $name]);

        case 'playlist_rename':
            $playlistId = filter_var($input['playlist_id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim((string)($input['name'] ?? ''));
            if (!$playlistId || $playlistId < 1 || $name === '' || mb_strlen_safe($name) > 80) {
                apiError('Некорректные данные плейлиста');
            }
            ownedPlaylist($pdo, $user, (int)$playlistId);
            $stmt = $pdo->prepare(
                'UPDATE playlists SET name = :name, updated_at = :updated_at
                 WHERE id = :id AND user_id = :user_id'
            );
            $stmt->execute([
                'name' => $name,
                'updated_at' => utcNow(),
                'id' => (int)$playlistId,
                'user_id' => (int)$user['id'],
            ]);
            apiResponse(['ok' => true]);

        case 'playlist_delete':
            $playlistId = filter_var($input['playlist_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$playlistId || $playlistId < 1) {
                apiError('Некорректный номер плейлиста');
            }
            ownedPlaylist($pdo, $user, (int)$playlistId);
            $stmt = $pdo->prepare(
                'DELETE FROM playlists WHERE id = :id AND user_id = :user_id'
            );
            $stmt->execute([
                'id' => (int)$playlistId,
                'user_id' => (int)$user['id'],
            ]);
            apiResponse(['ok' => true]);

        case 'playlist_add':
            $playlistId = filter_var($input['playlist_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$playlistId || $playlistId < 1) {
                apiError('Некорректный номер плейлиста');
            }
            $playlist = ownedPlaylist($pdo, $user, (int)$playlistId);
            $mediaPath = validMediaRelativePath($input['path'] ?? null);

            $stmt = $pdo->prepare(
                'SELECT COALESCE(MAX(sort_order), -1) + 1
                 FROM playlist_items WHERE playlist_id = :playlist_id'
            );
            $stmt->execute(['playlist_id' => (int)$playlistId]);
            $order = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'INSERT OR IGNORE INTO playlist_items
                 (playlist_id, media_path, sort_order, added_at)
                 VALUES (:playlist_id, :media_path, :sort_order, :added_at)'
            );
            $stmt->execute([
                'playlist_id' => (int)$playlistId,
                'media_path' => $mediaPath,
                'sort_order' => $order,
                'added_at' => utcNow(),
            ]);

            $stmt = $pdo->prepare(
                'UPDATE playlists SET updated_at = :updated_at WHERE id = :id'
            );
            $stmt->execute(['updated_at' => utcNow(), 'id' => (int)$playlistId]);
            apiResponse(['ok' => true, 'playlist' => $playlist]);

        case 'playlist_remove':
            $playlistId = filter_var($input['playlist_id'] ?? null, FILTER_VALIDATE_INT);
            $itemId = filter_var($input['item_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$playlistId || $playlistId < 1 || !$itemId || $itemId < 1) {
                apiError('Некорректные данные элемента плейлиста');
            }
            ownedPlaylist($pdo, $user, (int)$playlistId);
            $stmt = $pdo->prepare(
                'DELETE FROM playlist_items WHERE id = :item_id AND playlist_id = :playlist_id'
            );
            $stmt->execute([
                'item_id' => (int)$itemId,
                'playlist_id' => (int)$playlistId,
            ]);
            $stmt = $pdo->prepare(
                'UPDATE playlists SET updated_at = :updated_at WHERE id = :id'
            );
            $stmt->execute(['updated_at' => utcNow(), 'id' => (int)$playlistId]);
            apiResponse(['ok' => true]);

        case 'playlist_reorder':
            $playlistId = filter_var($input['playlist_id'] ?? null, FILTER_VALIDATE_INT);
            $itemIds = $input['item_ids'] ?? null;
            if (!$playlistId || $playlistId < 1 || !is_array($itemIds)) {
                apiError('Некорректный порядок плейлиста');
            }
            ownedPlaylist($pdo, $user, (int)$playlistId);

            $pdo->beginTransaction();
            try {
                $update = $pdo->prepare(
                    'UPDATE playlist_items SET sort_order = :sort_order
                     WHERE id = :id AND playlist_id = :playlist_id'
                );
                foreach (array_values($itemIds) as $index => $itemId) {
                    $id = filter_var($itemId, FILTER_VALIDATE_INT);
                    if (!$id || $id < 1) {
                        continue;
                    }
                    $update->execute([
                        'sort_order' => $index,
                        'id' => (int)$id,
                        'playlist_id' => (int)$playlistId,
                    ]);
                }
                $stmt = $pdo->prepare(
                    'UPDATE playlists SET updated_at = :updated_at WHERE id = :id'
                );
                $stmt->execute(['updated_at' => utcNow(), 'id' => (int)$playlistId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            apiResponse(['ok' => true]);

        default:
            apiError('Неизвестное действие API', 404);
    }
} catch (Throwable $e) {
    $logContext = [
        'action' => $action ?? 'initialization',
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
    if ($e instanceof PDOException && isset($e->errorInfo) && is_array($e->errorInfo)) {
        $logContext['sqlstate'] = $e->errorInfo[0] ?? null;
        $logContext['driver_code'] = $e->errorInfo[1] ?? null;
    }
    error_log(
        'Tali API exception: ' .
        json_encode($logContext, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
    );
    apiError('Внутренняя ошибка Tali API', 500);
}

/** UTF-8 length without requiring mbstring. */
function mb_strlen_safe(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    preg_match_all('/./us', $value, $matches);
    return count($matches[0]);
}
