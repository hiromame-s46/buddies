<?php
mb_language("Japanese");
mb_internal_encoding("UTF-8");

header('Content-Type: application/json; charset=UTF-8');

// POST のみ受け付ける
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// ── レートリミット（同一IPから60秒に1回まで） ────────────────
$ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_file = sys_get_temp_dir() . '/buddies_form_' . hash('sha256', $ip) . '.txt';
$now       = time();
if (file_exists($rate_file) && ($now - (int)file_get_contents($rate_file)) < 60) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => '送信は60秒に1回までです。しばらくお待ちください。']);
    exit;
}

// ── ヘッダーインジェクション対策：改行文字を除去 ──────────────
function sanitize_header(string $val): string {
    return preg_replace('/[\r\n\0]/', '', $val);
}

// ── テキスト入力のサニタイズ ──────────────────────────────────
function clean(string $val, int $max = 1000): string {
    $val = trim($val);
    if (mb_strlen($val) > $max) {
        $val = mb_substr($val, 0, $max);
    }
    return $val;
}

// 許可するカテゴリ値のホワイトリスト
$allowed_categories = [
    'お問い合わせ', 'サイトの感想や意見', '情報の提供', '不具合の報告',
    '誤情報の報告', '機能のリクエスト', 'サイトのリクエストや提案',
    '情報請求', 'その他',
];

// POSTデータ取得・検証
$category   = clean($_POST['category']   ?? '');
$need_reply = clean($_POST['need_reply'] ?? '');
$sns_service= clean($_POST['sns_service']?? '', 100);
$sns_account= clean($_POST['sns_account']?? '', 100);
$title      = clean($_POST['title']      ?? '', 200);
$site_name  = clean($_POST['site_name']  ?? '', 200);
$message    = clean($_POST['message']    ?? '', 3000);
$agree      = isset($_POST['agree']) ? '同意済み' : '未同意';

// カテゴリのホワイトリスト検証
if (!in_array($category, $allowed_categories, true)) {
    echo json_encode(['success' => false, 'message' => '不正なカテゴリです']);
    exit;
}

// 返信希望の値検証
if (!in_array($need_reply, ['希望しない', '希望する'], true)) {
    echo json_encode(['success' => false, 'message' => '不正な値です']);
    exit;
}

// 必須チェック
if (empty($category) || empty($message) || $agree !== '同意済み') {
    echo json_encode(['success' => false, 'message' => '必須項目が未入力です']);
    exit;
}

if ($need_reply === '希望する' && (empty($sns_service) || empty($sns_account))) {
    echo json_encode(['success' => false, 'message' => 'SNS情報を入力してください']);
    exit;
}

if (in_array($category, ['不具合の報告', '誤情報の報告'], true) && empty($site_name)) {
    echo json_encode(['success' => false, 'message' => '対象サイト名を入力してください']);
    exit;
}

// タイトルが空なら「未入力」
if (empty($title)) {
    $title = '未入力';
}

// ── 送信先・送信元 ───────────────────────────────────────────
$to   = 'nagahiro.s122@gmail.com';
// 送信元ドメインをハードコードしてホストヘッダインジェクションを防ぐ
$from = 'noreply@buddies46.stars.ne.jp';

// ── メール本文作成 ───────────────────────────────────────────
$body  = "お問い合わせがありました。\n\n";
$body .= "────────────────────\n";
$body .= "カテゴリ: {$category}\n";
$body .= "返信の希望: {$need_reply}\n";
if ($need_reply === '希望する') {
    $body .= "SNSサービス名: {$sns_service}\n";
    $body .= "アカウント名: {$sns_account}\n";
}
$body .= "タイトル: {$title}\n";
if (!empty($site_name)) {
    $body .= "対象サイト名: {$site_name}\n";
}
$body .= "────────────────────\n";
$body .= "本文:\n{$message}\n";
$body .= "────────────────────\n";
$body .= "送信日時: " . date('Y/m/d H:i:s') . "\n";
$body .= "IP: {$ip}\n";
$body .= "UA: " . mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200) . "\n";

// ── 件名（ヘッダーインジェクション対策済み） ─────────────────
$subject_raw = $category;
if ($title !== '未入力') {
    $subject_raw .= ' - ' . $title;
}
$subject = sanitize_header($subject_raw);

// ── MIMEマルチパート組み立て ─────────────────────────────────
$boundary = bin2hex(random_bytes(16));
$headers  = "From: " . sanitize_header($from) . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";

$mime_body  = "--{$boundary}\r\n";
$mime_body .= "Content-Type: text/plain; charset=UTF-8\r\n";
$mime_body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$mime_body .= $body . "\r\n";

// ── 画像添付（不具合報告のみ、最大3枚・5MB・画像のみ） ────────
$allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

if ($category === '不具合の報告' && isset($_FILES['images'])) {
    $files    = $_FILES['images'];
    $count    = is_array($files['name']) ? count($files['name']) : 0;
    $attached = 0;

    for ($i = 0; $i < $count && $attached < 3; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($files['size'][$i] > 5 * 1024 * 1024) continue;

        $tmp  = $files['tmp_name'][$i];
        $mime = mime_content_type($tmp);

        // MIMEタイプのホワイトリスト検証
        if (!in_array($mime, $allowed_mime, true)) continue;

        // ファイル内容でも画像か検証
        $info = @getimagesize($tmp);
        if ($info === false) continue;

        // ファイル名をサニタイズ（英数字・ハイフン・アンダースコア・ドットのみ）
        $raw_name = basename($files['name'][$i]);
        $ext      = match($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'bin',
        };
        $safe_name = 'image_' . ($i + 1) . '.' . $ext;

        $content  = chunk_split(base64_encode(file_get_contents($tmp)));

        $mime_body .= "--{$boundary}\r\n";
        $mime_body .= "Content-Type: {$mime}; name=\"{$safe_name}\"\r\n";
        $mime_body .= "Content-Transfer-Encoding: base64\r\n";
        $mime_body .= "Content-Disposition: attachment; filename=\"{$safe_name}\"\r\n\r\n";
        $mime_body .= $content . "\r\n";
        $attached++;
    }
}

$mime_body .= "--{$boundary}--";

// ── 送信 ────────────────────────────────────────────────────
$encoded_subject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
// -f オプションのシェルインジェクションを防ぐため escapeshellarg を使用
$extra_params = '-f ' . escapeshellarg($from);
$success = mail($to, $encoded_subject, $mime_body, $headers, $extra_params);

// レートリミット記録（送信成功時のみ）
if ($success) {
    file_put_contents($rate_file, $now, LOCK_EX);
}

echo json_encode(['success' => $success, 'message' => $success ? '送信完了' : 'メール送信エラー']);
?>
