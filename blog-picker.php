<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300, stale-while-revalidate=600');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function loadBlogsForPicker(): array {
    $path = __DIR__ . '/../data/blogs.json';
    if (!is_file($path) || !is_readable($path)) respond(['error' => 'ブログデータを読み込めません。'], 500);

    $version = (string)(filemtime($path) ?: 0) . ':' . (string)(filesize($path) ?: 0);
    $cacheKey = 'buddies_blog_picker_' . md5($path . ':' . $version);
    if (function_exists('apcu_fetch')) {
        $cached = @apcu_fetch($cacheKey, $hit);
        if ($hit && is_array($cached)) return $cached;
    }

    $json = file_get_contents($path);
    $blogs = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($blogs)) respond(['error' => 'ブログデータが正しくありません。'], 500);
    if (function_exists('apcu_store')) @apcu_store($cacheKey, $blogs, 900);
    return $blogs;
}

function pickerTextContains(string $text, string $query): bool {
    if ($query === '') return true;
    if (function_exists('mb_stripos')) return mb_stripos($text, $query, 0, 'UTF-8') !== false;
    return stripos($text, $query) !== false;
}

function pickerDateKey(string $value): string {
    if (!preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', trim($value), $m)) return '';
    return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
}

$blogs = loadBlogsForPicker();
$mode = trim((string)($_GET['mode'] ?? 'list'));

if ($mode === 'date') {
    $date = pickerDateKey((string)($_GET['date'] ?? ''));
    if ($date === '') respond(['error' => '日付が正しくありません。'], 400);
    $items = [];
    foreach ($blogs as $id => $blog) {
        if (!is_array($blog) || pickerDateKey((string)($blog['date'] ?? '')) !== $date) continue;
        $images = is_array($blog['images'] ?? null) ? $blog['images'] : [];
        $items[] = [
            'id' => (int)$id,
            'link' => (string)($blog['link'] ?? ''),
            'member' => (string)($blog['member'] ?? ''),
            'date' => (string)($blog['date'] ?? ''),
            'title' => (string)($blog['title'] ?? ''),
            'thumb' => (string)($blog['thumb'] ?? ($images[0] ?? '')),
        ];
    }
    respond(['items' => $items]);
}

if ($mode === 'detail') {
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($id === false || !isset($blogs[$id]) || !is_array($blogs[$id])) respond(['error' => 'ブログが見つかりません。'], 404);
    $blog = $blogs[$id];
    $images = array_values(array_filter(array_map('strval', is_array($blog['images'] ?? null) ? $blog['images'] : [])));
    if (!$images && !empty($blog['thumb'])) $images[] = (string)$blog['thumb'];
    respond(['blog' => [
        'id' => (int)$id,
        'link' => (string)($blog['link'] ?? ''),
        'member' => (string)($blog['member'] ?? ''),
        'date' => (string)($blog['date'] ?? ''),
        'title' => (string)($blog['title'] ?? ''),
        'thumb' => (string)($blog['thumb'] ?? ''),
        'images' => array_slice($images, 0, 10),
    ]]);
}

$offset = max(0, (int)($_GET['offset'] ?? 0));
$limit = min(30, max(1, (int)($_GET['limit'] ?? 20)));
$query = trim((string)($_GET['q'] ?? ''));
if (function_exists('mb_substr')) $query = mb_substr($query, 0, 100, 'UTF-8');
else $query = substr($query, 0, 100);
$items = [];
$matched = 0;

foreach ($blogs as $id => $blog) {
    if (!is_array($blog)) continue;
    $haystack = implode(' ', [(string)($blog['member'] ?? ''), (string)($blog['title'] ?? ''), (string)($blog['date'] ?? '')]);
    if (!pickerTextContains($haystack, $query)) continue;
    if ($matched++ < $offset) continue;
    $images = is_array($blog['images'] ?? null) ? $blog['images'] : [];
    $thumb = (string)($blog['thumb'] ?? ($images[0] ?? ''));
    $items[] = [
        'id' => (int)$id,
        'member' => (string)($blog['member'] ?? ''),
        'date' => (string)($blog['date'] ?? ''),
        'title' => (string)($blog['title'] ?? ''),
        'thumb' => $thumb,
    ];
    if (count($items) > $limit) break;
}

$hasMore = count($items) > $limit;
if ($hasMore) array_pop($items);
respond([
    'items' => $items,
    'next_offset' => $offset + count($items),
    'has_more' => $hasMore,
]);
