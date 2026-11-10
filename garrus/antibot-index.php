<?php
/**
 * Единая точка входа глобального AntiBot.
 *
 * Этот файл работает в двух случаях:
 *  - автоматически подключается через auto_prepend_file перед PHP-скриптом;
 *  - открывается как контроллер CAPTCHA и аварийного восстановления.
 *
 * Генерация CAPTCHA, формат токена, проверка HMAC, структура БД и порог
 * страйков сохранены из существующей реализации AntiBot.
 */
 
 // Максимальное количество страйков до блокировки
if (!defined('AB_STRIKE_LIMIT')) {
    define('AB_STRIKE_LIMIT', 7);
}

// Обновлять токен, когда осталось меньше 12 часов
if (!defined('AB_TOKEN_REFRESH_REMAINING')) {
    define('AB_TOKEN_REFRESH_REMAINING', 43200);
}

function ab_safe_local_url($url, $fallback = '/')
{
    if (!is_string($url) || $url === '' || $url[0] !== '/') {
        return $fallback;
    }

    if (strncmp($url, '//', 2) === 0 || strpos($url, "\r") !== false || strpos($url, "\n") !== false || strpos($url, '\\') !== false) {
        return $fallback;
    }

    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return $fallback;
    }

    return $url;
}

function ab_request_path()
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
    return is_string($path) && $path !== '' ? $path : '/';
}

function ab_is_whitelisted_ip($ip)
{
    /*
     * Белый список хранится отдельно в white.lst рядом с index.php.
     * Поддерживаются адрес IPv4 (эквивалентен /32) и CIDR, например 192.0.2.0/24.
     * Пустые строки и комментарии, начинающиеся с #, пропускаются.
     */
    if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }

    $listFile = __DIR__ . DIRECTORY_SEPARATOR . 'white.lst';
    if (!is_file($listFile) || !is_readable($listFile)) {
        // При отсутствии списка не отключаем AntiBot: доступ не разрешается.
        return false;
    }

    $lines = file($listFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return false;
    }

    $clientLong = ip2long($ip);
    if ($clientLong === false) {
        return false;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // Допускаем комментарий справа: 192.0.2.0/24 # пример
        $commentPos = strpos($line, '#');
        if ($commentPos !== false) {
            $line = trim(substr($line, 0, $commentPos));
        }
        if ($line === '') {
            continue;
        }

        $parts = explode('/', $line);
        if (count($parts) > 2) {
            continue;
        }

        $network = trim($parts[0]);
        if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            continue;
        }

        $prefix = 32;
        if (isset($parts[1])) {
            $prefixText = trim($parts[1]);
            if ($prefixText === '' || !ctype_digit($prefixText)) {
                continue;
            }
            $prefix = (int)$prefixText;
            if ($prefix < 0 || $prefix > 32) {
                continue;
            }
        }

        $networkLong = ip2long($network);
        if ($networkLong === false) {
            continue;
        }

        // /0 — вся IPv4-сеть; для остальных маску рассчитываем по длине префикса.
        $mask = ($prefix === 0)
            ? 0
            : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);

        if (($clientLong & $mask) === ($networkLong & $mask)) {
            return true;
        }
    }

    return false;
}

function ab_parse_valid_token($ip, $cookie, $tokenSecret)
{
    if (!is_string($cookie) || $cookie === '') {
        return false;
    }

    $parts = explode('.', $cookie, 3);
    if (count($parts) !== 3) {
        return false;
    }

    [$tokenTimeText, $token, $signature] = $parts;

    if ($tokenTimeText === '' || !ctype_digit($tokenTimeText)) {
        return false;
    }

    $tokenTime = (int)$tokenTimeText;
    $now = time();

    if (
        $tokenTime <= 0 ||
        $tokenTime > $now ||
        $tokenTime < $now - 86400 ||
        preg_match('/^[a-f0-9]{64}$/D', $token) !== 1 ||
        preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1
    ) {
        return false;
    }

    $tokenData = $ip . '|' . $tokenTime . '|' . $token;
    $expected = hash_hmac('sha256', $tokenData, $tokenSecret);

    if (!hash_equals($expected, $signature)) {
        return false;
    }

    return [
        'time' => $tokenTime,
        'token' => $token,
    ];
}


function ab_is_valid_recovery_cookie($ip, $cookie, $recoverySecret)
{
    if (!is_string($cookie) || $cookie === '') {
        return false;
    }

    $parts = explode('.', $cookie, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return false;
    }

    $timestampText = $parts[0];
    if (!ctype_digit($timestampText)) {
        return false;
    }

    $timestamp = (int)$timestampText;
    $now = time();
    if ($timestamp <= 0 || $timestamp > $now || $timestamp < $now - 86400) {
        return false;
    }

    $expected = hash_hmac('sha256', $ip . '|' . $timestamp, $recoverySecret);
    return hash_equals($expected, $parts[1]);
}

