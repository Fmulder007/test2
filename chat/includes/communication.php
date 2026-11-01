<?php
declare(strict_types=1);

/**
 * Общая точка выбора протокола динамического взаимодействия chat1.
 *
 * 1 — классические multipart/form-data POST-запросы.
 * 2 — application/x-www-form-urlencoded POST-запросы и блочная передача файлов.
 * Новые протоколы должны получить клиентский адаптер и серверную обработку до
 * добавления своего номера в список поддерживаемых.
 */
function chat_communication_protocol(): int
{
    $raw = trim((string)chat_cfg('communication', 'protocol', '2'));
    if (!in_array($raw, ['1', '2'], true)) {
        error_log('chat1: неизвестный communication.protocol: ' . $raw);
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Ошибка настройки chat1: неизвестный протокол связи. В settings.cfg задайте [communication] protocol = 1 или 2.\n");
    }
    return (int)$raw;
}

function chat_validate_communication_protocol(): void
{
    chat_communication_protocol();
}

/** Проверяет, что тип тела POST соответствует выбранному протоколу. */
function chat_enforce_communication_protocol_request(): void
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') return;

    $protocol = chat_communication_protocol();
    $contentType = trim((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $valid = false;

    if ($protocol === 1) {
        $valid = (bool)preg_match('/\Amultipart\/form-data\s*;\s*boundary=(?:"[^"]+"|[^;\s]+)(?:\s*;|\s*\z)/i', $contentType);
    } elseif ($protocol === 2) {
        $valid = (bool)preg_match('/\Aapplication\/x-www-form-urlencoded(?:\s*;.*)?\z/i', $contentType);
    }

    if (!$valid) {
        chat_json([
            'ok' => false,
            'error' => 'Тип запроса не соответствует выбранному протоколу связи. Обновите страницу и повторите действие.'
        ], 415);
    }
}
