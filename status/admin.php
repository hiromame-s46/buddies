<?php
session_start();
$password = '447686'; // 必ず変更

if (isset($_POST['login'])) {
  if ($_POST['password'] === $password) {
    $_SESSION['admin'] = true;
    header('Location: admin.php');
    exit;
  }
  $error = 'パスワードが違います';
}
if (isset($_GET['logout'])) {
  session_destroy();
  header('Location: admin.php');
  exit;
}

$data = json_decode(file_get_contents(__DIR__ . '/data.json'), true);
$success = isset($_GET['success']);
$tab = $_GET['tab'] ?? 'systems';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>管理画面</title>
  <meta name="robots" content="noindex,nofollow">
  <meta name="description" content="Buddies profile のシステムステータス管理ページです。">
  <meta property="og:title" content="システムステータス管理 - Buddies profile">
  <meta property="og:description" content="Buddies profile のシステムステータス管理ページです。">
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://buddies46.stars.ne.jp/satellite/buddies/status/admin.php">
  <meta property="og:image" content="https://buddies46.stars.ne.jp/satellite/buddies/icon/android-chrome-512x512.png">
  <meta property="og:image:width" content="512">
  <meta property="og:image:height" content="512">
  <meta property="og:site_name" content="Buddies">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="システムステータス管理 - Buddies profile">
  <meta name="twitter:description" content="Buddies profile のシステムステータス管理ページです。">
  <meta name="twitter:image" content="https://buddies46.stars.ne.jp/satellite/buddies/icon/android-chrome-512x512.png">
  <link rel="canonical" href="https://buddies46.stars.ne.jp/satellite/buddies/status/admin.php">
  <link rel="apple-touch-icon" sizes="180x180" href="../icon/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="../icon/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="../icon/favicon-16x16.png">
  <link rel="manifest" href="../icon/site.webmanifest">
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { 
      font-family: -apple-system, BlinkMacSystemFont, sans-serif; 
      background: #F9FAFB; 
      padding: 0 0 60px;
      color: #111;
    }
    .container { max-width: 680px; margin: 0 auto; }
    .header {
      background: #fff;
      border-bottom: 1px solid #E5E7EB;
      padding: 20px 16px;
      margin-bottom: 24px;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .header-inner {
      max-width: 680px;
      margin: 0 auto;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    h1 { font-size: 20px; font-weight: 700; }
    .nav-link {
      font-size: 14px;
      color: #6B7280;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .tabs {
      display: flex;
      gap: 8px;
      padding: 0 16px;
      margin-bottom: 24px;
    }
    .tab {
      flex: 1;
      padding: 14px;
      background: #fff;
      border: 1px solid #E5E7EB;
      border-radius: 16px;
      text-align: center;
      font-size: 15px;
      font-weight: 600;
      color: #6B7280;
      text-decoration: none;
    }
    .tab.active {
      background: #000;
      color: #fff;
      border-color: #000;
    }
    .alert {
      margin: 0 16px 20px;
      padding: 16px;
      border-radius: 16px;
      font-size: 14px;
      background: #D1FAE5;
      color: #065F46;
      border: 1px solid #6EE7B7;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .content {
      padding: 0 16px;
    }
    .section-title {
      font-size: 18px;
      font-weight: 700;
      margin: 32px 0 16px;
    }
    .card {
      background: #fff;
      border: 1px solid #E5E7EB;
      border-radius: 24px;
      padding: 24px;
      margin-bottom: 16px;
    }
    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: start;
      margin-bottom: 20px;
      padding-bottom: 16px;
      border-bottom: 1px solid #F3F4F6;
    }
    .card-title { font-size: 17px; font-weight: 600; }
    .card-meta { font-size: 12px; color: #9CA3AF; margin-top: 4px; }
    .form-group { margin-bottom: 20px; }
    .form-group:last-child { margin-bottom: 0; }
    label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 8px;
      color: #374151;
    }
    input, textarea, select {
      width: 100%;
      padding: 16px;
      font-size: 16px;
      border: 1px solid #D1D5DB;
      border-radius: 14px;
      font-family: inherit;
      background: #fff;
    }
    input:focus, textarea:focus, select:focus {
      outline: none;
      border-color: #000;
    }
    textarea { 
      min-height: 120px; 
      resize: vertical; 
      line-height: 1.6; 
    }
    .btn {
      width: 100%;
      padding: 18px;
      font-size: 16px;
      font-weight: 600;
      background: #000;
      color: #fff;
      border: none;
      border-radius: 9999px;
      cursor: pointer;
    }
    .btn:active { opacity: 0.8; }
    .btn-delete {
      background: #fff;
      color: #DC2626;
      border: 1px solid #FEE2E2;
      margin-top: 12px;
    }
    .btn-delete:active { background: #FEE2E2; }
    .login-box {
      background: #fff;
      border: 1px solid #E5E7EB;
      border-radius: 30px;
      padding: 40px 24px;
      margin: 60px 16px 0;
    }
    .error { 
      color: #DC2626; 
      font-size: 14px; 
      margin-bottom: 16px;
      padding: 12px;
      background: #FEE2E2;
      border-radius: 12px;
    }
    .empty {
      text-align: center;
      padding: 40px 20px;
      color: #9CA3AF;
      font-size: 14px;
    }
    [data-lucide] { width: 16px; height: 16px; }
  </style>
</head>
<body>
<?php if (!isset($_SESSION['admin'])): ?>
  <div class="container">
    <div class="login-box">
      <h1 style="margin-bottom:24px;">管理者ログイン</h1>
      <?php if(isset($error)): ?><div class="error"><?= $error ?></div><?php endif; ?>
      <form method="post">
        <div class="form-group">
          <label>パスワード</label>
          <input type="password" name="password" autocomplete="current-password">
        </div>
        <button type="submit" name="login" class="btn">ログイン</button>
      </form>
    </div>
  </div>
<?php else: ?>
  <div class="header">
    <div class="header-inner">
      <h1>管理画面</h1>
      <div style="display:flex;gap:16px;">
        <a href="index.php" class="nav-link">
          <i data-lucide="eye"></i>
          サイト確認
        </a>
        <a href="?logout=1" class="nav-link">
          <i data-lucide="log-out"></i>
          ログアウト
        </a>
      </div>
    </div>
  </div>

  <div class="container">
    <?php if($success): ?>
      <div class="alert">
        <i data-lucide="check-circle" style="width:20px;height:20px;"></i>
        更新しました
      </div>
    <?php endif; ?>

    <div class="tabs">
      <a href="?tab=systems" class="tab <?= $tab==='systems'?'active':'' ?>">
        <i data-lucide="server" style="width:18px;height:18px;vertical-align:middle;margin-right:4px;"></i>
        システム
      </a>
      <a href="?tab=releases" class="tab <?= $tab==='releases'?'active':'' ?>">
        <i data-lucide="package" style="width:18px;height:18px;vertical-align:middle;margin-right:4px;"></i>
        リリース
      </a>
    </div>

    <div class="content">
    <?php if($tab === 'systems'): ?>
      
      <h2 class="section-title">新規システム追加</h2>
      <div class="card">
        <form action="api.php" method="post">
          <input type="hidden" name="action" value="add_system">
          <div class="form-group">
            <label>システム名</label>
            <input name="name" placeholder="例: APIサーバー" required>
          </div>
          <div class="form-group">
            <label>ステータス</label>
            <select name="status">
              <option value="正常">正常</option>
              <option value="不具合あり">不具合あり</option>
              <option value="メンテナンス中">メンテナンス中</option>
              <option value="停止中">停止中</option>
              <option value="開発中">開発中</option>
            </select>
          </div>
          <div class="form-group">
            <label>詳細・備考</label>
            <textarea name="note" placeholder="障害内容やメンテナンス予定など"></textarea>
          </div>
          <button type="submit" class="btn">追加する</button>
        </form>
      </div>

      <h2 class="section-title">登録済みシステム</h2>
      <?php if(empty($data['systems'])): ?>
        <div class="empty">まだシステムが登録されていません</div>
      <?php endif; ?>
      <?php foreach($data['systems'] as $i => $sys): ?>
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title"><?= htmlspecialchars($sys['name']) ?></div>
            <div class="card-meta">最終更新: <?= htmlspecialchars($sys['updated']) ?></div>
          </div>
        </div>
        <form action="api.php" method="post">
          <input type="hidden" name="action" value="update_system">
          <input type="hidden" name="index" value="<?= $i ?>">
          <div class="form-group">
            <label>システム名</label>
            <input name="name" value="<?= htmlspecialchars($sys['name']) ?>">
          </div>
          <div class="form-group">
            <label>ステータス</label>
            <select name="status">
              <?php foreach(['正常','不具合あり','メンテナンス中','停止中','開発中'] as $s): ?>
              <option value="<?= $s ?>" <?= $sys['status']==$s?'selected':'' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>詳細・備考</label>
            <textarea name="note"><?= htmlspecialchars($sys['note']) ?></textarea>
          </div>
          <button type="submit" class="btn">更新する</button>
        </form>
        <form action="api.php" method="post" onsubmit="return confirm('<?= htmlspecialchars($sys['name']) ?>を削除しますか？')">
          <input type="hidden" name="action" value="delete_system">
          <input type="hidden" name="index" value="<?= $i ?>">
          <button type="submit" class="btn btn-delete">削除</button>
        </form>
      </div>
      <?php endforeach; ?>

    <?php else: ?>

      <h2 class="section-title">新規リリース追加</h2>
      <div class="card">
        <form action="api.php" method="post">
          <input type="hidden" name="action" value="add_release">
          <div class="form-group">
            <label>バージョン</label>
            <input name="version" placeholder="v1.0.0" required>
          </div>
          <div class="form-group">
            <label>概要</label>
            <input name="summary" placeholder="一言で変更内容" required>
          </div>
          <div class="form-group">
            <label>詳細</label>
            <textarea name="detail" placeholder="変更内容の詳細"></textarea>
          </div>
          <div class="form-group">
            <label>リリース日</label>
            <input type="date" name="date" value="<?= date('Y-m-d') ?>">
          </div>
          <button type="submit" class="btn">追加する</button>
        </form>
      </div>

      <h2 class="section-title">登録済みリリース</h2>
      <?php if(empty($data['releases'])): ?>
        <div class="empty">まだリリースノートがありません</div>
      <?php endif; ?>
      <?php foreach($data['releases'] as $i => $rel): ?>
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title"><?= htmlspecialchars($rel['version']) ?></div>
            <div class="card-meta"><?= htmlspecialchars($rel['date']) ?></div>
          </div>
        </div>
        <form action="api.php" method="post">
          <input type="hidden" name="action" value="update_release">
          <input type="hidden" name="index" value="<?= $i ?>">
          <div class="form-group">
            <label>バージョン</label>
            <input name="version" value="<?= htmlspecialchars($rel['version']) ?>">
          </div>
          <div class="form-group">
            <label>概要</label>
            <input name="summary" value="<?= htmlspecialchars($rel['summary']) ?>">
          </div>
          <div class="form-group">
            <label>詳細</label>
            <textarea name="detail"><?= htmlspecialchars($rel['detail']) ?></textarea>
          </div>
          <div class="form-group">
            <label>リリース日</label>
            <input type="date" name="date" value="<?= htmlspecialchars($rel['date']) ?>">
          </div>
          <button type="submit" class="btn">更新する</button>
        </form>
        <form action="api.php" method="post" onsubmit="return confirm('<?= htmlspecialchars($rel['version']) ?>を削除しますか？')">
          <input type="hidden" name="action" value="delete_release">
          <input type="hidden" name="index" value="<?= $i ?>">
          <button type="submit" class="btn btn-delete">削除</button>
        </form>
      </div>
      <?php endforeach; ?>

    <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<script>lucide.createIcons();</script>
</body>
</html>
