<?php
declare(strict_types=1);

function chat_send_mail(string $to, string $subject, string $body): bool
{
    $fromEmail = (string)chat_cfg('mail', 'from_email', 'chat@example.com');
    $fromName = (string)chat_cfg('mail', 'from_name', 'Web 1.0 Chat');
    if (!chat_email_valid($to) || !chat_email_valid($fromEmail)) return false;
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    $headers .= 'From: ' . $encodedName . ' <' . $fromEmail . ">\r\n";
    $headers .= 'X-Mailer: LightChat-PHP/1.0';
    $method = strtolower((string)chat_cfg('mail', 'method', 'php_mail'));
    if ($method === 'php_mail') return @mail($to, $encodedSubject, $body, $headers);
    if ($method !== 'smtp') return false;
    return chat_smtp_send($to, $encodedSubject, $body, $headers, $fromEmail);
}

function chat_smtp_send(string $to, string $subject, string $body, string $headers, string $fromEmail): bool
{
    $host = trim((string)chat_cfg('mail', 'smtp_host', ''));
    $port = chat_cfg_int('mail', 'smtp_port', 587);
    $encryption = strtolower((string)chat_cfg('mail', 'smtp_encryption', 'starttls'));
    $username = (string)chat_cfg('mail', 'smtp_username', '');
    $password = (string)chat_cfg('mail', 'smtp_password', '');
    $timeout = max(5, chat_cfg_int('mail', 'smtp_timeout_seconds', 15));
    if ($host === '') return false;
    $connectHost = ($encryption === 'ssl' ? 'ssl://' : '') . $host;
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client(($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$fp) { error_log('Chat SMTP connect error: ' . $errstr); return false; }
    stream_set_timeout($fp, $timeout);
    $read = static function () use ($fp): string {
        $all = '';
        while (($line = fgets($fp, 515)) !== false) {
            $all .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $all;
    };
    $cmd = static function (string $command, array $expected) use ($fp, $read): bool {
        fwrite($fp, $command . "\r\n");
        $response = $read();
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) { error_log('Chat SMTP error for command: ' . strtok($command, ' ') . ' (' . $code . ')'); return false; }
        return true;
    };
    try {
        $greet = $read();
        if ((int)substr($greet, 0, 3) !== 220) throw new RuntimeException('SMTP greeting failed');
        $hello = $_SERVER['SERVER_NAME'] ?? 'localhost';
        if (!$cmd('EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', (string)$hello), [250])) throw new RuntimeException('SMTP EHLO failed');
        if ($encryption === 'starttls') {
            if (!$cmd('STARTTLS', [220])) throw new RuntimeException('SMTP STARTTLS failed');
            $crypto = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) throw new RuntimeException('SMTP TLS failed');
            if (!$cmd('EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', (string)$hello), [250])) throw new RuntimeException('SMTP EHLO after TLS failed');
        }
        if ($username !== '') {
            if (!$cmd('AUTH LOGIN', [334])) throw new RuntimeException('SMTP AUTH failed');
            if (!$cmd(base64_encode($username), [334])) throw new RuntimeException('SMTP username rejected');
            if (!$cmd(base64_encode($password), [235])) throw new RuntimeException('SMTP password rejected');
        }
        if (!$cmd('MAIL FROM:<' . $fromEmail . '>', [250])) throw new RuntimeException('SMTP MAIL FROM failed');
        if (!$cmd('RCPT TO:<' . $to . '>', [250, 251])) throw new RuntimeException('SMTP RCPT TO failed');
        if (!$cmd('DATA', [354])) throw new RuntimeException('SMTP DATA failed');
        $data = 'To: <' . $to . ">\r\n" . 'Subject: ' . $subject . "\r\n" . $headers . "\r\n" . str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $body) . "\r\n.";
        fwrite($fp, $data . "\r\n");
        $end = $read();
        if ((int)substr($end, 0, 3) !== 250) throw new RuntimeException('SMTP message rejected');
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        error_log('Chat SMTP delivery failed: ' . $e->getMessage());
        fclose($fp);
        return false;
    }
}
