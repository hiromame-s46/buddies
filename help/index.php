<?php
header('Content-Type: text/html; charset=utf-8');
$data = file_exists('data.json') ? file_get_contents('data.json') : '{"categories":[]}';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>ヘルプセンター</title>
<script src="https://unpkg.com/lucide@latest"></script>
<style>
:root{--fg:#111;--muted:#666;--line:#e5e5e5}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{font-size:16px}
body{margin:0;background:#f6f6f6;color:var(--fg);font-family:-apple-system,BlinkMacSystemFont,"Hiragino Kaku Gothic ProN",Meiryo,sans-serif;-webkit-text-size-adjust:100%}
button,a{font-family:inherit;color:inherit;text-decoration:none;touch-action:manipulation}
button{border:0;background:none;padding:0;cursor:pointer}
.app{max-width:520px;margin:0 auto;min-height:100dvh;background:#fff;display:flex;flex-direction:column}
.header{position:sticky;top:0;z-index:10;background:#fff;border-bottom:1px solid var(--line);padding:14px 16px;padding-top:calc(14px + env(safe-area-inset-top))}
.title{font-size:22px;font-weight:800;margin:0}
.search-wrap{position:relative;margin-top:12px}
.search-ico{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#999;pointer-events:none}
.search{width:100%;height:50px;padding:0 44px 0 40px;font-size:16px;border:1px solid var(--line);border-radius:9999px;background:#fafafa;outline:none}
.search:focus{background:#fff;border-color:#ddd}
.search-clear{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:36px;height:36px;border-radius:9999px;display:none;place-items:center;color:#666}
.search-clear.show{display:grid}
.main{flex:1;padding:14px 16px 24px}
.sec{margin:20px 0 8px;font-size:13px;font-weight:700;color:var(--muted);letter-spacing:.04em}
.item{width:100%;display:flex;align-items:flex-start;gap:12px;padding:14px;border:1px solid var(--line);border-radius:16px;background:#fff;margin:0 0 10px;text-align:left}
.item:active{background:#f9f9f9}
.item-ico{width:40px;height:40px;border-radius:12px;background:#f7f7f7;border:1px solid var(--line);display:grid;place-items:center;flex-shrink:0;color:#444;margin-top:2px}
.item-txt{flex:1;min-width:0}
.item-t{font-size:16px;font-weight:600;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;white-space:normal;word-break:break-word}
.item-d{font-size:13px;color:var(--muted);margin-top:4px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;white-space:normal;word-break:break-word}
.chev{color:#bbb;flex-shrink:0;margin-top:8px}
.empty{padding:60px 20px;text-align:center;color:#777}

/* 記事 */
.article{position:fixed;inset:0;z-index:50;background:#fff;max-width:520px;margin:0 auto;display:flex;flex-direction:column;transform:translateY(100%);transition:transform .28s;visibility:hidden}
.article.on{transform:translateY(0);visibility:visible}
.a-head{position:sticky;top:0;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:8px;padding:10px;padding-top:calc(10px + env(safe-area-inset-top))}
.back{width:44px;height:44px;display:grid;place-items:center;border-radius:12px;border:1px solid var(--line)}
.a-title{font-weight:700;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.a-body{flex:1;overflow:auto;padding:22px 18px;font-size:16px;line-height:1.9}
.a-foot{padding:14px 16px;padding-bottom:calc(14px + env(safe-area-inset-bottom));border-top:1px solid var(--line);background:#fff}
.btn-primary{width:100%;height:52px;border-radius:9999px;background:#111;color:#fff;font-weight:700;font-size:16px;display:flex;align-items:center;justify-content:center;gap:8px}

/* お問い合わせ */
.scrim{position:fixed;inset:0;background:rgba(0,0,0,0);transition:.2s;pointer-events:none;z-index:60}
.scrim.on{background:rgba(0,0,0,.4);pointer-events:auto}
.sheet{position:fixed;left:0;right:0;bottom:0;max-width:520px;margin:0 auto;background:#fff;border-radius:24px 24px 0 0;border:1px solid var(--line);border-bottom:0;transform:translateY(110%);transition:.28s;z-index:70}
.scrim.on .sheet{transform:translateY(0)}
.sheet-h{width:40px;height:4px;background:#e5e5e5;border-radius:2px;margin:10px auto}
.sheet-t{font-weight:800;text-align:center;padding:4px 0 12px;color:#111}
.opt{width:calc(100% - 28px);margin:0 14px 10px;display:flex;align-items:center;gap:12px;padding:14px;border:1px solid var(--line);border-radius:14px;background:#fff;text-align:left;color:#111}
.opt:active{background:#f9f9f9}
.opt-ico{width:40px;height:40px;border-radius:12px;background:#f7f7f7;border:1px solid var(--line);display:grid;place-items:center;color:#111}
.cancel{margin:6px 14px calc(14px + env(safe-area-inset-bottom));width:calc(100% - 28px);height:48px;border-radius:12px;border:1px solid var(--line);background:#fff;font-weight:600;color:#111}
.admin-link{display:block;text-align:center;padding:40px 0 20px;font-size:12px;color:#ccc}
.admin-link a{color:#ccc}
</style>
</head>
<body>
<div class="app">
  <header class="header">
    <h1 class="title">ヘルプセンター</h1>
    <div class="search-wrap">
      <span class="search-ico"><i data-lucide="search" width="18" height="18"></i></span>
      <input id="q" class="search" type="search" inputmode="search" placeholder="キーワードで検索" autocomplete="off">
      <button id="clear" class="search-clear" aria-label="クリア"><i data-lucide="x" width="18" height="18"></i></button>
    </div>
  </header>
  <main class="main" id="list"></main>
  <a href="admin.php" class="admin-link">管理</a>
</div>

<div id="article" class="article" aria-hidden="true">
  <div class="a-head">
    <button class="back" onclick="closeA()" aria-label="戻る"><i data-lucide="chevron-left" width="24" height="24"></i></button>
    <div class="a-title" id="aTitle"></div>
  </div>
  <div class="a-body" id="aBody"></div>
  <div class="a-foot">
    <button class="btn-primary" onclick="openContact()"><i data-lucide="message-circle" width="18" height="18"></i>お問い合わせ</button>
  </div>
</div>

<div id="scrim" class="scrim" onclick="closeContact()">
  <div class="sheet" onclick="event.stopPropagation()">
    <div class="sheet-h"></div>
    <div class="sheet-t">お問い合わせ</div>
    <a class="opt" href="../form/">
      <span class="opt-ico"><i data-lucide="mail" width="20" height="20"></i></span>
      <span><div style="font-weight:600">フォームで問い合わせ</div><div style="font-size:12px;color:#666">お問い合わせフォームを開きます</div></span>
    </a>
    <a class="opt" href="https://x.com/hiromame_sakura" target="_blank" rel="noopener">
      <span class="opt-ico">
        <!-- Xアイコンを直接埋め込み -->
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
      </span>
      <span><div style="font-weight:600">XでDMする</div><div style="font-size:12px;color:#666">@hiromame_sakura</div></span>
    </a>
    <button class="cancel" onclick="closeContact()">キャンセル</button>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script>
const DATA = <?= $data ?>;
const list = document.getElementById('list');
const q = document.getElementById('q');
const clear = document.getElementById('clear');
const article = document.getElementById('article');
const aBody = document.getElementById('aBody');
const aTitle = document.getElementById('aTitle');

const items = DATA.categories.flatMap(c => c.items.map(i => ({...i, cat:c.title})));

function nl2br(s){ return (s||'').replace(/\n/g,'<br>'); }

function render(filter=''){
  list.innerHTML='';
  const f = filter.trim().toLowerCase();
  const groups = {};
  items.forEach(it=>{
    if(!f || (it.title+it.desc+it.body).toLowerCase().includes(f)){
      (groups[it.cat] ||= []).push(it);
    }
  });
  if(Object.keys(groups).length===0){
    list.innerHTML = `<div class="empty">見つかりませんでした</div>`; return;
  }
  for(const [cat, arr] of Object.entries(groups)){
    const sec = document.createElement('div');
    sec.innerHTML = `<div class="sec">${cat}</div>`;
    arr.forEach(it=>{
      const b = document.createElement('button');
      b.className='item'; b.type='button';
      b.innerHTML = `<span class="item-ico"><i data-lucide="help-circle" width="20" height="20"></i></span><span class="item-txt"><span class="item-t">${nl2br(it.title)}</span><span class="item-d">${nl2br(it.desc||'')}</span></span><span class="chev"><i data-lucide="chevron-right" width="20" height="20"></i></span>`;
      b.onclick = ()=>open(it);
      sec.appendChild(b);
    });
    list.appendChild(sec);
  }
  lucide.createIcons();
}
function open(it){
  aTitle.textContent = it.title;
  aBody.innerHTML = marked.parse(it.body||'');
  article.classList.add('on');
  document.body.style.overflow='hidden';
}
function closeA(){ article.classList.remove('on'); document.body.style.overflow=''; }
function openContact(){ document.getElementById('scrim').classList.add('on'); }
function closeContact(){ document.getElementById('scrim').classList.remove('on'); }

q.addEventListener('input', ()=>{ clear.classList.toggle('show', q.value.length>0); render(q.value); });
clear.onclick = ()=>{ q.value=''; clear.classList.remove('show'); render(''); q.focus(); };

render();
lucide.createIcons();
</script>
</body>
</html>