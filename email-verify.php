<?php
$token = (string)($_GET['token'] ?? '');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
?><!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>メールアドレス認証 | Buddies</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100dvh;display:grid;place-items:center;padding:20px;background:#f7f7f7;color:#111;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Kaku Gothic ProN","Yu Gothic",Meiryo,sans-serif}.card{width:min(100%,420px);padding:30px 24px;border:1px solid #eee;border-radius:24px;background:#fff;text-align:center;box-shadow:0 8px 26px rgba(0,0,0,.05)}h1{margin:0 0 10px;font-size:22px}p{color:#666;font-size:14px;line-height:1.8}a,button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 18px;border-radius:9999px;border:1px solid #111;background:#111;color:#fff;text-decoration:none;font:inherit;font-size:13px;font-weight:700;cursor:pointer}.error{color:#c62828}.loading{color:#888}
</style>
</head>
<body>
<main class="card">
  <h1 id="title">メールアドレスを認証しています</h1>
  <p id="message" class="loading">しばらくお待ちください。</p>
  <a id="back" href="./" hidden>Buddiesへ戻る</a>
</main>
<script>
const token = <?= json_encode($token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const title = document.getElementById('title');
const message = document.getElementById('message');
const back = document.getElementById('back');
async function verify(){
  if(!/^[a-f0-9]{64}$/i.test(token)){title.textContent='認証できませんでした';message.textContent='認証リンクが正しくありません。';message.className='error';back.hidden=false;return;}
  try{
    const res=await fetch('./api.php?action=buddies_email_verify&token='+encodeURIComponent(token),{credentials:'include'});
    const json=await res.json();
    if(!json.ok)throw new Error(json.error||'認証リンクが無効または期限切れです。');
    title.textContent='メール認証が完了しました';message.textContent=(json.data?.email||'メールアドレス')+'を認証しました。';message.className='';back.hidden=false;
  }catch(e){title.textContent='認証できませんでした';message.textContent=e.message||'認証リンクが無効または期限切れです。';message.className='error';back.hidden=false;}
}
verify();
</script>
</body>
</html>
