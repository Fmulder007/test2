<?php
declare(strict_types=1);

function chat_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $host = (string)chat_cfg('database', 'host', 'localhost');
    $port = (int)chat_cfg('database', 'port', 3306);
    $name = (string)chat_cfg('database', 'name', '');
    $user = (string)chat_cfg('database', 'user', '');
    $pass = (string)chat_cfg('database', 'password', '');
    $charset = (string)chat_cfg('database', 'charset', 'utf8mb4');
    if ($name === '' || $user === '' || $name === 'CHANGE_ME' || $user === 'CHANGE_ME') {
        throw new RuntimeException('Заполните параметры базы данных в settings.cfg.');
    }
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        Pdo\Mysql::ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
