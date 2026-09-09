<?php
declare(strict_types=1);

/**
 * Buddies 専用メール基盤。
 * 送信処理・暗号化・本文はBuddies内で完結させる。
 * 設定はBuddies/.envまたはサーバー環境変数だけを使用する。
 */

function buddies_mail_env(string $key, ?string $default = null): ?string {
    $serverValue = getenv($key);
    if ($serverValue !== false && trim((string)$serverValue) !== '') return trim((string)$serverValue);
    static $env = null;
    if ($env === null) {
        $env = [];
        $envFile = __DIR__ . '/.env';
        if (is_readable($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$name, $raw] = explode('=', $line, 2);
                $name = trim($name);
                $raw = trim($raw);
                $value = trim($raw, " \t\n\r\0\x0B\"'");
                if ($name !== '' && $value !== '' && !array_key_exists($name, $env)) {
                    $env[$name] = $value;
                }
            }
        }
    }
    if (array_key_exists($key, $env) && trim((string)$env[$key]) !== '') return (string)$env[$key];
    return $default;
}

function buddies_mail_secret(): string {
    $secret = trim((string)buddies_mail_env('BUDDIES_MAIL_TOKEN_SECRET', ''));
    if (strlen($secret) < 32) {
        throw new RuntimeException('BUDDIES_MAIL_TOKEN_SECRET は32文字以上で設定してください。');
    }
    return hash('sha256', $secret . '|buddies-email', true);
}

function buddies_mail_hash(string $value): string {
    return hash_hmac('sha256', $value, buddies_mail_secret());
}

function buddies_mail_encrypt(string $plaintext): string {
    $key = hash('sha256', buddies_mail_secret() . '|data', true);
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ciphertext === false) throw new RuntimeException('メール情報を暗号化できませんでした。');
    return base64_encode(json_encode([
        'v' => 1,
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'data' => base64_encode($ciphertext),
    ], JSON_UNESCAPED_SLASHES));
}

function buddies_mail_decrypt(?string $encoded): ?string {
    if (!$encoded) return null;
    $payload = json_decode(base64_decode($encoded, true) ?: '', true);
    if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1) return null;
    $iv = base64_decode((string)($payload['iv'] ?? ''), true);
    $tag = base64_decode((string)($payload['tag'] ?? ''), true);
    $data = base64_decode((string)($payload['data'] ?? ''), true);
    if ($iv === false || $tag === false || $data === false) return null;
    $key = hash('sha256', buddies_mail_secret() . '|data', true);
    $plain = openssl_decrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '');
    return $plain === false ? null : $plain;
}

function buddies_mail_enabled(): bool {
    $value = strtolower(trim((string)buddies_mail_env('BUDDIES_MAIL_ENABLED', '1')));
    return !in_array($value, ['0', 'false', 'off', 'no'], true);
}

function buddies_mail_from(): string {
    return trim((string)buddies_mail_env('BUDDIES_MAIL_FROM', ''));
}

function buddies_mail_from_name(): string {
    return trim((string)(buddies_mail_env('BUDDIES_MAIL_FROM_NAME', 'Buddies') ?: 'Buddies'));
}

function buddies_mail_subject_prefix(): string {
    return trim((string)(buddies_mail_env('BUDDIES_MAIL_SUBJECT_PREFIX', '[Buddies]') ?: '[Buddies]'));
}

function buddies_mail_reply_to(): string {
    return trim((string)buddies_mail_env('BUDDIES_MAIL_REPLY_TO', ''));
}

function buddies_mail_driver(): string {
    $driver = strtolower(trim((string)buddies_mail_env('BUDDIES_MAIL_DRIVER', '')));
    if (in_array($driver, ['smtp', 'mail'], true)) return $driver;
    return trim((string)buddies_mail_env('BUDDIES_SMTP_HOST', '')) !== '' ? 'smtp' : 'mail';
}

