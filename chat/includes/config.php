<?php
declare(strict_types=1);

function chat_load_config(): array
{
    $path = dirname(__DIR__) . '/settings.cfg';
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(500);
        exit('Не найден settings.cfg. Проверьте ручную установку.');
    }
    $cfg = parse_ini_file($path, true, INI_SCANNER_TYPED);
    if (!is_array($cfg)) {
        http_response_code(500);
        exit('Ошибка разбора settings.cfg.');
    }
    return $cfg;
}

function chat_cfg(string $section, string $key, mixed $default = null): mixed
{
    global $CHAT_CONFIG;
    return $CHAT_CONFIG[$section][$key] ?? $default;
}

function chat_cfg_bool(string $section, string $key, bool $default = false): bool
{
    $value = chat_cfg($section, $key, $default);
    if (is_bool($value)) return $value;
    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
}

function chat_cfg_int(string $section, string $key, int $default): int
{
    $value = chat_cfg($section, $key, $default);
    return is_numeric($value) ? (int)$value : $default;
}
