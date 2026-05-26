<?php
session_start();
$ADMIN_PASS = '447686';

if(isset($_POST['logout'])){ session_destroy(); header('Location: admin.php'); exit; }
if(isset($_POST['pass'])){ if($_POST['pass']===$ADMIN_PASS){ $_SESSION['admin']=1; } else { $err='パスワードが違います'; } }

$dataFile='data.json';
if(!file_exists($dataFile)) file_put_contents($dataFile, json_encode(['categories'=>[]],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

if(isset($_POST['action']) && !empty($_SESSION['admin'])){
  $data = json_decode(file_get_contents($dataFile), true);
  $action = $_POST['action'];
  
  if($action==='save_item'){
    $cat = trim($_POST['category']);
    $id = trim($_POST['id']) ?: 'a'.time();
    $item = ['id'=>$id,'title'=>trim($_POST['title']),'desc'=>trim($_POST['desc']),'body'=>$_POST['body']];
    $found=false;
    foreach($data['categories'] as &$c){
      if($c['title']===$cat){
        $found=true;
        $idx = array_search($id, array_column($c['items'],'id'));
        if($idx!==false) $c['items'][$idx]=$item; else $c['items'][]=$item;
      }
    }
    if(!$found) $data['categories'][]=['title'=>$cat,'items'=>[$item]];
    file_put_contents($dataFile, json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    $saved=1;
  }
  if($action==='delete_item'){
    $id=$_POST['id']; $cat=$_POST['category'];
    foreach($data['categories'] as &$c){
      if($c['title']===$cat){ $c['items']=array_values(array_filter($c['items'],fn($i)=>$i['id']!==$id)); }
    }
    $data['categories']=array_values(array_filter($data['categories'],fn($c)=>count($c['items'])>0));
    file_put_contents($dataFile, json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    $deleted=1;
  }
}

$data = json_decode(file_get_contents($dataFile), true);
if(empty($_SESSION['admin'])): ?>
<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ログイン</title>
<style>body{margin:0;font-family:-apple-system,sans-serif;background:#f6f6f6;display:grid;place-items:center;min-height:100vh}form{background:#fff;padding:24px;border-radius:16px;width:90%;max-width:340px}input{width:100%;height:48px;font-size:16px;padding:0 12px;border:1px solid #ddd;border-radius:12px}button{width:100%;height:48px;margin-top:12px;border-radius:9999px;background:#111;color:#fff;border:1px solid #111;font-weight:600;font-size:16px}</style>
</head><body>
<form method="post"><h2 style="margin:0 0 16px">管理者ログイン</h2><?php if(!empty($err)) echo "<p style=color:#d00>$err</p>";?><input type="password" name="pass" placeholder="パスワード" autofocus><button>ログイン</button></form>
</body></html><?php exit; endif; ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>ヘルプ編集</title>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<style>
:root{--line:#e5e5e5}
*{box-sizing:border-box}html,body{height:100%}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f6f6f6;color:#111;-webkit-text-size-adjust:100%}
button,input,textarea,select{font-family:inherit;font-size:16px;color:#111}
button{cursor:pointer;touch-action:manipulation;border:0;background:none;padding:0}
.app{max-width:720px;margin:0 auto;min-height:100vh;background:#fff}
header{position:sticky;top:0;background:#fff;border-bottom:1px solid var(--line);padding:12px 16px;padding-top:calc(12px + env(safe-area-inset-top));display:flex;align-items:center;gap:10px;z-index:10}
h1{font-size:18px;margin:0;flex:1}
.btn{height:40px;padding:0 14px;border-radius:9999px;border:1px solid var(--line);background:#fff;font-weight:600;display:inline-flex;align-items:center;gap:6px;color:#111}
.btn-primary{background:#111;color:#fff;border-color:#111}
.btn:active{opacity:.9}
main{padding:16px;padding-bottom:calc(80px + env(safe-area-inset-bottom))}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:14px;margin-bottom:12px}
.cat{font-size:13px;font-weight:700;color:#666;margin:0 0 8px;text-transform:uppercase;letter-spacing:.04em}
.item{display:flex;align-items:center;gap:10px;padding:12px;border:1px solid var(--line);border-radius:12px;margin-bottom:8px;background:#fff}
.item-main{flex:1;min-width:0}
.item-t{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.item-d{font-size:13px;color:#666;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.item-act{display:flex;gap:6px}
.small{width:36px;height:36px;display:grid;place-items:center;border-radius:10px;border:1px solid var(--line);background:#fff;color:#111}

.form{position:fixed;inset:0;background:#fff;z-index:50;display:flex;flex-direction:column;transform:translateY(100%);transition:.28s}
.form.on{transform:translateY(0)}
.f-head{padding:12px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:8px;padding-top:calc(12px + env(safe-area-inset-top))}
.f-title{flex:1;font-weight:700}
.f-body{flex:1;overflow:auto;padding:16px}
.field{margin-bottom:16px}
.label{font-size:13px;font-weight:600;color:#444;margin-bottom:6px;display:block}
.input,select,textarea{width:100%;padding:12px;border:1px solid var(--line);border-radius:12px;background:#fff;outline:none}
textarea{min-height:180px;line-height:1.6;font-family:ui-monospace,monospace}
.toolbar{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0}
.tool{height:36px;padding:0 10px;border-radius:10px;border:1px solid var(--line);background:#f9f9f9;font-size:14px;color:#111}
.preview{border:1px dashed var(--line);border-radius:12px;padding:12px;min-height:80px;background:#fafafa;font-size:15px}
.f-foot{padding:12px 16px;border-top:1px solid var(--line);background:#fff;padding-bottom:calc(12px + env(safe-area-inset-bottom));display:flex;gap:8px}
.f-foot .btn{flex:1;height:48px;justify-content:center}
.notice{padding:10px 14px;background:#e8f5e9;border:1px solid #c8e6c9;color:#1b5e20;border-radius:12px;margin-bottom:12px;font-size:14px}
</style>
</head>
<body>
<div class="app">
<header>
  <h1>ヘルプ編集</h1>
  <button class="btn" onclick="openForm()"><i data-lucide="plus" width="16" height="16"></i>新規</button>
  <form method="post" style="margin:0"><button name="logout" class="btn">ログアウト</button></form>
</header>
<main>
  <?php if(!empty($saved)) echo '<div class="notice">保存しました</div>'; if(!empty($deleted)) echo '<div class="notice">削除しました</div>'; ?>
  <div id="list">
  <?php foreach($data['categories'] as $cat): ?>
    <div class="card">
      <div class="cat"><?=htmlspecialchars($cat['title'])?></div>
      <?php foreach($cat['items'] as $it): ?>
        <div class="item">
          <div class="item-main">
            <div class="item-t"><?=htmlspecialchars($it['title'])?></div>
            <div class="item-d"><?=htmlspecialchars($it['desc']??'')?></div>
          </div>
          <div class="item-act">
            <button class="small" onclick='editItem(<?=json_encode($it,JSON_HEX_APOS|JSON_HEX_QUOT)?>,<?=json_encode($cat['title'])?>)' aria-label="編集"><i data-lucide="pencil" width="16" height="16"></i></button>
            <button class="small" onclick="delItem('<?=htmlspecialchars($it['id'])?>','<?=htmlspecialchars($cat['title'])?>')" aria-label="削除"><i data-lucide="trash-2" width="16" height="16"></i></button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; if(empty($data['categories'])) echo '<p style="text-align:center;color:#777;padding:40px 0">記事がありません。「新規」から追加してください</p>'; ?>
  </div>
</main>
</div>

<div id="form" class="form">
  <div class="f-head">
    <button class="small" onclick="closeForm()"><i data-lucide="x" width="20" height="20"></i></button>
    <div class="f-title" id="fTitle">新規記事</div>
  </div>
  <form method="post" class="f-body" id="itemForm" onsubmit="return beforeSave()">
    <input type="hidden" name="action" value="save_item">
    <input type="hidden" name="id" id="fId">
    <div class="field">
      <label class="label">カテゴリ</label>
      <input class="input" list="cats" name="category" id="fCat" placeholder="例: はじめに" required>
      <datalist id="cats"><?php foreach($data['categories'] as $c) echo '<option value="'.htmlspecialchars($c['title']).'">'; ?></datalist>
    </div>
    <div class="field">
      <label class="label">タイトル</label>
      <input class="input" name="title" id="fTitleInput" placeholder="例: ログインできない" required>
    </div>
    <div class="field">
      <label class="label">説明（短い要約）</label>
      <input class="input" name="desc" id="fDesc" placeholder="例: パスワードを忘れた場合">
    </div>
    <div class="field">
      <label class="label">本文（マークダウン）</label>
      <div class="toolbar">
        <button type="button" class="tool" onclick="md('**','**')">太字</button>
        <button type="button" class="tool" onclick="md('*','*')">斜体</button>
        <button type="button" class="tool" onclick="md('\n## ','')">見出し</button>
        <button type="button" class="tool" onclick="md('\n- ','')">リスト</button>
        <button type="button" class="tool" onclick="md('[','](https://)')">リンク</button>
        <button type="button" class="tool" onclick="md('`','`')">コード</button>
        <button type="button" class="tool" onclick="md('\n> ','')">引用</button>
      </div>
      <textarea name="body" id="fBody" placeholder="ここにマークダウンで入力"></textarea>
      <div style="font-size:12px;color:#666;margin:6px 0 4px">プレビュー</div>
      <div class="preview" id="preview"></div>
    </div>
  </form>
  <div class="f-foot">
    <button class="btn" onclick="closeForm()">キャンセル</button>
    <button class="btn btn-primary" onclick="document.getElementById('itemForm').requestSubmit()">保存</button>
  </div>
</div>

<form id="delForm" method="post" style="display:none">
  <input type="hidden" name="action" value="delete_item">
  <input type="hidden" name="id" id="delId">
  <input type="hidden" name="category" id="delCat">
</form>

<script>
lucide.createIcons();
const form = document.getElementById('form');
const fId = document.getElementById('fId');
const fCat = document.getElementById('fCat');
const fTitle = document.getElementById('fTitleInput');
const fDesc = document.getElementById('fDesc');
const fBody = document.getElementById('fBody');
const preview = document.getElementById('preview');

function openForm(){ form.classList.add('on'); document.body.style.overflow='hidden'; setTimeout(()=>fTitle.focus(),100); }
function closeForm(){ form.classList.remove('on'); document.body.style.overflow=''; }
function editItem(it,cat){
  document.getElementById('fTitle').textContent='記事を編集';
  fId.value=it.id; fCat.value=cat; fTitle.value=it.title; fDesc.value=it.desc||''; fBody.value=it.body||'';
  updatePreview(); openForm();
}
function delItem(id,cat){
  if(!confirm('削除しますか？')) return;
  document.getElementById('delId').value=id;
  document.getElementById('delCat').value=cat;
  document.getElementById('delForm').submit();
}
function md(a,b){ const s=fBody.selectionStart, e=fBody.selectionEnd, t=fBody.value; fBody.value=t.slice(0,s)+a+t.slice(s,e)+b+t.slice(e); fBody.focus(); fBody.selectionStart=s+a.length; fBody.selectionEnd=e+a.length; updatePreview(); }
function updatePreview(){ preview.innerHTML = marked.parse(fBody.value||'*プレビューがここに表示されます*'); }
fBody.addEventListener('input', updatePreview);
function beforeSave(){ if(!fCat.value.trim()||!fTitle.value.trim()){ alert('カテゴリとタイトルは必須です'); return false;} return true; }
function openFormNew(){ document.getElementById('fTitle').textContent='新規記事'; fId.value=''; fCat.value=''; fTitle.value=''; fDesc.value=''; fBody.value=''; updatePreview(); openForm(); }
document.querySelector('header .btn').onclick = openFormNew;
updatePreview();
</script>
</body>
</html>