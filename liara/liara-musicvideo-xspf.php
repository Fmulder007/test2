#!/usr/local/bin/php
<?php
declare(strict_types=1);

/*
 * LIARA — построение иерархического XSPF-каталога MusicVideo.
 *
 * Сканирует /data/video/MusicVideo рекурсивно.
 * Создаёт VLC VOD-записи и XSPF с настоящими вложенными папками VLC.
 *
 * Запускать от root ВНУТРИ jail liara:
 *   /usr/local/sbin/liara-musicvideo-xspf.php
 */

const VIDEO_ROOT = '/data/video/MusicVideo';
const VLM_FILE = '/usr/local/etc/liara-vod.vlm';
const XSPF_FILE = '/usr/local/www/liara/musicvideo.xspf';
const RTSP_BASE = 'rtsp://192.168.2.221:8554/';
const PID_FILE = '/var/run/liara-vod.pid';
const LOG_FILE = '/tmp/liara-vod.log';
const LOCK_FILE = '/var/run/liara-musicvideo-xspf.lock';
const VLC_NAMESPACE = 'http://www.videolan.org/vlc/playlist/0';

function fail(string $message): never
{
    fwrite(STDERR, "ОШИБКА: {$message}\n");
    exit(1);
}

function xmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

function atomicWrite(string $path, string $contents): void
{
    $directory = dirname($path);

    if (!is_dir($directory)
        && !@mkdir($directory, 0755, true)
        && !is_dir($directory)
    ) {
        fail("Не удалось создать каталог: {$directory}");
    }

    $temporary = $path . '.tmp.' . getmypid();

    if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
        fail("Не удалось записать временный файл: {$temporary}");
    }

    @chmod($temporary, 0644);

    if (!@rename($temporary, $path)) {
        @unlink($temporary);
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

        for ($attempt = 0; $attempt < 15; $attempt++) {
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
            fail('Не удалось остановить старый процесс VLC.');
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

    for ($attempt = 0; $attempt < 30; $attempt++) {
        if (portListening()) {
            return;
        }

        if (!vlcRunning()) {
            break;
        }

        sleep(1);
    }

    $tail = [];
    exec('/usr/bin/tail -n 30 ' . escapeshellarg(LOG_FILE) . ' 2>&1', $tail);

    fail(
        "VLC не открыл RTSP-порт 8554. Последние строки журнала:\n"
        . implode("\n", $tail)
    );
}

function newTreeNode(): array
{
    return [
        'dirs' => [],
        'files' => []
    ];
}

function compareNames(string $left, string $right): int
{
    $comparison = strnatcasecmp($left, $right);
    return $comparison !== 0 ? $comparison : strcmp($left, $right);
}

/**
 * Строит содержимое VLC-узла.
 * Для каждого файла назначает tid и добавляет запись в trackList.
 */
function buildVlcNodeContents(array $node, array &$tracks, int $level): string
{
    $entries = [];

    foreach ($node['dirs'] as $name => $child) {
        $entries[] = [
            'type' => 'dir',
            'name' => (string)$name,
            'node' => $child
        ];
    }

    foreach ($node['files'] as $file) {
        $entries[] = [
            'type' => 'file',
            'name' => basename($file['relative']),
            'file' => $file
        ];
    }

    usort($entries, static function (array $a, array $b): int {
        $comparison = compareNames($a['name'], $b['name']);

        if ($comparison !== 0) {
            return $comparison;
        }

        // При одинаковом имени сначала показываем папку.
        return ($a['type'] === 'dir' ? 0 : 1)
            <=> ($b['type'] === 'dir' ? 0 : 1);
    });

    $xml = '';
    $indent = str_repeat('  ', $level);

    foreach ($entries as $entry) {
        if ($entry['type'] === 'dir') {
            $xml .= $indent
                . '<vlc:node title="' . xmlEscape($entry['name']) . '">' . "\n";

            $xml .= buildVlcNodeContents(
                $entry['node'],
                $tracks,
                $level + 1
            );

            $xml .= $indent . "</vlc:node>\n";
            continue;
        }

        $tid = count($tracks);
        $tracks[] = $entry['file'];

        $xml .= $indent . '<vlc:item tid="' . $tid . '" />' . "\n";
    }

    return $xml;
}

// Проверяем запуск от root.
exec('/usr/bin/id -u', $uidOutput, $uidStatus);

if ($uidStatus !== 0 || ($uidOutput[0] ?? '') !== '0') {
    fail('Запускай скрипт от root внутри клетки liara.');
}

// Блокируем параллельные запуски вручную и из cron.
$lock = @fopen(LOCK_FILE, 'c');

if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fail('Обновление каталога уже выполняется.');
}

if (!is_dir(VIDEO_ROOT) || !is_readable(VIDEO_ROOT)) {
    fail('Каталог недоступен: ' . VIDEO_ROOT);
}

// Распространённые аудио- и видеоформаты, которые умеет читать VLC.
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
        $relative = substr($fullPath, strlen(VIDEO_ROOT) + 1);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        // Для XML/VLM пропускаем некорректный UTF-8 и управляющие символы.
        if (preg_match('//u', $relative) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\r\n]/', $relative)
        ) {
            $skippedNames++;
            continue;
        }

        $id = 'mv_' . substr(hash('sha256', $relative), 0, 32);

        $items[] = [
            'relative' => $relative,
            'id' => $id,
            'size' => $entry->getSize(),
            'mtime' => $entry->getMTime()
        ];
    }
} catch (Throwable $e) {
    fail('Ошибка чтения видеотеки: ' . $e->getMessage());
}

