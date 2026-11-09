#!/usr/local/bin/php
<?php
declare(strict_types=1);

/*
 * Автоматическое обновление VOD-каталога Лиары.
 * Запускать от root внутри клетки liara.
 */

const VIDEO_ROOT = '/data/video';
const VLM_FILE = '/usr/local/etc/liara-vod.vlm';
const PLAYLIST_FILE = '/usr/local/www/nginx/liara.m3u';
const RTSP_BASE = 'rtsp://192.168.2.221:8554/';
const PID_FILE = '/var/run/liara-vod.pid';
const LOG_FILE = '/tmp/liara-vod.log';

function fail(string $message): never
{
    fwrite(STDERR, "ОШИБКА: {$message}\n");
    exit(1);
}

function atomicWrite(string $path, string $contents): void
{
    $dir = dirname($path);

    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        fail("Не удалось создать каталог: {$dir}");
    }

    $tmp = $path . '.tmp.' . getmypid();

    if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
        fail("Не удалось записать: {$tmp}");
    }

    @chmod($tmp, 0644);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        fail("Не удалось заменить файл: {$path}");
    }
}

function vlcRunning(): bool
{
    exec('/usr/bin/pgrep -u vlc -x vlc 2>/dev/null', $output, $status);
    return $status === 0;
}

function portListening(): bool
{
    exec('/usr/bin/sockstat -4 -l 2>/dev/null', $lines, $status);

    if ($status !== 0) {
        return false;
    }

    foreach ($lines as $line) {
        if (preg_match('/:8554\s/', $line)) {
            return true;
        }
    }

    return false;
}

function stopVlc(): void
{
    if (vlcRunning()) {
        exec('/usr/bin/pkill -TERM -u vlc -x vlc 2>/dev/null');

        for ($i = 0; $i < 15; $i++) {
            if (!vlcRunning()) {
                break;
            }
            sleep(1);
        }

        if (vlcRunning()) {
            exec('/usr/bin/pkill -KILL -u vlc -x vlc 2>/dev/null');
            sleep(1);
        }

        if (vlcRunning()) {
            fail('Не удалось остановить старый VLC.');
        }
    }

    @unlink(PID_FILE);
}

function startVlc(): void
{
    $command = '/usr/sbin/daemon -u vlc'
        . ' -P ' . escapeshellarg(PID_FILE)
        . ' -o ' . escapeshellarg(LOG_FILE)
        . ' /usr/local/bin/cvlc'
        . ' --intf dummy'
        . ' --vod-server=vod_rtsp'
        . ' --rtsp-port=8554'
        . ' --vlm-conf=' . escapeshellarg(VLM_FILE)
        . ' --verbose=1';

    exec($command . ' >/dev/null 2>&1', $output, $status);

    if ($status !== 0) {
        fail('Команда запуска VLC завершилась с ошибкой.');
    }

    // Ждём открытия RTSP-порта.
    for ($i = 0; $i < 300; $i++) {
        if (portListening()) {
            return;
        }

        if (!vlcRunning()) {
            break;
        }

        sleep(1);
    }

    $tail = [];
    exec('/usr/bin/tail -n 25 ' . escapeshellarg(LOG_FILE) . ' 2>&1', $tail);

    fail(
        "VLC не открыл порт 8554. Последние строки журнала:\n"
        . implode("\n", $tail)
    );
}

// Проверяем, что скрипт запущен от root.
exec('/usr/bin/id -u', $uidOutput, $uidStatus);

if ($uidStatus !== 0 || ($uidOutput[0] ?? '') !== '0') {
    fail('Запускай скрипт от root внутри клетки liara.');
}

// Защита от одновременного запуска вручную и из cron.
$lock = @fopen('/var/run/liara-vod-update.lock', 'c');

if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fail('Обновление уже выполняется.');
}

if (!is_dir(VIDEO_ROOT) || !is_readable(VIDEO_ROOT)) {
    fail('Видеотека недоступна: ' . VIDEO_ROOT);
}

// Поддерживаемые аудио- и видеоформаты VLC.
$extensions = array_fill_keys([
    '3g2', '3gp', 'aac', 'ac3', 'aif', 'aiff', 'alac',
    'amr', 'ape', 'asf', 'au', 'avi', 'caf',
    'divx', 'dts', 'dv', 'flac', 'flv', 'iso',
    'm1v', 'm2t', 'm2ts', 'm2v', 'm4a', 'm4b',
    'm4v', 'mka', 'mkv', 'mod', 'mov', 'mp2',
    'mp3', 'mp4', 'mpa', 'mpe', 'mpeg', 'mpg',
    'mpv', 'mpv2', 'mts', 'mxf', 'oga', 'ogg',
    'ogm', 'ogv', 'opus', 'qt', 'ra', 'ram',
    'rm', 'rmvb', 's3m', 'spx', 'ts', 'tta',
    'vob', 'wav', 'webm', 'wma', 'wmv', 'wv', 'xm'
], true);

