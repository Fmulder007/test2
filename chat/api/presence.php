<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
chat_require_method('POST');
chat_verify_csrf();
$user=chat_require_user();
$pdo=chat_db();
$stmt=$pdo->prepare('UPDATE users SET last_seen_at_utc=? WHERE id=?');
$stmt->execute([chat_now_utc(),(int)$user['id']]);
chat_json(['ok'=>true,'server_time'=>chat_now_utc()]);