if (count($items) === 0) {
    fail('В MusicVideo не найдено подходящих файлов. Старый каталог не изменён.');
}

// Сортируем список файлов естественным образом по относительному пути.
usort($items, static function (array $a, array $b): int {
    return compareNames($a['relative'], $b['relative']);
});

// Строим дерево каталогов от корня MusicVideo.
$tree = newTreeNode();

foreach ($items as $item) {
    $parts = explode('/', $item['relative']);
    array_pop($parts); // имя файла

    $node = &$tree;

    foreach ($parts as $part) {
        if (!isset($node['dirs'][$part])) {
            $node['dirs'][$part] = newTreeNode();
        }

        $node = &$node['dirs'][$part];
    }

    $node['files'][] = $item;
    unset($node);
}

// Создаём дерево VLC и одновременно получаем порядок trackList.
$tracks = [];

$treeXml = '  <vlc:node title="MusicVideo">' . "\n";
$treeXml .= buildVlcNodeContents($tree, $tracks, 2);
$treeXml .= "  </vlc:node>\n";

// Формируем VLM-конфигурацию для одного общего RTSP-сервера.
$vlm = "# Автоматически создано liara-musicvideo-xspf.php\n";
$vlm .= "# Источник: " . VIDEO_ROOT . "\n";
$vlm .= "# Количество файлов: " . count($tracks) . "\n\n";

foreach ($tracks as $track) {
    $relative = $track['relative'];

    // Кодируем каждый компонент пути отдельно: пробелы, кириллица,
    // кавычки и скобки не ломают URI внутри VLM-команды.
    $encodedRelative = implode(
        '/',
        array_map('rawurlencode', explode('/', $relative))
    );

    $inputUri = 'file://' . VIDEO_ROOT . '/' . $encodedRelative;

    $vlm .= '# ' . $relative
        . ' | size=' . $track['size']
        . ' | mtime=' . $track['mtime'] . "\n";

    $vlm .= 'new ' . $track['id'] . " vod enabled\n";
    $vlm .= 'setup ' . $track['id']
        . ' input "' . $inputUri . "\"\n\n";
}

// Формируем XSPF с trackList и настоящими вложенными VLC-папками.
$xspf = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$xspf .= '<playlist version="1"'
    . ' xmlns="http://xspf.org/ns/0/"'
    . ' xmlns:vlc="http://www.videolan.org/vlc/playlist/ns/0/">' . "\n";
$xspf .= "  <title>LIARA — MusicVideo</title>\n";
$xspf .= "  <creator>LIARA</creator>\n";
$xspf .= "  <trackList>\n";

foreach ($tracks as $tid => $track) {
    $title = basename($track['relative']);
    $location = RTSP_BASE . $track['id'];

    $xspf .= "    <track>\n";
    $xspf .= '      <location>' . xmlEscape($location) . "</location>\n";
    $xspf .= '      <title>' . xmlEscape($title) . "</title>\n";
    $xspf .= '      <extension application="' . VLC_NAMESPACE . '">' . "\n";
    $xspf .= '        <vlc:id>' . $tid . "</vlc:id>\n";
    $xspf .= "      </extension>\n";
    $xspf .= "    </track>\n";
}

$xspf .= "  </trackList>\n";
$xspf .= '  <extension application="' . VLC_NAMESPACE . '">' . "\n";
$xspf .= $treeXml;
$xspf .= "  </extension>\n";
$xspf .= "</playlist>\n";

// Не перезаписываем VLM и XSPF пустым или неполным каталогом.
$oldVlm = is_file(VLM_FILE) ? @file_get_contents(VLM_FILE) : false;
$vlmChanged = ($oldVlm === false || $oldVlm !== $vlm);

if ($vlmChanged) {
    atomicWrite(VLM_FILE, $vlm);

    echo 'Каталог MusicVideo изменился: '
        . count($tracks)
        . " файлов. Перезапускаю VLC VoD...\n";

    stopVlc();
    startVlc();
} elseif (!portListening()) {
    echo "VLM не изменился, но RTSP-порт закрыт. Запускаю VLC...\n";

    stopVlc();
    startVlc();
} else {
    echo "Каталог не изменился. Работающий VLC не трогаю.\n";
}

// Публикуем XSPF после успешного запуска/проверки RTSP-сервера.
$oldXspf = is_file(XSPF_FILE) ? @file_get_contents(XSPF_FILE) : false;

if ($oldXspf === false || $oldXspf !== $xspf) {
    atomicWrite(XSPF_FILE, $xspf);
    echo "XSPF-плейлист обновлён.\n";
} else {
    echo "XSPF-плейлист не изменился.\n";
}

@chmod(VLM_FILE, 0644);
@chmod(XSPF_FILE, 0644);

echo 'Готово: ' . count($tracks) . " файлов в каталоге MusicVideo.\n";
echo 'XSPF: ' . XSPF_FILE . "\n";
echo 'На хосте: /jails/liara' . XSPF_FILE . "\n";
echo 'Адрес VLC: http://192.168.2.221/musicvideo.xspf' . "\n";

if ($skippedNames > 0) {
    echo 'Пропущено файлов с некорректным UTF-8 или управляющими символами: '
        . $skippedNames . "\n";
}
