<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

chat_require_method('GET');
chat_require_user();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$root = realpath(dirname(__DIR__));
if ($root === false) chat_json(['ok'=>false,'error'=>'Не удалось определить каталог чата.'],500);

function chat_smiles_resolve_dir(string $root, string $relative): ?string
{
    $relative = trim(str_replace('\\', '/', $relative));
    if ($relative === '' || str_starts_with($relative, '/') || preg_match('#(^|/)\.\.?(/|$)#', $relative)) return null;
    $candidate = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($candidate === false || !is_dir($candidate) || !str_starts_with($candidate, $prefix)) return null;
    return $candidate;
}

function chat_smiles_read_definitions(string $dir): array
{
    $path = $dir . DIRECTORY_SEPARATOR . '_define.ini';
    if (!is_file($path) || !is_readable($path)) throw new RuntimeException('Не найден доступный _define.ini в каталоге '.basename($dir).'.');
    $content = file_get_contents($path);
    if (!is_string($content)) throw new RuntimeException('Не удалось прочитать _define.ini в каталоге '.basename($dir).'.');
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
    $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
    $rows = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//') || preg_match('/^;\s+/', $line)) continue;
        $codes = [];
        foreach (explode(',', $line) as $code) {
            $code = trim($code);
            if ($code === '' || strlen($code) > 100 || preg_match('/[\x00-\x1F\x7F]/', $code)) continue;
            if (!in_array($code, $codes, true)) $codes[] = $code;
        }
        if ($codes) $rows[] = $codes;
    }
    return $rows;
}

function chat_smiles_list_files(string $dir): array
{
    $allowed = ['gif','png','webp','jpg','jpeg'];
    $prefix = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $files = [];
    foreach (glob($prefix . '*') ?: [] as $path) {
        if (!is_file($path)) continue;
        $real = realpath($path);
        if ($real === false || !str_starts_with($real, $prefix)) continue;
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;
        $files[] = ['path'=>$real,'name'=>basename($real)];
    }
    usort($files, static function(array $a, array $b): int {
        $cmp = strcasecmp($a['name'], $b['name']);
        return $cmp !== 0 ? $cmp : strcmp($a['name'], $b['name']);
    });
    return $files;
}

function chat_smiles_public_url(string $root, string $file): string
{
    $relative = substr($file, strlen($root) + 1);
    $parts = explode(DIRECTORY_SEPARATOR, $relative);
    $encoded = implode('/', array_map('rawurlencode', $parts));
    return chat_base_url($encoded);
}

try {
    $lightRelative = (string)chat_cfg('smiles', 'light_dir', 'assets/smiles/light');
    $darkRelative = (string)chat_cfg('smiles', 'dark_dir', 'assets/smiles/dark');
    $lightDir = chat_smiles_resolve_dir($root, $lightRelative);
    $darkDir = chat_smiles_resolve_dir($root, $darkRelative);
    if (!$lightDir) chat_json(['ok'=>false,'error'=>'Не найден каталог светлых смайлов. Проверьте [smiles] light_dir в settings.cfg.'],500);
    if (!$darkDir) chat_json(['ok'=>false,'error'=>'Не найден каталог тёмных смайлов. Проверьте [smiles] dark_dir в settings.cfg.'],500);

    $lightRows = chat_smiles_read_definitions($lightDir);
    $darkRows = chat_smiles_read_definitions($darkDir);
    $lightFiles = chat_smiles_list_files($lightDir);
    $darkFiles = chat_smiles_list_files($darkDir);
    if (!$lightRows) chat_json(['ok'=>false,'error'=>'В светлом _define.ini не найдены описания смайлов.'],500);
    if (!$darkRows) chat_json(['ok'=>false,'error'=>'В тёмном _define.ini не найдены описания смайлов.'],500);

    $max = min(count($lightRows), count($darkRows), count($lightFiles), count($darkFiles));
    $warnings = [];
    if (count($lightRows) !== count($lightFiles)) $warnings[] = 'Светлая тема: строк в _define.ini '.count($lightRows).', изображений '.count($lightFiles).'. Проверьте соответствие порядка файлов.';
    if (count($darkRows) !== count($darkFiles)) $warnings[] = 'Тёмная тема: строк в _define.ini '.count($darkRows).', изображений '.count($darkFiles).'. Проверьте соответствие порядка файлов.';
    if (count($lightRows) !== count($darkRows)) $warnings[] = 'Количество строк в светлом и тёмном _define.ini различается.';
    for ($i = 0; $i < min(count($lightRows), count($darkRows)); $i++) {
        if ($lightRows[$i] !== $darkRows[$i]) {
            $warnings[] = 'Определения светлой и тёмной темы различаются начиная со строки '.($i + 1).'. Для кодов используется светлый _define.ini.';
            break;
        }
    }
    $items = [];
    for ($i = 0; $i < $max; $i++) {
        $items[] = [
            'index' => $i,
            'codes' => $lightRows[$i],
            'light_url' => chat_smiles_public_url($root, $lightFiles[$i]['path']),
            'dark_url' => chat_smiles_public_url($root, $darkFiles[$i]['path']),
        ];
    }
    $columns = max(2, min(3, chat_cfg_int('smiles', 'columns', 3)));
    $imageSize = max(12, min(48, chat_cfg_int('smiles', 'image_size_px', 24)));
    chat_json(['ok'=>true,'items'=>$items,'columns'=>$columns,'image_size_px'=>$imageSize,'warning'=>implode(' ', array_unique($warnings))]);
} catch (Throwable $e) {
    error_log('Light Chat smiles catalog error: '.$e->getMessage());
    chat_json(['ok'=>false,'error'=>'Не удалось разобрать набор смайлов: '.$e->getMessage()],500);
}
