#!/usr/local/bin/php
<?php
declare(strict_types=1);

/*
 * LIARA — генератор иерархического XSPF для всей видеотеки.
 *
 * Сканирует /data/video, сохраняет дерево каталогов и формирует
 * прямые HTTP-ссылки на медиафайлы через Nginx.
 *
 * Скрипт только читает файлы и записывает XSPF.
 * Он не запускает, не останавливает и не управляет серверным VLC.
 *
 * Запускать от root внутри jail liara:
 *   /usr/local/sbin/liara-video-xspf.php
 */

const VIDEO_ROOT = '/data/video';
const HTTP_BASE = 'http://192.168.2.221/video/';
const XSPF_URL = 'http://192.168.2.221/video.xspf';
const XSPF_FILE = '/usr/local/www/liara/video.xspf';
const LOCK_FILE = '/var/run/liara-video-xspf.lock';
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

function isSafeName(string $name): bool
{
    return preg_match('//u', $name) === 1
        && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\r\n]/', $name) !== 1;
}

function compareNames(string $left, string $right): int
{
    $comparison = strnatcasecmp($left, $right);
    return $comparison !== 0 ? $comparison : strcmp($left, $right);
}

function newTreeNode(): array
{
    return ['dirs' => [], 'files' => []];
}

function atomicWrite(string $path, string $contents): void
{
    $directory = dirname($path);

    if (!is_dir($directory)) {
        fail("Каталог для плейлиста не существует: {$directory}");
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

/**
 * Рекурсивно читает каталог.
 * Сохраняет все реальные папки, в том числе пустые.
 * В список воспроизведения попадают только поддерживаемые медиафайлы.
 */
function scanDirectory(string $directory, array $extensions, int &$skipped): array
{
    $node = newTreeNode();
    $names = @scandir($directory);

    if ($names === false) {
        fail("Не удалось прочитать каталог: {$directory}");
    }

    foreach ($names as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        if (!isSafeName($name)) {
            $skipped++;
            continue;
        }

        $fullPath = $directory . DIRECTORY_SEPARATOR . $name;

        // Символические ссылки не обходим, чтобы не выйти за пределы видеотеки.
        if (is_link($fullPath)) {
            continue;
        }

        if (is_dir($fullPath)) {
            $node['dirs'][$name] = scanDirectory($fullPath, $extensions, $skipped);
            continue;
        }

        if (!is_file($fullPath)) {
            continue;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!isset($extensions[$extension])) {
            continue;
        }

        $relative = substr($fullPath, strlen(VIDEO_ROOT) + 1);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        $node['files'][] = [
            'relative' => $relative,
            'name' => $name,
        ];
    }

    uksort($node['dirs'], 'compareNames');
    usort($node['files'], static fn(array $a, array $b): int => compareNames($a['name'], $b['name']));

    return $node;
}

function buildVlcNodeContents(array $node, array &$tracks, int $level): string
{
    $xml = '';
    $indent = str_repeat('  ', $level);

    // Сначала все папки — как в стандартном представлении Проводника.
    foreach ($node['dirs'] as $name => $child) {
        $xml .= $indent . '<vlc:node title="' . xmlEscape((string)$name) . '">' . "\n";
        $xml .= buildVlcNodeContents($child, $tracks, $level + 1);
        $xml .= $indent . "</vlc:node>\n";
    }

    // Затем файлы текущего каталога.
    foreach ($node['files'] as $file) {
        $tid = count($tracks);
        $tracks[] = $file;
        $xml .= $indent . '<vlc:item tid="' . $tid . '" />' . "\n";
    }

    return $xml;
}

function httpLocation(string $relative): string
{
    $parts = explode('/', $relative);
    $encoded = implode('/', array_map('rawurlencode', $parts));
    return HTTP_BASE . $encoded;
}

// Не допускаем параллельную генерацию.
$lock = @fopen(LOCK_FILE, 'c');

if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fail('Обновление плейлиста уже выполняется.');
}

if (!is_dir(VIDEO_ROOT) || !is_readable(VIDEO_ROOT)) {
    fail('Видеотека недоступна: ' . VIDEO_ROOT);
}

// Аудио- и видеоформаты, поддерживаемые VLC.
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

$skippedNames = 0;

try {
    $tree = scanDirectory(VIDEO_ROOT, $extensions, $skippedNames);
} catch (Throwable $e) {
    fail('Ошибка чтения видеотеки: ' . $e->getMessage());
}

$tracks = [];
$treeXml = '<vlc:node title="video">' . "\n";
$treeXml .= buildVlcNodeContents($tree, $tracks, 2);
$treeXml .= "  </vlc:node>\n";

$xspf = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$xspf .= '<playlist version="1"'
    . ' xmlns="http://xspf.org/ns/0/"'
    . ' xmlns:vlc="http://www.videolan.org/vlc/playlist/ns/0/">' . "\n";
$xspf .= "  <title>LIARA — Video</title>\n";
$xspf .= "  <creator>LIARA</creator>\n";
$xspf .= "  <trackList>\n";

foreach ($tracks as $tid => $track) {
    $xspf .= "    <track>\n";
    $xspf .= '      <location>' . xmlEscape(httpLocation($track['relative'])) . "</location>\n";
    $xspf .= '      <title>' . xmlEscape($track['name']) . "</title>\n";
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

$oldXspf = is_file(XSPF_FILE) ? @file_get_contents(XSPF_FILE) : false;

if ($oldXspf === false || $oldXspf !== $xspf) {
    atomicWrite(XSPF_FILE, $xspf);
    echo "XSPF-плейлист обновлён.\n";
} else {
    echo "Структура видеотеки не изменилась — плейлист оставлен без изменений.\n";
}

@chmod(XSPF_FILE, 0644);

echo 'Готово: ' . count($tracks) . " медиафайлов.\n";
echo 'Каталог: ' . VIDEO_ROOT . "\n";
echo 'XSPF: ' . XSPF_FILE . "\n";
echo 'HTTP: ' . XSPF_URL . "\n";

if ($skippedNames > 0) {
    echo 'Пропущено объектов с некорректными именами: ' . $skippedNames . "\n";
}