function ab_issue_token_cookie($ip, $token, $tokenSecret, $lifetime = 86400)
{
    $timestamp = time();
    $tokenData = $ip . '|' . $timestamp . '|' . $token;
    $signature = hash_hmac('sha256', $tokenData, $tokenSecret);
    $cookieValue = $timestamp . '.' . $token . '.' . $signature;

    setcookie('ab_token', $cookieValue, [
        'expires' => $timestamp + $lifetime,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function ab_start_session()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function ab_db_connect()
{
    require_once __DIR__ . '/db.php';
    return $pdo;
}

function ab_ensure_antibot_ip_row(PDO $pdo, $ip)
{
    $stmt = $pdo->prepare(
        'INSERT INTO antibot (ip, strikes, blocked_until)
         VALUES (?, 0, 0)
         ON DUPLICATE KEY UPDATE ip = VALUES(ip)'
    );
    $stmt->execute([$ip]);
}

function ab_load_and_check_antibot_row(PDO $pdo, $ip)
{
    ab_ensure_antibot_ip_row($pdo, $ip);

    $stmt = $pdo->prepare(
        'SELECT strikes, blocked_until
         FROM antibot
         WHERE ip = ?'
    );
    $stmt->execute([$ip]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(500);
        exit('Ошибка AntiBot.');
    }

    $strikes = (int)$row['strikes'];
    $blockedUntil = (int)$row['blocked_until'];

    if ($blockedUntil > time()) {
        http_response_code(403);
        exit('Доступ временно заблокирован на 24 часа.');
    }

    if ($blockedUntil > 0 && $blockedUntil <= time()) {
        $stmt = $pdo->prepare(
            'UPDATE antibot
             SET strikes = 0,
                 blocked_until = 0
             WHERE ip = ?'
        );
        $stmt->execute([$ip]);
        $strikes = 0;
        $blockedUntil = 0;
    }

    return [$strikes, $blockedUntil];
}

function ab_current_return_url($isDirectAntibotRequest, $preferCurrentRequest = false)
{
    ab_start_session();

    /* При отображении CAPTCHA запоминаем текущую страницу заново.
       Это не позволяет фоновой сессии AJAX оставить старый адрес возврата. */
    if (!$preferCurrentRequest) {
        $existing = $_SESSION['return_url'] ?? null;
        if (is_string($existing) && $existing !== '') {
            $safe = ab_safe_local_url($existing, '/');
            if ($safe !== '/antibot/' && $safe !== '/antibot' && strpos($safe, '/antibot/') !== 0) {
                return $safe;
            }
        }
    }

    if ($isDirectAntibotRequest) {
        $candidate = $_GET['return'] ?? '/';

        /*
         * Совместимость со старым правилом .htaccess:
         *   /antibot/?return=/forum/viewtopic.php&t=9
         * Apache добавляет исходную query string ПОСЛЕ параметра return.
         * PHP тогда видит return=/forum/viewtopic.php отдельно от t=9.
         * Восстанавливаем исходную строку запроса из остальных GET-параметров,
         * чтобы phpBB и другие приложения получили исходные параметры.
         */
        if (is_string($candidate) && strpos($candidate, '?') === false) {
            $originalQueryParams = $_GET;
            unset($originalQueryParams['return'], $originalQueryParams['ab_page']);

            if ($originalQueryParams !== []) {
                $originalQuery = http_build_query(
                    $originalQueryParams,
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );

                if ($originalQuery !== '') {
                    $candidate .= '?' . $originalQuery;
                }
            }
        }
    } else {
        $candidate = $_SERVER['REQUEST_URI'] ?? '/';
    }

    $safe = ab_safe_local_url($candidate, '/');
    if ($safe === '/antibot/' || $safe === '/antibot' || strpos($safe, '/antibot/') === 0) {
        $safe = '/';
    }

    $_SESSION['return_url'] = $safe;
    return $safe;
}

/*
 * Определяет фоновые запросы (AJAX, fetch, API), которые не являются
 * навигацией пользователя на страницу. Для них нельзя показывать CAPTCHA
 * в теле ответа и нельзя записывать их URL как адрес возврата.
 */
function ab_is_non_navigation_request()
{
    $mode = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '')));
    $dest = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')));

    if ($mode !== '' || $dest !== '') {
        if ($mode !== '' && $mode !== 'navigate') {
            return true;
        }
        if ($dest !== '' && $dest !== 'document') {
            return true;
        }
        return false;
    }

    $requestedWith = strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    if ($requestedWith === 'xmlhttprequest') {
        return true;
    }

    $path = ab_request_path();
    if (
        $path === '/wp-admin/admin-ajax.php' ||
        strpos($path, '/wp-json/') === 0
    ) {
        return true;
    }

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    if ($accept === '' || strpos($accept, 'text/html') === false) {
        return true;
    }

    return false;
}

