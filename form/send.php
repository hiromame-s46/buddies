<?php
mb_language('Japanese');
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_file = sys_get_temp_dir() . '/buddies_form_' . hash('sha256', $ip) . '.txt';
$now = time();
$rate_seconds = 6;
if (file_exists($rate_file) && ($now - (int)file_get_contents($rate_file)) < $rate_seconds) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => '連続送信を防止しています。数秒後にもう一度お試しください。'], JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize_header(string $val): string { return preg_replace('/[\r\n\0]/', '', $val); }
function clean(string $val, int $max = 1000): string {
    $val = trim($val);
    return mb_strlen($val) > $max ? mb_substr($val, 0, $max) : $val;
}
function json_fail(string $message, int $code = 200): never {
    if ($code !== 200) http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
function resolve_config_path(): ?string {
    $candidates = [
        __DIR__ . '/../../../../api/config.php',
        __DIR__ . '/../../../api/config.php',
        __DIR__ . '/../../api/config.php',
        __DIR__ . '/../api/config.php',
    ];
    foreach ($candidates as $p) if (is_file($p)) return $p;
    return null;
}
function contact_db(): ?PDO {
    static $pdo = false;
    if ($pdo instanceof PDO) return $pdo;
    if ($pdo === null) return null;
    $path = resolve_config_path();
    if (!$path) { $pdo = null; return null; }
    try {
        $config = require $path;
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['dbname']);
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        error_log('[contact] db connect failed: ' . $e->getMessage());
        $pdo = null;
        return null;
    }
}
function table_columns(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query('DESCRIBE `' . str_replace('`', '``', $table) . '`')->fetchAll();
        return array_column($rows, 'Field');
    } catch (Throwable $e) { return []; }
}
function ensure_contact_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_contact_inquiries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        contact_code CHAR(4) NOT NULL,
        user_id INT NULL,
        account_mode VARCHAR(20) NOT NULL DEFAULT 'guest',
        requester_name VARCHAR(128) NULL,
        requester_username VARCHAR(64) NULL,
        category VARCHAR(80) NOT NULL,
        title VARCHAR(200) NULL,
        site_name VARCHAR(200) NULL,
        message TEXT NOT NULL,
        need_reply TINYINT(1) NOT NULL DEFAULT 1,
        reply_channel VARCHAR(20) NOT NULL DEFAULT 'site',
        dm_service VARCHAR(32) NULL,
        dm_account VARCHAR(120) NULL,
        email_notification TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        admin_replied_at DATETIME NULL,
        user_read_at DATETIME NULL,
        user_hidden_at DATETIME NULL,
        admin_read_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_contact_code (contact_code),
        INDEX idx_contact_user (user_id, created_at),
        INDEX idx_contact_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $cols = table_columns($pdo, 'buddies_contact_inquiries');
    if ($cols && !in_array('contact_code', $cols, true)) {
        $pdo->exec("ALTER TABLE buddies_contact_inquiries ADD COLUMN contact_code CHAR(4) NULL AFTER id");
        $rows = $pdo->query("SELECT id FROM buddies_contact_inquiries WHERE contact_code IS NULL OR contact_code='' ORDER BY id ASC")->fetchAll();
        foreach ($rows as $row) {
            $code = generate_contact_code($pdo);
            $st = $pdo->prepare('UPDATE buddies_contact_inquiries SET contact_code=? WHERE id=?');
            $st->execute([$code, (int)$row['id']]);
        }
        try { $pdo->exec("ALTER TABLE buddies_contact_inquiries MODIFY contact_code CHAR(4) NOT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE buddies_contact_inquiries ADD UNIQUE KEY uq_contact_code (contact_code)"); } catch (Throwable $e) {}
    }
    $cols = table_columns($pdo, 'buddies_contact_inquiries');
    if ($cols) {
        if (!in_array('user_read_at', $cols, true)) {
            try { $pdo->exec("ALTER TABLE buddies_contact_inquiries ADD COLUMN user_read_at DATETIME NULL AFTER admin_replied_at"); } catch (Throwable $e) {}
        }
        if (!in_array('user_hidden_at', $cols, true)) {
            try { $pdo->exec("ALTER TABLE buddies_contact_inquiries ADD COLUMN user_hidden_at DATETIME NULL AFTER user_read_at"); } catch (Throwable $e) {}
        }
        if (!in_array('admin_read_at', $cols, true)) {
            $after = in_array('user_hidden_at', $cols, true) ? 'user_hidden_at' : 'user_read_at';
            try { $pdo->exec("ALTER TABLE buddies_contact_inquiries ADD COLUMN admin_read_at DATETIME NULL AFTER {$after}"); } catch (Throwable $e) {}
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_contact_messages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        inquiry_id BIGINT UNSIGNED NOT NULL,
        sender VARCHAR(20) NOT NULL DEFAULT 'user',
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_contact_messages_inquiry (inquiry_id, id),
        CONSTRAINT fk_contact_messages_inquiry FOREIGN KEY (inquiry_id)
          REFERENCES buddies_contact_inquiries(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_contact_images (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        inquiry_id BIGINT UNSIGNED NOT NULL,
        message_id BIGINT UNSIGNED NULL,
        file_path VARCHAR(255) NOT NULL,
        file_url VARCHAR(512) NOT NULL,
        original_name VARCHAR(255) NULL,
        mime_type VARCHAR(80) NOT NULL,
        size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_contact_images_inquiry (inquiry_id, id),
        CONSTRAINT fk_contact_images_inquiry FOREIGN KEY (inquiry_id)
          REFERENCES buddies_contact_inquiries(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $imgCols = table_columns($pdo, 'buddies_contact_images');
    if ($imgCols && !in_array('message_id', $imgCols, true)) {
        try { $pdo->exec("ALTER TABLE buddies_contact_images ADD COLUMN message_id BIGINT UNSIGNED NULL AFTER inquiry_id"); } catch (Throwable $e) {}
    }
}
function current_contact_user(PDO $pdo): ?array {
    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? ($_COOKIE['sakulabo_token'] ?? null);
    if (!$token) return null;
    $token = preg_replace('/^Bearer\s+/i', '', trim($token));
    try {
        $st = $pdo->prepare('SELECT u.* FROM sakulabo_users u JOIN sakulabo_sessions s ON s.user_id=u.id WHERE s.token=? AND s.expires_at > NOW() LIMIT 1');
        $st->execute([$token]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('[contact] current user failed: ' . $e->getMessage());
        return null;
    }
}
function generate_contact_code(PDO $pdo): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 200; $try++) {
        $code = '';
        for ($i = 0; $i < 4; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
        $st = $pdo->prepare('SELECT id FROM buddies_contact_inquiries WHERE contact_code=? LIMIT 1');
        $st->execute([$code]);
        if (!$st->fetch()) return $code;
    }
    throw new RuntimeException('管理番号の生成に失敗しました');
}
function absolute_url_for(string $relative): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = sanitize_header($_SERVER['HTTP_HOST'] ?? 'buddies46.stars.ne.jp');
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($scriptDir === '/' || $scriptDir === '.') $scriptDir = '';
    return $scheme . '://' . $host . $scriptDir . '/' . ltrim($relative, '/');
}
function admin_reply_url(string $code): string {
    $override = getenv('BUDDIES_CONTACT_ADMIN_URL') ?: '';
    if ($override !== '') return str_contains($override, '%s') ? sprintf($override, $code) : rtrim($override, '?&') . '?' . rawurlencode($code);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = sanitize_header($_SERVER['HTTP_HOST'] ?? 'buddies46.stars.ne.jp');
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = rtrim(dirname($script), '/');
    // send.php が Form/ 配下でも buddies 直下でも、管理画面リンクが確実に admin.html を指すようにする。
    if (preg_match('~/Form$~i', $dir)) $dir = rtrim(dirname($dir), '/');
    if ($dir === '.' || $dir === '/') $dir = '';
    return $scheme . '://' . $host . $dir . '/admin.html?tab=contacts&contact=' . rawurlencode($code);
}

function insert_contact_inquiry(PDO $pdo, ?array $user, array $data): array {
    ensure_contact_tables($pdo);
    $code = generate_contact_code($pdo);
    $userId = $user ? (int)$user['id'] : null;
    $accountMode = $user ? $data['account_mode'] : 'guest';
    $requesterName = ($user && $accountMode === 'profile') ? ($user['display_name'] ?: $user['username']) : null;
    $requesterUsername = ($user && $accountMode === 'profile') ? ($user['username'] ?? null) : null;
    $st = $pdo->prepare("INSERT INTO buddies_contact_inquiries
        (contact_code, user_id, account_mode, requester_name, requester_username, category, title, site_name, message, need_reply, reply_channel, dm_service, dm_account, email_notification)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([
        $code, $userId, $accountMode, $requesterName, $requesterUsername,
        $data['category'], $data['title'], $data['site_name'], $data['message'],
        $data['need_reply'], $data['reply_channel'], $data['dm_service'], $data['dm_account'], 0,
    ]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO buddies_contact_messages (inquiry_id, sender, body) VALUES (?,?,?)")
        ->execute([$id, 'user', $data['message']]);
    $messageId = (int)$pdo->lastInsertId();
    return ['id' => $id, 'code' => $code, 'message_id' => $messageId];
}
function image_from_upload(string $tmp, string $mime) {
    return match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png'  => @imagecreatefrompng($tmp),
        'image/gif'  => @imagecreatefromgif($tmp),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        default      => false,
    };
}
function save_compressed_jpeg(string $tmp, string $mime, string $dest): bool {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) return @copy($tmp, $dest);
    $src = image_from_upload($tmp, $mime);
    if (!$src) return @copy($tmp, $dest);
    $w = imagesx($src); $h = imagesy($src);
    if ($w <= 0 || $h <= 0) { imagedestroy($src); return false; }
    $max = 1600;
    $scale = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = imagejpeg($dst, $dest, 78);
    imagedestroy($src); imagedestroy($dst);
    return $ok;
}
function process_contact_images(PDO $pdo, int $inquiryId, string $code, ?int $messageId = null): array {
    if (empty($_FILES['images'])) return [];
    $files = $_FILES['images'];
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $tmps  = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $errs  = is_array($files['error']) ? $files['error'] : [$files['error']];
    $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    $dir = __DIR__ . '/img';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return [];
    $saved = [];
    $limit = min(count($names), 2);
    for ($i = 0; $i < $limit; $i++) {
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        if (($sizes[$i] ?? 0) > 5 * 1024 * 1024) continue;
        $tmp = $tmps[$i] ?? '';
        if (!is_uploaded_file($tmp)) continue;
        $mime = mime_content_type($tmp) ?: '';
        if (!in_array($mime, $allowed, true)) continue;
        if (@getimagesize($tmp) === false) continue;
        $filename = $code . '_' . ($i + 1) . '_' . bin2hex(random_bytes(4)) . '.jpg';
        $dest = $dir . '/' . $filename;
        if (!save_compressed_jpeg($tmp, $mime, $dest)) continue;
        $relative = 'img/' . $filename;
        $url = absolute_url_for($relative);
        $size = (int)@filesize($dest);
        $original = clean((string)($names[$i] ?? ''), 255);
        $pdo->prepare("INSERT INTO buddies_contact_images (inquiry_id, message_id, file_path, file_url, original_name, mime_type, size_bytes) VALUES (?,?,?,?,?,?,?)")
            ->execute([$inquiryId, $messageId, $relative, $url, $original, 'image/jpeg', $size]);
        $saved[] = ['path' => $dest, 'url' => $url, 'name' => $filename, 'mime' => 'image/jpeg', 'size' => $size];
    }
    return $saved;
}

$allowed_categories = [
    'お問い合わせ', 'サイトの感想や意見', '情報の提供', '不具合の報告',
    '誤情報の報告', '機能のリクエスト', 'サイトのリクエストや提案',
    '情報請求', 'その他',
];

$category = clean($_POST['category'] ?? '', 80);
$need_reply = clean($_POST['need_reply'] ?? '希望する', 20);
$title = clean($_POST['title'] ?? '', 200);
$site_name = clean($_POST['site_name'] ?? '', 200);
$message = clean($_POST['message'] ?? '', 3000);
$agree = isset($_POST['agree']) ? '同意済み' : '未同意';

if (!in_array($category, $allowed_categories, true)) json_fail('不正なカテゴリです');
if (empty($category) || empty($message) || $agree !== '同意済み') json_fail('必須項目が未入力です');
if (in_array($category, ['不具合の報告', '誤情報の報告'], true) && empty($site_name)) json_fail('対象サイト名を入力してください');
if (empty($title)) $title = '';

$pdo = contact_db();
if (!$pdo) json_fail('DB接続に失敗しました。時間をおいて再度お試しください。', 500);
ensure_contact_tables($pdo);
$user = current_contact_user($pdo);
$is_logged_in = (bool)$user;

$account_mode = clean($_POST['account_mode'] ?? ($is_logged_in ? 'profile' : 'guest'), 20);
if (!$is_logged_in) $account_mode = 'guest';
if (!in_array($account_mode, ['guest','anonymous','profile'], true)) $account_mode = $is_logged_in ? 'profile' : 'guest';

$need_reply_bool = !in_array($need_reply, ['0','false','希望しない','不要','no'], true);
$need_reply = $need_reply_bool ? '希望する' : '希望しない';

$reply_channel = clean($_POST['reply_channel'] ?? ($is_logged_in ? 'site' : 'dm'), 20);
if (!$need_reply_bool) $reply_channel = 'none';
elseif (!$is_logged_in && $reply_channel === 'site') $reply_channel = 'dm';
if (!in_array($reply_channel, ['site','dm','none'], true)) $reply_channel = $need_reply_bool ? ($is_logged_in ? 'site' : 'dm') : 'none';

$sns_service = clean($_POST['sns_service'] ?? $_POST['dm_service'] ?? '', 32);
$sns_account = clean($_POST['sns_account'] ?? $_POST['dm_account'] ?? '', 120);
if ($need_reply_bool && $reply_channel === 'dm') {
    if (!in_array($sns_service, ['X', 'Instagram'], true)) json_fail('DM返信は X または Instagram を選択してください');
    if ($sns_account === '') json_fail('DM返信用のアカウント名を入力してください');
}

try {
    $created = insert_contact_inquiry($pdo, $user, [
        'category' => $category,
        'title' => $title,
        'site_name' => $site_name,
        'message' => $message,
        'account_mode' => $account_mode,
        'need_reply' => $need_reply_bool ? 1 : 0,
        'reply_channel' => $reply_channel,
        'dm_service' => $reply_channel === 'dm' ? $sns_service : '',
        'dm_account' => $reply_channel === 'dm' ? $sns_account : '',
    ]);
    $contact_id = (int)$created['id'];
    $contact_code = (string)$created['code'];
    $images = process_contact_images($pdo, $contact_id, $contact_code, (int)($created['message_id'] ?? 0));
} catch (Throwable $e) {
    error_log('[contact] create failed: ' . $e->getMessage());
    json_fail('お問い合わせの保存に失敗しました。', 500);
}

$to = 'nagahiro.s122@gmail.com';
$from = 'noreply@buddies46.stars.ne.jp';
$adminUrl = admin_reply_url($contact_code);

$body  = "お問い合わせがありました。\n\n";
$body .= "────────────────────\n";
$body .= "管理番号: {$contact_code}\n";
$body .= "カテゴリ: {$category}\n";
$body .= "返信の希望: {$need_reply}\n";
if ($need_reply_bool) {
    $replyLabel = $reply_channel === 'site' ? 'このサイトで返信を受け取る' : ($reply_channel === 'dm' ? 'DMで返信を受け取る' : 'なし');
    $body .= "返信方法: {$replyLabel}\n";
    if ($reply_channel === 'dm') {
        $body .= "SNSサービス名: {$sns_service}\n";
        $body .= "アカウント名: {$sns_account}\n";
    }
}
if ($is_logged_in) {
    $shown = $account_mode === 'profile' ? (($user['display_name'] ?: $user['username']) . ' / @' . $user['username']) : '匿名（ログインユーザーID: ' . (int)$user['id'] . '）';
    $body .= "アカウント: {$shown}\n";
} else {
    $body .= "アカウント: ログインなし\n";
}
if (!empty($site_name)) $body .= "対象サイト名: {$site_name}\n";
$body .= "画像: " . (count($images) ? count($images) . "枚添付" : 'なし') . "\n";
$body .= "このお問い合わせに返信する: {$adminUrl}\n";
$body .= "────────────────────\n";
$body .= "本文:\n{$message}\n";
$body .= "────────────────────\n";
$body .= "送信日時: " . date('Y/m/d H:i:s') . "\n";
$body .= "IP: {$ip}\n";
$body .= "UA: " . mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200) . "\n";

$subject_raw = '[お問い合わせ ' . $contact_code . '] ' . $category;
$subject = sanitize_header($subject_raw);

$boundary = bin2hex(random_bytes(16));
$headers  = 'From: ' . sanitize_header($from) . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";

$mime_body  = "--{$boundary}\r\n";
$mime_body .= "Content-Type: text/plain; charset=UTF-8\r\n";
$mime_body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$mime_body .= $body . "\r\n";

foreach ($images as $img) {
    if (!is_file($img['path'])) continue;
    $content = chunk_split(base64_encode(file_get_contents($img['path'])));
    $safeName = sanitize_header($img['name']);
    $mime_body .= "--{$boundary}\r\n";
    $mime_body .= "Content-Type: image/jpeg; name=\"{$safeName}\"\r\n";
    $mime_body .= "Content-Transfer-Encoding: base64\r\n";
    $mime_body .= "Content-Disposition: attachment; filename=\"{$safeName}\"\r\n\r\n";
    $mime_body .= $content . "\r\n";
}
$mime_body .= "--{$boundary}--";

$encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$extra_params = '-f ' . escapeshellarg($from);
$mail_sent = mail($to, $encoded_subject, $mime_body, $headers, $extra_params);
file_put_contents($rate_file, (string)$now, LOCK_EX);
if (!$mail_sent) {
    error_log('[contact] admin mail failed for ' . $contact_code);
}

echo json_encode([
    'success' => true,
    'message' => $mail_sent ? '送信完了' : 'お問い合わせは保存しましたが、管理者メール通知に失敗しました。',
    'mail_sent' => $mail_sent,
    'contact_id' => $contact_id,
    'contact_code' => $contact_code,
    'admin_url' => $adminUrl,
], JSON_UNESCAPED_UNICODE);
?>