/**
 * 直近の送信失敗理由を、秘密値を含まない内部コードで保持する。
 * APIはこのコードを利用して、単なる「接続失敗」ではなく対処可能な案内を返す。
 */
function buddies_mail_set_last_error(?string $code): void {
    $GLOBALS['buddies_mail_last_error_code'] = $code;
}

function buddies_mail_last_error_code(): ?string {
    $code = $GLOBALS['buddies_mail_last_error_code'] ?? null;
    return is_string($code) && $code !== '' ? $code : null;
}

function buddies_mail_last_error_message(): string {
    return match (buddies_mail_last_error_code()) {
        'configuration' => 'メール設定が不足しています。',
        'recipient' => '宛先メールアドレスが正しくありません。',
        'connection' => 'SMTPサーバーに接続できませんでした。',
        'tls' => 'SMTPサーバーとの暗号化接続に失敗しました。',
        'authentication' => 'SMTP認証に失敗しました。Gmailのアプリパスワードを確認してください。',
        'sender' => 'SMTPサーバーが送信元アドレスを受け付けませんでした。',
        'delivery' => 'SMTPサーバーが宛先またはメール本文を受け付けませんでした。',
        'php_mail' => 'サーバーのメール送信機能で送信できませんでした。',
        default => 'メール送信処理に失敗しました。',
    };
}

function buddies_mail_safe_log_detail(string $detail): string {
    $detail = preg_replace('/[\w.!#$%&\'*+\/=?^`{|}~-]+@[\w.-]+/u', '<email>', $detail) ?? $detail;
    return substr(trim(preg_replace('/\s+/', ' ', $detail) ?? $detail), 0, 300);
}

final class BuddiesMailTransportException extends RuntimeException {
    public string $stage;

    public function __construct(string $stage, string $detail) {
        $this->stage = $stage;
        parent::__construct($detail);
    }
}

/**
 * 送信前に、画面へは出さない安全な設定診断を行う。
 * パスワードや秘密値そのものは、戻り値・ログのどちらにも含めない。
 */
function buddies_mail_configuration_error(): ?string {
    if (!buddies_mail_enabled()) return 'メール送信が無効です。';
    if (!filter_var(buddies_mail_from(), FILTER_VALIDATE_EMAIL)) return '送信元メールアドレスが未設定です。';
    try {
        buddies_mail_secret();
    } catch (Throwable $e) {
        return 'メール認証用の秘密値が未設定です。';
    }
    if (buddies_mail_driver() === 'smtp') {
        $missing = [];
        if (trim((string)buddies_mail_env('BUDDIES_SMTP_HOST', '')) === '') $missing[] = 'SMTPホスト';
        if (trim((string)buddies_mail_env('BUDDIES_SMTP_USER', '')) === '') $missing[] = 'SMTPユーザー';
        if ((string)buddies_mail_env('BUDDIES_SMTP_PASS', '') === '') $missing[] = 'SMTPパスワード';
        if ($missing) return implode('・', $missing) . 'が未設定です。';
    }
    return null;
}

