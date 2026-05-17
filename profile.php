<?php
declare(strict_types=1);
require __DIR__ . '/og-card-lib.php';

$uid = (int)($_GET['uid'] ?? 0);
$profile = buddies_profile($uid);

if (!$profile) {
    http_response_code(404);
    $title = 'Buddies profile';
    $desc = 'プロフィールが見つかりませんでした。';
    $image = BUDDIES_BASE_URL . 'icon/android-chrome-512x512.png';
    $appUrl = BUDDIES_BASE_URL;
} else {
    $title = $profile['display_name'] . 'のBuddiesプロフィール';
    $desc = buddies_meta_description($profile);
    $image = buddies_image_url((int)$profile['id']);
    $appUrl = buddies_app_url((int)$profile['id']);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= buddies_esc($title) ?></title>
<meta name="description" content="<?= buddies_esc($desc) ?>">
<meta property="og:title" content="<?= buddies_esc($title) ?>">
<meta property="og:description" content="<?= buddies_esc($desc) ?>">
<meta property="og:type" content="profile">
<meta property="og:url" content="<?= buddies_esc(buddies_profile_url($uid)) ?>">
<meta property="og:image" content="<?= buddies_esc($image) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:site_name" content="Buddies">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= buddies_esc($title) ?>">
<meta name="twitter:description" content="<?= buddies_esc($desc) ?>">
<meta name="twitter:image" content="<?= buddies_esc($image) ?>">
<link rel="canonical" href="<?= buddies_esc(buddies_profile_url($uid)) ?>">
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f7f7f7;color:#111;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Kaku Gothic ProN","Yu Gothic",Meiryo,sans-serif;padding:24px}
.card{width:min(520px,100%);background:#fff;border:1px solid #eee;border-radius:24px;padding:22px;box-shadow:0 12px 40px rgba(0,0,0,.06)}
img{width:100%;border-radius:18px;border:1px solid #eee;display:block;background:#fff9f3}
h1{font-size:22px;margin:18px 0 8px;line-height:1.35}
p{color:#666;line-height:1.7;margin:0 0 18px}
.actions{display:flex;gap:10px;flex-wrap:wrap}
a{flex:1;min-width:150px;text-align:center;text-decoration:none;border-radius:9999px;padding:13px 16px;font-weight:700;border:1px solid #ddd;color:#111}
a.primary{background:#111;color:#fff;border-color:#111}
</style>
</head>
<body>
<main class="card">
  <img src="<?= buddies_esc($image) ?>" alt="">
  <h1><?= buddies_esc($title) ?></h1>
  <p><?= buddies_esc($desc) ?></p>
  <div class="actions">
    <a class="primary" href="<?= buddies_esc($appUrl) ?>">プロフィールを開く</a>
    <?php if ($profile): ?><a href="<?= buddies_esc(buddies_card_url((int)$profile['id'])) ?>">プロフィールカードを表示</a><?php endif; ?>
  </div>
</main>
<script>
const isBot=/bot|crawler|spider|crawling|facebookexternalhit|twitterbot|line|discordbot|slackbot/i.test(navigator.userAgent);
if(!isBot) setTimeout(()=>{ location.href=<?= json_encode($appUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>; }, 700);
</script>
</body>
</html>
