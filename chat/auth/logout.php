<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
chat_require_method('POST'); chat_verify_csrf();
$user = chat_current_user();
if ($user) chat_audit('logout',(int)$user['id'],(string)$user['email'],(int)$user['id'],(string)$user['email'],['result'=>'success']);
chat_logout_user();
chat_json(['ok'=>true,'message'=>'Вы вышли из чата.']);