function buddies_mail_header_encode(string $value): string {
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function buddies_mail_address(string $email, string $name = ''): string {
    $email = trim(str_replace(["\r", "\n"], '', $email));
    $name = trim(str_replace(["\r", "\n"], '', $name));
    return $name === '' ? $email : buddies_mail_header_encode($name) . ' <' . $email . '>';
}

function buddies_mail_encoded_body(string $body): string {
    return chunk_split(base64_encode($body), 76, "\r\n");
}

function buddies_mail_mime(string $to, string $subject, string $text): string {
    $from = buddies_mail_from();
    $headers = [
        'Date: ' . date('r'),
        'From: ' . buddies_mail_address($from, buddies_mail_from_name()),
        'To: ' . $to,
        'Subject: ' . buddies_mail_header_encode($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    $replyTo = buddies_mail_reply_to();
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: ' . $replyTo;
    return implode("\r\n", $headers) . "\r\n\r\n" . buddies_mail_encoded_body($text) . "\r\n";
}

function buddies_mail_send(array|string $to, string $subject, string $text): bool {
    buddies_mail_set_last_error(null);
    $configurationError = buddies_mail_configuration_error();
    if ($configurationError !== null) {
        buddies_mail_set_last_error('configuration');
        error_log('[buddies] mail configuration error: ' . $configurationError);
        return false;
    }
    $from = buddies_mail_from();
    $recipients = is_array($to) ? $to : preg_split('/[,;]/', $to);
    $recipients = array_values(array_filter(
        array_map('trim', $recipients ?: []),
        static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
    ));
    if (!$recipients) {
        buddies_mail_set_last_error('recipient');
        error_log('[buddies] mail send skipped: no valid recipient');
        return false;
    }
    return buddies_mail_driver() === 'smtp'
        ? buddies_mail_smtp_send($recipients, $subject, $text)
        : buddies_mail_php_send($recipients, $subject, $text);
}

function buddies_mail_php_send(array $recipients, string $subject, string $text): bool {
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'From: ' . buddies_mail_address(buddies_mail_from(), buddies_mail_from_name()),
    ];
    $replyTo = buddies_mail_reply_to();
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: ' . $replyTo;
    $params = '';
    $returnPath = trim((string)buddies_mail_env('BUDDIES_MAIL_RETURN_PATH', ''));
    if (filter_var($returnPath, FILTER_VALIDATE_EMAIL)) $params = '-f' . $returnPath;
    $to = implode(', ', $recipients);
    $encodedSubject = buddies_mail_header_encode($subject);
    $encodedBody = buddies_mail_encoded_body($text);
    $sent = $params !== ''
        ? @mail($to, $encodedSubject, $encodedBody, implode("\r\n", $headers), $params)
        : @mail($to, $encodedSubject, $encodedBody, implode("\r\n", $headers));
    if (!$sent) {
        buddies_mail_set_last_error('php_mail');
        error_log('[buddies] PHP mail() returned false');
    }
    return $sent;
}

function buddies_mail_smtp_read($fp): string {
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) break;
        $data .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    return $data;
}

function buddies_mail_smtp_expect($fp, array $codes, string $stage = 'delivery'): void {
    $response = buddies_mail_smtp_read($fp);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $codes, true)) {
        $detail = trim(preg_replace('/\s+/', ' ', $response));
        throw new BuddiesMailTransportException($stage, 'SMTP response ' . $code . ': ' . substr($detail, 0, 240));
    }
}

function buddies_mail_smtp_command($fp, string $command, array $codes, string $stage = 'delivery'): void {
    if (fwrite($fp, $command . "\r\n") === false) {
        throw new BuddiesMailTransportException($stage, 'SMTP command write failed');
    }
    buddies_mail_smtp_expect($fp, $codes, $stage);
}

