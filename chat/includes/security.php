<?php
declare(strict_types=1);

function chat_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
}
function chat_enforce_https(): void
{
    if (!chat_cfg_bool('app', 'https_required', true)) return;
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    if ($https) return;
    $base = (string)chat_cfg('app', 'base_url', '');
    $host = parse_url($base, PHP_URL_HOST);
    if (!$host) {
        http_response_code(400);
        exit('Заполните корректный HTTPS base_url в settings.cfg.');
    }
    $port = parse_url($base, PHP_URL_PORT);
    $hostPart = $host . ($port && $port !== 443 ? ':' . $port : '');
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: https://' . $hostPart . $uri, true, 302);
    exit;
}