$items = [];
$skippedNames = 0;

try {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            VIDEO_ROOT,
            FilesystemIterator::SKIP_DOTS
        )
    );

    foreach ($iterator as $entry) {
        if (!$entry->isFile() || $entry->isLink()) {
            continue;
        }

        $extension = strtolower($entry->getExtension());

        if (!isset($extensions[$extension])) {
            continue;
        }

        $fullPath = $entry->getPathname();

        $relative = substr(
            $fullPath,
            strlen(VIDEO_ROOT) + 1
        );

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        // Имена с переводами строк пропускаем.
        if (str_contains($relative, "\n") || str_contains($relative, "\r")) {
            $skippedNames++;
            continue;
        }

        $items[] = [
            'relative' => $relative,
            'size' => $entry->getSize(),
            'mtime' => $entry->getMTime()
        ];
    }
} catch (Throwable $e) {
    fail('Ошибка чтения видеотеки: ' . $e->getMessage());
}

if (count($items) === 0) {
    fail('Медиафайлы не найдены. Старый каталог оставлен без изменений.');
}

// Сортировка сначала по папкам, затем по именам файлов.
usort($items, static function (array $a, array $b): int {
    $dirCompare = strnatcasecmp(
        dirname($a['relative']),
        dirname($b['relative'])
    );

    if ($dirCompare !== 0) {
        return $dirCompare;
    }

    return strnatcasecmp(
        basename($a['relative']),
        basename($b['relative'])
    );
});

// Формируем конфигурацию VLC и M3U.
$vlm = "# Автоматически создано liara-vod-update.php\n\n";
$m3u = "#EXTM3U\n";

foreach ($items as $item) {
    $relative = $item['relative'];

    // Постоянный идентификатор на основе относительного пути.
    $id = 'm_' . substr(hash('sha256', $relative), 0, 32);

    // Кодируем каждый компонент пути отдельно.
    $encodedRelative = implode(
        '/',
        array_map('rawurlencode', explode('/', $relative))
    );

    $inputUri = 'file://' . VIDEO_ROOT . '/' . $encodedRelative;

    // Запись VOD в едином VLC.
    // Размер и время изменения позволяют обнаруживать замену файла.
    $vlm .= '# ' . $relative
        . ' | size=' . $item['size']
        . ' | mtime=' . $item['mtime'] . "\n";

    $vlm .= 'new ' . $id . " vod enabled\n";
    $vlm .= 'setup ' . $id . ' input "' . $inputUri . "\"\n\n";

    // В названии пункта сохраняем весь путь папок.
    $m3u .= '#EXTINF:-1,' . $relative . "\n";
    $m3u .= RTSP_BASE . $id . "\n";
}

// Сравниваем новую конфигурацию со старой.
$oldVlm = is_file(VLM_FILE)
    ? @file_get_contents(VLM_FILE)
    : false;

$vlmChanged = ($oldVlm === false || $oldVlm !== $vlm);

if ($vlmChanged) {
    atomicWrite(VLM_FILE, $vlm);

    echo 'Каталог изменился: '
        . count($items)
        . " записей. Перезапускаю VoD...\n";

    stopVlc();
    startVlc();

} elseif (!portListening()) {

    echo "VLC не слушает порт 8554. Запускаю...\n";

    stopVlc();
    startVlc();

} else {
    echo 'Каталог не изменился. VLC не перезапускаю. ';
}

// Публикуем плейлист только после успешного запуска VoD.
$oldM3u = is_file(PLAYLIST_FILE)
    ? @file_get_contents(PLAYLIST_FILE)
    : false;

if ($oldM3u === false || $oldM3u !== $m3u) {
    atomicWrite(PLAYLIST_FILE, $m3u);
}

@chmod(VLM_FILE, 0644);
@chmod(PLAYLIST_FILE, 0644);

echo 'Готово: ' . count($items) . " аудио- и видеофайлов.\n";
echo 'Плейлист: ' . PLAYLIST_FILE . "\n";
echo 'RTSP: ' . RTSP_BASE . "<идентификатор>\n";

if ($skippedNames > 0) {
    echo 'Пропущено файлов с переводами строк в имени: '
        . $skippedNames . "\n";
}