function ab_render_captcha($ip, $isDirectAntibotRequest, $tokenSecret)
{
    ab_start_session();
    $returnUrl = ab_current_return_url($isDirectAntibotRequest, true);

    $pdo = ab_db_connect();
    [$strikes] = ab_load_and_check_antibot_row($pdo, $ip);

    /* Сохраняем существующее поведение: показ CAPTCHA добавляет страйк. */
    $stmt = $pdo->prepare(
        'UPDATE antibot
         SET strikes = strikes + 1
         WHERE ip = ?'
    );
    $stmt->execute([$ip]);

    $stmt = $pdo->prepare(
        'SELECT strikes
         FROM antibot
         WHERE ip = ?'
    );
    $stmt->execute([$ip]);
    $strikes = (int)$stmt->fetchColumn();

    /* Сохраняем существующий порог: 7 страйков -> блокировка на 24 часа. */
    if ($strikes >= AB_STRIKE_LIMIT) {
        $blockedUntil = time() + 86400;
        $stmt = $pdo->prepare(
            'UPDATE antibot
             SET blocked_until = ?
             WHERE ip = ?'
        );
        $stmt->execute([$blockedUntil, $ip]);

        http_response_code(403);
        exit('Доступ временно заблокирован на 24 часа.');
    }

    $captchaError = $_SESSION['ab_captcha_error'] ?? null;
    unset($_SESSION['ab_captcha_error']);

    $error = is_array($captchaError);
    $left = $error ? (int)($captchaError['left'] ?? 0) : 0;

    $chars = 'abcefghijkmnrstuvwxyz23457';
    if (
        !isset($_SESSION['captcha_code']) ||
        !is_string($_SESSION['captcha_code']) ||
        $_SESSION['captcha_code'] === ''
    ) {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $_SESSION['captcha_code'] = $code;
    } else {
        $code = $_SESSION['captcha_code'];
    }

    require_once __DIR__ . '/captcha.php';
    $captchaPng = captcha_generate($code, $chars);

    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $formAction = ab_safe_local_url($requestUri, '/');
    $formActionHtml = htmlspecialchars($formAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Проверка</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body {
    font-family: Arial, sans-serif;
    text-align: center;
    padding-top: 80px;
    min-height: 110vh;
    box-sizing: border-box;
}
.box {
    display: inline-block;
    padding: 25px;
    border: 1px solid #ccc;
    border-radius: 8px;
}
input {
    font-size: 20px;
    width: 160px;
    padding: 7px;
    text-align: center;
}
button {
    font-size: 16px;
    padding: 8px 20px;
    margin-top: 10px;
}
</style>
</head>
<body>
<div class="box">
    <h2>Проверка</h2>
<?php if ($error): ?>
    <p style="color:red;font-weight:bold;">Неверный ввод</p>
    <p>Осталось попыток: <b><?php echo $left; ?></b></p>
<?php endif; ?>
    <p>Введите код:</p>
    <div style="display:inline-block;margin-bottom:15px;">
        <img src="data:image/png;base64,<?php echo $captchaPng; ?>" width="270" height="80" alt="CAPTCHA" style="display:block;border-radius:10px;">
    </div>
    <form method="post" action="<?php echo $formActionHtml; ?>">
        <input type="hidden" name="ab_action" value="captcha">
        <br>
        <input type="text" name="code" maxlength="6" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" autofocus>
        <br>
        <button type="submit">Проверить</button>
    </form>
</div>
</body>
</html>
<?php
    exit;
}

function ab_process_captcha_post($ip, $tokenSecret)
{
    ab_start_session();
    $pdo = ab_db_connect();
    ab_load_and_check_antibot_row($pdo, $ip);

    $code = $_POST['code'] ?? '';
    if (!is_string($code)) {
        $code = '';
    }
    $correct = $_SESSION['captcha_code'] ?? '';

    if (!is_string($correct) || $correct === '') {
        unset($_SESSION['captcha_code']);
        $returnUrl = ab_current_return_url(false);
        session_write_close();
        header('Location: ' . $returnUrl, true, 302);
        exit;
    }

    unset($_SESSION['captcha_code']);

    if (hash_equals($correct, $code)) {
        $stmt = $pdo->prepare('DELETE FROM antibot WHERE ip = ?');
        $stmt->execute([$ip]);

        $token = bin2hex(random_bytes(32));
        ab_issue_token_cookie($ip, $token, $tokenSecret, 86400);

        $returnUrl = ab_current_return_url(false);
        unset($_SESSION['return_url'], $_SESSION['ab_captcha_error']);
        session_write_close();

        header('Location: ' . $returnUrl, true, 302);
        exit;
    }

    /* Сохраняем увеличение страйка, которое ранее выполнялось в verify.php. */
    $stmt = $pdo->prepare(
        'UPDATE antibot
         SET strikes = strikes + 1
         WHERE ip = ?'
    );
    $stmt->execute([$ip]);

    $stmt = $pdo->prepare('SELECT strikes FROM antibot WHERE ip = ?');
    $stmt->execute([$ip]);
    $strikes = (int)$stmt->fetchColumn();

    if ($strikes >= AB_STRIKE_LIMIT) {
        $blockedUntil = time() + 86400;
        $stmt = $pdo->prepare(
            'UPDATE antibot
             SET blocked_until = ?
             WHERE ip = ?'
        );
        $stmt->execute([$blockedUntil, $ip]);

        session_write_close();
        http_response_code(403);
        exit('Доступ временно заблокирован на 24 часа.');
    }

    /* Сохраняем прежнюю формулу и учёт показа CAPTCHA после перенаправления на GET. */
    $left = 2 - intdiv($strikes - 1, 3);
    $_SESSION['ab_captcha_error'] = ['left' => $left];
    $returnUrl = ab_current_return_url(false);
    session_write_close();

    header('Location: ' . $returnUrl, true, 302);
    exit;
}

function ab_render_recovery($ip, $tokenSecret)
{
    $recoverySecret = 'cекрет';
    $maxAttempts = 5;
    $lockTime = 3600;
    $tokenLifetime = 86400;

    ab_start_session();
    $pdo = ab_db_connect();

    $stmt = $pdo->prepare(
        'INSERT INTO recovery (ip, attempts, blocked_until)
         VALUES (?, 0, 0)
         ON DUPLICATE KEY UPDATE ip = VALUES(ip)'
    );
    $stmt->execute([$ip]);

    $stmt = $pdo->prepare(
        'SELECT attempts, blocked_until
         FROM recovery
         WHERE ip = ?'
    );
    $stmt->execute([$ip]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        exit;
    }

    if ((int)$row['blocked_until'] > time()) {
        http_response_code(404);
        exit;
    }

    if ((int)$row['blocked_until'] > 0 && (int)$row['blocked_until'] <= time()) {
        $stmt = $pdo->prepare(
            'UPDATE recovery
             SET attempts = 0,
                 blocked_until = 0
             WHERE ip = ?'
        );
        $stmt->execute([$ip]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ab_action'] ?? '') === 'recovery') {
        $secret = $_POST['secret'] ?? '';
        if (!is_string($secret) || !hash_equals($recoverySecret, $secret)) {
            $stmt = $pdo->prepare(
                'UPDATE recovery
                 SET attempts = attempts + 1
                 WHERE ip = ?'
            );
            $stmt->execute([$ip]);

            $stmt = $pdo->prepare('SELECT attempts FROM recovery WHERE ip = ?');
            $stmt->execute([$ip]);
            $attempts = (int)$stmt->fetchColumn();

            if ($attempts >= $maxAttempts) {
                $stmt = $pdo->prepare(
                    'UPDATE recovery
                     SET blocked_until = ?
                     WHERE ip = ?'
                );
                $stmt->execute([time() + $lockTime, $ip]);
            }

            session_write_close();
            http_response_code(404);
            exit;
        }

        $timestamp = time();
        $token = bin2hex(random_bytes(32));
        ab_issue_token_cookie($ip, $token, $tokenSecret, $tokenLifetime);

        $stmt = $pdo->prepare('DELETE FROM recovery WHERE ip = ?');
        $stmt->execute([$ip]);

        session_write_close();
        header('Location: /', true, 302);
        exit;
    }

    $requestUri = $_SERVER['REQUEST_URI'] ?? '/antibot/recovery';
    $formAction = ab_safe_local_url($requestUri, '/antibot/recovery');
    $formActionHtml = htmlspecialchars($formAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Аварийный доступ</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: Arial, sans-serif; text-align: center; padding-top: 80px; }
.box { display: inline-block; padding: 25px; border: 1px solid #ccc; border-radius: 8px; }
input { font-size: 20px; width: 260px; padding: 7px; text-align: center; }
button { font-size: 16px; padding: 8px 20px; margin-top: 10px; }
</style>
</head>
<body>
<div class="box">
    <h2>Аварийный доступ</h2>
    <form method="post" action="<?php echo $formActionHtml; ?>">
        <input type="hidden" name="ab_action" value="recovery">
        <input type="password" name="secret" autocomplete="off" autofocus>
        <br>
        <button type="submit">OK</button>
    </form>
</div>
</body>
</html>
<?php
    exit;
}

function ab_global_antibot_run()
{
    $tokenSecret = 'секрет2';
    $recoverySecret = 'cекрет';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $requestPath = ab_request_path();
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $selfPath = realpath(__FILE__);
    $targetScript = isset($_SERVER['SCRIPT_FILENAME']) ? realpath((string)$_SERVER['SCRIPT_FILENAME']) : false;
    $isDirectAntibotRequest = ($selfPath !== false && $targetScript !== false && $selfPath === $targetScript);
    $isRecoveryPage = $isDirectAntibotRequest
        && ($requestPath === '/antibot/recovery' || $requestPath === '/antibot/recovery/')
        && (($_GET['ab_page'] ?? '') === 'recovery');

    /* Старые публичные PHP-обработчики больше не являются точками входа. */
    if (!$isDirectAntibotRequest && strpos($requestPath, '/antibot/') === 0) {
        http_response_code(404);
        exit;
    }

    /* Аварийное восстановление использует этот же файл, сохраняя прежнюю логику БД и попыток. */
    if ($isRecoveryPage) {
        ab_render_recovery($ip, $tokenSecret);
    }

    /* Сохраняем прежнюю обработку подписанной аварийной cookie. */
    $recoveryCookie = $_COOKIE['ab_recovery'] ?? '';
    $recoveryCookieValid = ab_is_valid_recovery_cookie($ip, $recoveryCookie, $recoverySecret);
    if ($recoveryCookieValid) {
        if ($isDirectAntibotRequest) {
            header('Location: /', true, 302);
            exit;
        }
        return;
    }

    /* Сохраняем обход проверки токена для IP из белого списка. */
    if (ab_is_whitelisted_ip($ip) && !$isDirectAntibotRequest) {
        return;
    }

    /* Ответ формы CAPTCHA обрабатывается до запуска скрипта приложения. */
    if ($requestMethod === 'POST' && ($_POST['ab_action'] ?? '') === 'captcha') {
        ab_process_captcha_post($ip, $tokenSecret);
    }

    /* При прямом открытии /antibot/ с действительным токеном возвращаемся к сохранённому адресу. */
    $tokenCookie = $_COOKIE['ab_token'] ?? '';
    $validToken = ab_parse_valid_token($ip, $tokenCookie, $tokenSecret);

    if ($validToken !== false) {
        /* Продлеваем токен ещё на 24 часа при активности PHP-запросов. */
        // Время истечения текущего токена
		$expiresAt = $validToken['time'] + 86400;

		// Сколько секунд осталось до истечения
		$remaining = $expiresAt - time();

		// Продлеваем только если осталось МЕНЬШЕ 12 часов
		if ($remaining < AB_TOKEN_REFRESH_REMAINING) {
			ab_issue_token_cookie(
			$ip,
			$validToken['token'],
			$tokenSecret,
			86400
		);
	}

        if ($isDirectAntibotRequest) {
            ab_start_session();
            $returnUrl = ab_current_return_url(true);
            unset($_SESSION['return_url'], $_SESSION['ab_captcha_error']);
            session_write_close();
            header('Location: ' . $returnUrl, true, 302);
            exit;
        }

        return;
    }

    /*
     * Без токена не выдаём CAPTCHA в ответ на фоновые AJAX/API-запросы.
     * Иначе их URL может попасть в сессию как адрес возврата, а HTML CAPTCHA
     * ломает ожидаемый формат ответа таких запросов (например, WordPress AJAX).
     * Страйки и CAPTCHA продолжают работать для настоящих переходов на страницы.
     */
    if (!$isDirectAntibotRequest && ab_is_non_navigation_request()) {
        header('Cache-Control: no-store, private');
        http_response_code(403);
        exit('Для выполнения фонового запроса требуется действительный токен AntiBot. Откройте страницу сайта и пройдите проверку.');
    }

    /* Нет действительного токена: показываем CAPTCHA в ответ на запрос страницы. */
    ab_render_captcha($ip, $isDirectAntibotRequest, $tokenSecret);
}

ab_global_antibot_run();