function buddies_mail_smtp_send_once(array $recipients, string $subject, string $text, string $host, int $port, string $secure, string $user, string $pass): void {
    $remote = $secure === 'ssl' ? 'ssl://' . $host : $host;
    $errno = 0; $errstr = '';
    $context = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => $host,
        'SNI_enabled' => true,
    ]]);
    $fp = @stream_socket_client($remote . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$fp) {
        throw new BuddiesMailTransportException('connection', 'SMTP connection failed: ' . $errno . ' ' . substr($errstr, 0, 240));
    }
    stream_set_timeout($fp, 20);
    try {
        buddies_mail_smtp_expect($fp, [220], 'connection');
        $serverName = preg_replace('/[^a-zA-Z0-9.\-]/', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
        buddies_mail_smtp_command($fp, 'EHLO ' . $serverName, [250], 'connection');
        if ($secure === 'tls') {
            buddies_mail_smtp_command($fp, 'STARTTLS', [220], 'tls');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new BuddiesMailTransportException('tls', 'SMTP TLS negotiation failed');
            }
            buddies_mail_smtp_command($fp, 'EHLO ' . $serverName, [250], 'tls');
        }
        buddies_mail_smtp_command($fp, 'AUTH LOGIN', [334], 'authentication');
        buddies_mail_smtp_command($fp, base64_encode($user), [334], 'authentication');
        buddies_mail_smtp_command($fp, base64_encode($pass), [235], 'authentication');
        $from = buddies_mail_from();
        buddies_mail_smtp_command($fp, 'MAIL FROM:<' . $from . '>', [250], 'sender');
        foreach ($recipients as $recipient) buddies_mail_smtp_command($fp, 'RCPT TO:<' . $recipient . '>', [250, 251], 'delivery');
        buddies_mail_smtp_command($fp, 'DATA', [354], 'delivery');
        $message = buddies_mail_mime(implode(', ', $recipients), $subject, $text);
        if (fwrite($fp, preg_replace('/^\./m', '..', $message) . "\r\n.\r\n") === false) {
            throw new BuddiesMailTransportException('delivery', 'SMTP message write failed');
        }
        buddies_mail_smtp_expect($fp, [250], 'delivery');
        buddies_mail_smtp_command($fp, 'QUIT', [221], 'delivery');
        fclose($fp);
        return;
    } catch (Throwable $e) {
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        throw $e;
    }
}

function buddies_mail_smtp_send(array $recipients, string $subject, string $text): bool {
    $host = trim((string)buddies_mail_env('BUDDIES_SMTP_HOST', 'smtp.gmail.com'));
    $port = (int)(buddies_mail_env('BUDDIES_SMTP_PORT', '587') ?: '587');
    $secure = strtolower(trim((string)buddies_mail_env('BUDDIES_SMTP_SECURE', 'tls')));
    $user = trim((string)buddies_mail_env('BUDDIES_SMTP_USER', ''));
    $pass = (string)buddies_mail_env('BUDDIES_SMTP_PASS', '');
    // Googleの管理画面上で4文字区切り表示されたアプリパスワードを貼り付けても扱えるようにする。
    if (strcasecmp($host, 'smtp.gmail.com') === 0) $pass = preg_replace('/\s+/', '', $pass) ?? $pass;
    if ($host === '' || $user === '' || $pass === '') {
        buddies_mail_set_last_error('configuration');
        error_log('[buddies] SMTP settings are incomplete');
        return false;
    }

    $attempts = [[$host, $port, $secure]];
    // 共有サーバー側で587/STARTTLSだけが制限される場合に備え、Gmailの公式SSL経路も試す。
    if (strcasecmp($host, 'smtp.gmail.com') === 0 && $port === 587 && $secure === 'tls') {
        $attempts[] = [$host, 465, 'ssl'];
    }

    $lastError = null;
    foreach ($attempts as [$attemptHost, $attemptPort, $attemptSecure]) {
        try {
            buddies_mail_smtp_send_once($recipients, $subject, $text, $attemptHost, $attemptPort, $attemptSecure, $user, $pass);
            buddies_mail_set_last_error(null);
            return true;
        } catch (Throwable $e) {
            $lastError = $e;
            $stage = $e instanceof BuddiesMailTransportException ? $e->stage : 'delivery';
            // 認証・送信拒否はポートを変えても解決しないため、二重送信を避けて終了する。
            if (!in_array($stage, ['connection', 'tls'], true)) break;
        }
    }

    $stage = $lastError instanceof BuddiesMailTransportException ? $lastError->stage : 'delivery';
    buddies_mail_set_last_error($stage);
    error_log('[buddies] SMTP send failed [' . $stage . ']: ' . buddies_mail_safe_log_detail($lastError?->getMessage() ?? 'unknown error'));
    return false;
}

