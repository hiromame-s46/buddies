<?php
declare(strict_types=1);
require __DIR__ . '/og-card-lib.php';

$uid = (int)($_GET['uid'] ?? 0);
$profile = buddies_profile($uid);
if (!$profile) {
    http_response_code(404);
    header('Content-Type: image/png');
    $im = imagecreatetruecolor(1200, 630);
    imagefilledrectangle($im, 0, 0, 1200, 630, buddies_rgb($im, '#fff9f3'));
    buddies_text($im, 42, 356, 300, 'Buddies profile', buddies_rgb($im, '#111111'));
    buddies_text($im, 24, 376, 350, 'プロフィールが見つかりませんでした', buddies_rgb($im, '#666666'));
    imagepng($im);
    exit;
}

$im = buddies_render_card($profile);
$download = !empty($_GET['download']);
header('Content-Type: image/png');
header('Cache-Control: public, max-age=900');
if ($download) {
    $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $profile['username'] ?: 'buddies');
    header('Content-Disposition: attachment; filename="buddies-profile-' . $name . '.png"');
}
imagepng($im);
