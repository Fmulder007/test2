<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';require_once dirname(__DIR__).'/includes/messages.php';$user=chat_require_user();chat_json(['ok'=>true,'pinned'=>chat_get_pinned(chat_db(),$user)]);
