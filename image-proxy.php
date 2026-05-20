<?php
declare(strict_types=1);

$url = trim((string)($_GET['url'] ?? ''));
if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit;
}

$host = parse_url($url, PHP_URL_HOST) ?: '';
$allowedHosts = [
    'sakurazaka46.com',
    'cdn.sakurazaka46.com',
    'buddies46.stars.ne.jp',
];
$allowed = false;
foreach ($allowedHosts as $allowedHost) {
    if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    http_response_code(403);
    exit;
}

$ctx = stream_context_create([
    'http' => [
        'timeout' => 5,
        'user_agent' => 'Buddies image proxy',
    ],
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
]);
$data = @file_get_contents($url, false, $ctx);
if (!$data) {
    http_response_code(404);
    exit;
}

$info = @getimagesizefromstring($data);
if (!$info || empty($info['mime']) || !str_starts_with($info['mime'], 'image/')) {
    http_response_code(415);
    exit;
}

header('Content-Type: ' . $info['mime']);
header('Cache-Control: public, max-age=86400');
header('Access-Control-Allow-Origin: *');
echo $data;
