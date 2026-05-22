<?php
session_start();
if (!isset($_SESSION['admin'])) {
  header('HTTP/1.1 403 Forbidden');
  exit('権限がありません');
}

$jsonFile = __DIR__ . '/data.json';

// ファイル存在チェック＆初期化
if (!file_exists($jsonFile)) {
  file_put_contents($jsonFile, json_encode(['systems'=>[], 'releases'=>[]], JSON_UNESCAPED_UNICODE));
}

// 読み込み
$json = file_get_contents($jsonFile);
$data = json_decode($json, true);

if (json_last_error() !== JSON_ERROR_NONE) {
  die('JSONの解析に失敗: ' . json_last_error_msg());
}

$action = $_POST['action'] ?? '';

switch($action) {
  case 'add_system':
    $data['systems'][] = [
      'name' => trim($_POST['name']),
      'status' => $_POST['status'],
      'note' => trim($_POST['note']),
      'updated' => date('Y/m/d H:i')
    ];
    break;
    
  case 'update_system':
    $i = (int)$_POST['index'];
    if (isset($data['systems'][$i])) {
      $data['systems'][$i] = [
        'name' => trim($_POST['name']),
        'status' => $_POST['status'],
        'note' => trim($_POST['note']),
        'updated' => date('Y/m/d H:i')
      ];
    }
    break;
    
  case 'delete_system':
    array_splice($data['systems'], (int)$_POST['index'], 1);
    break;
    
  case 'add_release':
    array_unshift($data['releases'], [
      'version' => trim($_POST['version']),
      'summary' => trim($_POST['summary']),
      'detail' => trim($_POST['detail']),
      'date' => $_POST['date']
    ]);
    break;
    
  case 'update_release':
    $i = (int)$_POST['index'];
    if (isset($data['releases'][$i])) {
      $data['releases'][$i] = [
        'version' => trim($_POST['version']),
        'summary' => trim($_POST['summary']),
        'detail' => trim($_POST['detail']),
        'date' => $_POST['date']
      ];
    }
    break;
    
  case 'delete_release':
    array_splice($data['releases'], (int)$_POST['index'], 1);
    break;
}

// 書き込み - エラーハンドリング強化
$newJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($newJson === false) {
  die('JSONエンコード失敗: ' . json_last_error_msg());
}

if (file_put_contents($jsonFile, $newJson, LOCK_EX) === false) {
  die('ファイル書き込み失敗。data.jsonのパーミッションを確認してください: chmod 666 data.json');
}

header('Location: admin.php?success=1');
exit;