<?php
$data = json_decode(file_get_contents('data.json'), true);
$systems = $data['systems'] ?? [];
$releases = $data['releases'] ?? [];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>システムステータス - Buddies profile</title>
  <meta name="description" content="Buddies profile のサービス稼働状況と更新情報を確認できます。">
  <meta property="og:title" content="システムステータス - Buddies profile">
  <meta property="og:description" content="Buddies profile のサービス稼働状況と更新情報を確認できます。">
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://buddies46.stars.ne.jp/satellite/buddies/status/">
  <meta property="og:image" content="https://buddies46.stars.ne.jp/satellite/buddies/icon/android-chrome-512x512.png">
  <meta property="og:image:width" content="512">
  <meta property="og:image:height" content="512">
  <meta property="og:site_name" content="Buddies">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="システムステータス - Buddies profile">
  <meta name="twitter:description" content="Buddies profile のサービス稼働状況と更新情報を確認できます。">
  <meta name="twitter:image" content="https://buddies46.stars.ne.jp/satellite/buddies/icon/android-chrome-512x512.png">
  <link rel="canonical" href="https://buddies46.stars.ne.jp/satellite/buddies/status/">
  <link rel="apple-touch-icon" sizes="180x180" href="../icon/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="../icon/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="../icon/favicon-16x16.png">
  <link rel="manifest" href="../icon/site.webmanifest">
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { 
      font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", sans-serif; 
      background: #fff; 
      color: #000;
      padding: 20px 16px 40px;
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
    }
    h1 { 
      font-size: 28px; 
      font-weight: 700; 
      margin-bottom: 32px; 
      letter-spacing: -0.5px;
    }
    .section {
      margin-bottom: 40px;
    }
    .section-title { 
      font-size: 13px; 
      font-weight: 600; 
      margin-bottom: 16px;
      color: #666;
      letter-spacing: 0.5px;
    }
    
    /* システムステータス */
    .status-list {
      border: 1px solid #CCCCCC;
      border-radius: 30px;
      overflow: hidden;
    }
    .status-item {
      padding: 20px;
      border-bottom: 1px solid #EEEEEE;
      background: #fff;
    }
    .status-item:last-child { 
      border-bottom: none; 
    }
    .status-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      cursor: pointer;
      min-height: 28px;
    }
    .status-left {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 16px;
      font-weight: 500;
    }
    .status-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      flex-shrink: 0;
    }
    .dot-normal { background: #22C55E; }
    .dot-error { background: #EF4444; }
    .dot-maintenance { background: #3B82F6; }
    .dot-stopped { background: #9CA3AF; }
    .dot-dev { background: #A855F7; }
    .status-right {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 15px;
      color: #666;
    }
    .status-detail {
      max-height: 0;
      overflow: hidden;
      transition: max-height 0.3s ease;
    }
    .status-detail.open { 
      max-height: 500px; 
    }
    .status-detail-inner {
      padding-top: 16px;
      margin-top: 16px;
      border-top: 1px solid #F5F5F5;
      font-size: 14px;
      color: #555;
      line-height: 1.8;
    }
    .detail-label {
      font-size: 12px;
      color: #999;
      margin-bottom: 4px;
    }
    
    /* リリースノート */
    .release-list {
      border: 1px solid #CCCCCC;
      border-radius: 30px;
      overflow: hidden;
    }
    .release-item {
      padding: 20px;
      border-bottom: 1px solid #EEEEEE;
      background: #fff;
    }
    .release-item:last-child { 
      border-bottom: none; 
    }
    .release-header {
      cursor: pointer;
    }
    .release-top {
      display: flex;
      justify-content: space-between;
      align-items: start;
      gap: 12px;
    }
    .release-version {
      font-size: 16px;
      font-weight: 600;
      margin-bottom: 6px;
    }
    .release-summary {
      font-size: 14px;
      color: #666;
      line-height: 1.5;
    }
    .release-detail {
      max-height: 0;
      overflow: hidden;
      transition: max-height 0.3s ease;
    }
    .release-detail.open { 
      max-height: 1000px; 
    }
    .release-detail-inner {
      padding-top: 16px;
      margin-top: 16px;
      border-top: 1px solid #F5F5F5;
      font-size: 14px;
      color: #555;
      line-height: 1.8;
    }
    .release-date {
      font-size: 12px;
      color: #999;
      margin-top: 12px;
    }
    
    /* お問い合わせボタン */
    .btn-contact {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      width: 100%;
      padding: 18px;
      background: #000;
      color: #fff;
      text-align: center;
      border-radius: 9999px;
      text-decoration: none;
      font-size: 16px;
      font-weight: 600;
      border: none;
      margin-top: 8px;
    }
    .btn-contact:active {
      opacity: 0.7;
    }
    
    /* フッター */
    .footer {
      text-align: center;
      margin-top: 48px;
    }
    .footer a {
      color: #BBB;
      font-size: 12px;
      text-decoration: none;
    }
    
    [data-lucide] { 
      width: 18px; 
      height: 18px; 
      transition: transform 0.25s ease;
      flex-shrink: 0;
    }
    .icon-rotated {
      transform: rotate(180deg);
    }
  </style>
</head>
<body>
  <h1>システムステータス</h1>
  
  <div class="section">
    <div class="section-title">サービス状態</div>
    <div class="status-list">
      <?php foreach($systems as $sys): 
        $dotClass = [
          '正常' => 'dot-normal',
          '不具合あり' => 'dot-error', 
          'メンテナンス中' => 'dot-maintenance',
          '停止中' => 'dot-stopped',
          '開発中' => 'dot-dev'
        ][$sys['status']];
      ?>
      <div class="status-item">
        <div class="status-header" onclick="toggleStatus(this)">
          <div class="status-left">
            <span class="status-dot <?= $dotClass ?>"></span>
            <span><?= htmlspecialchars($sys['name']) ?></span>
          </div>
          <div class="status-right">
            <span><?= htmlspecialchars($sys['status']) ?></span>
            <i data-lucide="chevron-down"></i>
          </div>
        </div>
        <div class="status-detail">
          <div class="status-detail-inner">
            <div class="detail-label">最終更新</div>
            <div><?= htmlspecialchars($sys['updated'] ?? '-') ?></div>
            <?php if(!empty($sys['note'])): ?>
            <div style="margin-top:12px;">
              <div class="detail-label">備考</div>
              <div><?= nl2br(htmlspecialchars($sys['note'])) ?></div>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="section">
    <div class="section-title">リリースノート</div>
    <div class="release-list">
      <?php foreach($releases as $rel): ?>
      <div class="release-item">
        <div class="release-header" onclick="toggleRelease(this)">
          <div class="release-top">
            <div style="flex:1;">
              <div class="release-version"><?= htmlspecialchars($rel['version']) ?></div>
              <div class="release-summary"><?= htmlspecialchars($rel['summary']) ?></div>
            </div>
            <i data-lucide="chevron-down"></i>
          </div>
        </div>
        <div class="release-detail">
          <div class="release-detail-inner">
            <?= nl2br(htmlspecialchars($rel['detail'])) ?>
            <div class="release-date"><?= htmlspecialchars($rel['date']) ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <a href="/satellite/buddies/form" class="btn-contact">
    <i data-lucide="mail"></i>
    お問い合わせ
  </a>

  <div class="footer">
    <a href="admin.php">管理者ログイン</a>
  </div>

  <script>
    lucide.createIcons();
    
    function toggleStatus(el) {
      const item = el.closest('.status-item');
      const detail = item.querySelector('.status-detail');
      const icon = el.querySelector('[data-lucide]');
      detail.classList.toggle('open');
      icon.classList.toggle('icon-rotated');
    }
    
    function toggleRelease(el) {
      const item = el.closest('.release-item');
      const detail = item.querySelector('.release-detail');
      const icon = el.querySelector('[data-lucide]');
      detail.classList.toggle('open');
      icon.classList.toggle('icon-rotated');
    }
  </script>
</body>
</html>
