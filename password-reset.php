<?php
$token = (string)($_GET['token'] ?? '');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
?><!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>パスワードリセット | Buddies</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100dvh;display:grid;place-items:center;padding:20px;background:#f7f7f7;color:#111;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Kaku Gothic ProN","Yu Gothic",Meiryo,sans-serif}.card{width:min(100%,420px);padding:30px 24px;border:1px solid #eee;border-radius:24px;background:#fff;box-shadow:0 8px 26px rgba(0,0,0,.05)}h1{margin:0 0 8px;text-align:center;font-size:22px}p{color:#666;font-size:13px;line-height:1.7;text-align:center}.field{margin:16px 0}.field label{display:block;margin-bottom:5px;color:#666;font-size:12px;font-weight:700}.field input{width:100%;min-height:46px;border:1px solid #ddd;border-radius:13px;padding:10px 14px;font:inherit;font-size:16px}.submit{width:100%;min-height:46px;border:0;border-radius:9999px;background:#111;color:#fff;font:inherit;font-size:14px;font-weight:800;cursor:pointer}.submit:disabled{opacity:.5}.message{display:none;margin-top:12px;padding:10px 11px;border-radius:12px;background:#fff5f5;color:#c62828;font-size:12px;line-height:1.6;text-align:center}.message.show{display:block}.success{background:#f0fff4;color:#287442}.back{display:block;margin-top:16px;text-align:center;color:#555;font-size:12px}
</style>
</head>
<body>
<main class="card">
  <h1>パスワードを再設定</h1>
  <p>新しいパスワードを入力してください。このリンクは30分間有効です。</p>
  <form id="form">
    <div class="field"><label for="password">新しいパスワード（8〜256文字）</label><input id="password" type="password" minlength="8" maxlength="256" autocomplete="new-password" required></div>
    <div class="field"><label for="password2">新しいパスワード（確認）</label><input id="password2" type="password" minlength="8" maxlength="256" autocomplete="new-password" required></div>
    <button class="submit" type="submit">パスワードを変更する</button>
  </form>
  <div id="message" class="message"></div>
  <a class="back" href="./">Buddiesへ戻る</a>
</main>
<script>
const token = <?= json_encode($token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const form=document.getElementById('form'), message=document.getElementById('message'), button=form.querySelector('button');
function showMessage(text,success=false){message.textContent=text;message.className='message show'+(success?' success':'');}
form.addEventListener('submit',async event=>{
  event.preventDefault();
  const password=document.getElementById('password').value, password2=document.getElementById('password2').value;
  if(!/^[a-f0-9]{64}$/i.test(token))return showMessage('リセットリンクが正しくありません。');
  if(password.length<8||password.length>256)return showMessage('パスワードは8〜256文字で入力してください。');
  if(password!==password2)return showMessage('パスワードが一致しません。');
  button.disabled=true;button.textContent='変更中…';
  try{
    const res=await fetch('./api.php?action=buddies_password_reset',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify({token,new_password:password})});
    const json=await res.json();
    if(!json.ok)throw new Error(json.error||'パスワードを変更できませんでした。');
    form.remove();showMessage(json.data?.message||'パスワードを変更しました。新しいパスワードでログインしてください。',true);
  }catch(e){showMessage(e.message||'通信エラーが発生しました。');button.disabled=false;button.textContent='パスワードを変更する';}
});
</script>
</body>
</html>