function buddies_mail_verify_url(string $token): string {
    $base = trim((string)buddies_mail_env('BUDDIES_MAIL_VERIFY_URL', ''));
    if ($base === '') $base = 'https://buddies46.stars.ne.jp/satellite/buddies/email-verify.php';
    return $base . (str_contains($base, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
}

function buddies_mail_reset_url(string $token): string {
    $base = trim((string)buddies_mail_env('BUDDIES_MAIL_RESET_URL', ''));
    if ($base === '') $base = 'https://buddies46.stars.ne.jp/satellite/buddies/password-reset.php';
    return $base . (str_contains($base, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
}

function buddies_mail_contact_url(): string {
    return trim((string)buddies_mail_env('BUDDIES_MAIL_CONTACT_URL', 'https://buddies46.stars.ne.jp/satellite/form/'));
}

function buddies_mail_terms_url(): string {
    return trim((string)buddies_mail_env('BUDDIES_MAIL_TERMS_URL', 'https://buddies46.stars.ne.jp/satellite/index.php?section=terms'));
}

function buddies_mail_privacy_url(): string {
    return trim((string)buddies_mail_env('BUDDIES_MAIL_PRIVACY_URL', 'https://buddies46.stars.ne.jp/satellite/index.php?section=privacy'));
}

function buddies_mail_footer(): string {
    return "お問い合わせ\n" . buddies_mail_contact_url() . "\n\n"
        . "利用規約\n" . buddies_mail_terms_url() . "\n\n"
        . "プライバシーポリシー\n" . buddies_mail_privacy_url() . "\n\n"
        . "--\nBuddies profile\nSakumap\nひろまめ。";
}

function buddies_mail_expiry_label(int $seconds): string {
    $timezoneName = (string)(buddies_mail_env('BUDDIES_TIMEZONE', 'Asia/Tokyo') ?: 'Asia/Tokyo');
    try {
        $timezone = new DateTimeZone($timezoneName);
    } catch (Throwable $e) {
        $timezone = new DateTimeZone('Asia/Tokyo');
    }
    return (new DateTimeImmutable('now', $timezone))->modify('+' . max(0, $seconds) . ' seconds')->format('n月j日H時i分');
}

function buddies_mail_send_verification(string $email, array $user, string $token): bool {
    $name = (string)($user['display_name'] ?? $user['username'] ?? 'Buddies');
    $url = buddies_mail_verify_url($token);
    $text = $name . " さん\n\n"
        . "サイトをご利用いただきありがとうございます。\n\n"
        . "Buddiesプロフィールのメールアドレス認証を受け付けました。\n"
        . "次のリンクを開くと認証が完了します。\n\n"
        . $url . "\n\n"
        . "このリンクの有効期限は24時間後の" . buddies_mail_expiry_label(86400) . "です。認証が完了すると、このリンクは無効になります。\n"
        . "心当たりがない場合は、このメールを破棄してください。\n\n"
        . buddies_mail_footer() . "\n";
    return buddies_mail_send($email, buddies_mail_subject_prefix() . ' メールアドレスの認証', $text);
}

function buddies_mail_send_password_reset(string $email, array $user, string $token): bool {
    $name = (string)($user['display_name'] ?? $user['username'] ?? 'Buddies');
    $url = buddies_mail_reset_url($token);
    $text = $name . " さん\n\n"
        . "サイトをご利用いただきありがとうございます。\n\n"
        . "Buddiesプロフィールのパスワードリセットを受け付けました。\n"
        . "心当たりがある場合は、次のリンクから新しいパスワードを設定してください。\n\n"
        . $url . "\n\n"
        . "このリンクの有効期限は30分後の" . buddies_mail_expiry_label(1800) . "です。パスワードの変更が完了すると、このリンクは無効になります。\n"
        . "心当たりがない場合は、このメールを破棄してください。\n\n"
        . buddies_mail_footer() . "\n";
    return buddies_mail_send($email, buddies_mail_subject_prefix() . ' パスワードリセット', $text);
}
