<?php
declare(strict_types=1);

const BUDDIES_BASE_URL = 'https://buddies46.stars.ne.jp/satellite/buddies/';
const BUDDIES_MEMBER_DATA_PATH = __DIR__ . '/../data/member.json';

function buddies_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $config = require __DIR__ . '/../../../api/config.php';
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['dbname']);
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    return $pdo;
}

function buddies_calc_age(?string $birthday): ?int {
    if (!$birthday) return null;
    $ts = strtotime($birthday);
    if (!$ts) return null;
    $bd  = new DateTime(date('Y-m-d', $ts));
    $now = new DateTime('today');
    if ($bd > $now) return null;
    return $now->diff($bd)->y;
}

function buddies_profile(int $uid): ?array {
    if ($uid <= 0) return null;
    $st = buddies_db()->prepare(
        'SELECT u.id, u.username, u.display_name, u.oshi_member, u.oshi_member_2, u.oshi_member_3,
                u.user_icon,
                bp.birthday, bp.age, bp.gender, bp.location, bp.buddies_since, bp.bio,
                bp.tags, bp.favorite_songs, bp.sns_links, bp.follow_stance
         FROM sakulabo_users u
         LEFT JOIN buddies_profiles bp ON bp.user_id = u.id
         WHERE u.id = ? LIMIT 1'
    );
    $st->execute([$uid]);
    $r = $st->fetch();
    if (!$r) return null;
    $birthday = $r['birthday'] ?? null;
    return [
        'id'             => (int)$r['id'],
        'username'       => $r['username'] ?? '',
        'display_name'   => $r['display_name'] ?: ($r['username'] ?? 'Buddies'),
        'oshi_member'    => $r['oshi_member'] ?? null,
        'oshi_member_2'  => $r['oshi_member_2'] ?? null,
        'oshi_member_3'  => $r['oshi_member_3'] ?? null,
        'user_icon'      => $r['user_icon'] ?? null,
        'age'            => $birthday ? buddies_calc_age($birthday) : (($r['age'] ?? null) !== null ? (int)$r['age'] : null),
        'location'       => $r['location'] ?? null,
        'buddies_since'  => $r['buddies_since'] ?? null,
        'bio'            => $r['bio'] ?? null,
        'tags'           => !empty($r['tags']) ? (json_decode($r['tags'], true) ?: []) : [],
        'favorite_songs' => !empty($r['favorite_songs']) ? (json_decode($r['favorite_songs'], true) ?: []) : [],
        'sns_links'      => !empty($r['sns_links']) ? (json_decode($r['sns_links'], true) ?: []) : [],
        'follow_stance'  => $r['follow_stance'] ?? null,
    ];
}

function buddies_members(): array {
    static $members = null;
    if ($members !== null) return $members;
    if (!is_file(BUDDIES_MEMBER_DATA_PATH)) return $members = [];
    $json = json_decode((string)file_get_contents(BUDDIES_MEMBER_DATA_PATH), true);
    return $members = is_array($json) ? $json : [];
}

function buddies_profile_url(int $uid): string {
    return BUDDIES_BASE_URL . 'profile.php?uid=' . $uid;
}

function buddies_app_url(int $uid): string {
    return BUDDIES_BASE_URL . '?uid=' . $uid . '&mode=view';
}

function buddies_image_url(int $uid): string {
    return BUDDIES_BASE_URL . 'og-image.php?uid=' . $uid . '&v=2';
}

function buddies_download_url(int $uid): string {
    return BUDDIES_BASE_URL . 'og-image.php?uid=' . $uid . '&download=1';
}

function buddies_card_url(int $uid): string {
    return BUDDIES_BASE_URL . 'card.html?uid=' . $uid;
}

function buddies_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function buddies_meta_description(array $p): string {
    $oshis = array_values(array_filter([$p['oshi_member'] ?? null, $p['oshi_member_2'] ?? null, $p['oshi_member_3'] ?? null]));
    $bits = [];
    if ($oshis) $bits[] = implode(' / ', $oshis) . ' 推し';
    if (!empty($p['location'])) $bits[] = $p['location'];
    if (!empty($p['favorite_songs'][0])) $bits[] = '好きな曲: ' . $p['favorite_songs'][0];
    if (!$bits) $bits[] = '櫻坂46ファンのプロフィール';
    return implode('・', $bits) . ' | Buddies profile';
}

function buddies_font_regular(): string {
    $candidates = [
        __DIR__ . '/assets/fonts/NotoSansJP-Regular.ttf',
        '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
        '/System/Library/Fonts/AppleSDGothicNeo.ttc',
        '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
    ];
    $candidates = array_merge(glob('/System/Library/Fonts/*角*W6.ttc') ?: [], glob('/System/Library/Fonts/*角*W8.ttc') ?: [], $candidates);
    foreach ($candidates as $path) if (is_file($path)) return $path;
    return '';
}

function buddies_font_hand(): string {
    $candidates = [
        __DIR__ . '/assets/fonts/Yomogi-Regular.ttf',
        __DIR__ . '/assets/fonts/ZenMaruGothic-Regular.ttf',
        '/usr/share/fonts/truetype/fonts-japanese-gothic.ttf',
        buddies_font_regular(),
    ];
    $candidates = array_merge(glob('/System/Library/Fonts/*丸*W4.ttc') ?: [], $candidates);
    foreach ($candidates as $path) if ($path && is_file($path)) return $path;
    return '';
}

function buddies_color(string $hex): int {
    $hex = ltrim($hex, '#');
    return hexdec($hex);
}

function buddies_rgb(GdImage $im, string $hex): int {
    $n = buddies_color($hex);
    return imagecolorallocate($im, ($n >> 16) & 255, ($n >> 8) & 255, $n & 255);
}

function buddies_rgba(GdImage $im, string $hex, int $alpha): int {
    $n = buddies_color($hex);
    return imagecolorallocatealpha($im, ($n >> 16) & 255, ($n >> 8) & 255, $n & 255, $alpha);
}

function buddies_text(GdImage $im, int $size, int $x, int $y, string $text, int $color, string $font = '', int $angle = 0): void {
    $font = $font ?: buddies_font_regular();
    if ($font && function_exists('imagettftext')) {
        imagettftext($im, $size, $angle, $x, $y, $color, $font, $text);
    } else {
        imagestring($im, 5, $x, $y - $size, $text, $color);
    }
}

function buddies_wrap(string $text, int $limit, int $lines = 2): array {
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') return [];
    $out = [];
    while (mb_strlen($text) > $limit && count($out) < $lines) {
        $out[] = mb_substr($text, 0, $limit);
        $text = mb_substr($text, $limit);
    }
    if (count($out) < $lines && $text !== '') $out[] = $text;
    if (count($out) > $lines) $out = array_slice($out, 0, $lines);
    if ($out && count($out) === $lines && mb_strlen(end($out)) > $limit - 1) {
        $out[$lines - 1] = mb_substr($out[$lines - 1], 0, max(1, $limit - 1)) . '…';
    }
    return $out;
}

function buddies_rounded_rect(GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, int $color, bool $filled = true): void {
    if ($filled) {
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    } else {
        imagerectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagerectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagearc($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, 180, 270, $color);
        imagearc($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, 270, 360, $color);
        imagearc($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, 90, 180, $color);
        imagearc($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, 0, 90, $color);
    }
}

function buddies_load_image(?string $url): ?GdImage {
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    $ctx = stream_context_create([
        'http' => ['timeout' => 3, 'user_agent' => 'Buddies OGP Image'],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if (!$data) return null;
    $img = @imagecreatefromstring($data);
    return $img ?: null;
}

function buddies_avatar_url(array $p): ?string {
    if (!empty($p['user_icon'])) return $p['user_icon'];
    $first = $p['oshi_member'] ?? null;
    $members = buddies_members();
    if ($first && !empty($members[$first]['image'])) return $members[$first]['image'];
    return null;
}

function buddies_draw_cover_image(GdImage $dst, GdImage $src, int $x, int $y, int $w, int $h): void {
    $sw = imagesx($src);
    $sh = imagesy($src);
    if ($sw <= 0 || $sh <= 0) return;
    $scale = max($w / $sw, $h / $sh);
    $cw = (int)round($w / $scale);
    $ch = (int)round($h / $scale);
    $sx = max(0, (int)(($sw - $cw) / 2));
    $sy = max(0, (int)(($sh - $ch) / 4));
    imagecopyresampled($dst, $src, $x, $y, $sx, $sy, $w, $h, $cw, $ch);
}

function buddies_draw_cover_circle(GdImage $dst, GdImage $src, int $cx, int $cy, int $size): void {
    $tmp = imagecreatetruecolor($size, $size);
    imagealphablending($tmp, false);
    imagesavealpha($tmp, true);
    imagefilledrectangle($tmp, 0, 0, $size, $size, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
    buddies_draw_cover_image($tmp, $src, 0, 0, $size, $size);

    $maskColor = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
    $r = $size / 2;
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $dx = $x - $r + 0.5;
            $dy = $y - $r + 0.5;
            if (($dx * $dx + $dy * $dy) > ($r * $r)) {
                imagesetpixel($tmp, $x, $y, $maskColor);
            }
        }
    }
    imagecopy($dst, $tmp, $cx - (int)($size / 2), $cy - (int)($size / 2), 0, 0, $size, $size);
}

function buddies_draw_chip(GdImage $im, string $text, int $x, int $y, int $maxW, int $bg, int $fg, string $font): int {
    $text = mb_strlen($text) > 16 ? mb_substr($text, 0, 15) . '…' : $text;
    $box = imagettfbbox(19, 0, $font, $text);
    $w = min($maxW, abs($box[2] - $box[0]) + 34);
    buddies_rounded_rect($im, $x, $y, $x + $w, $y + 42, 21, $bg, true);
    buddies_text($im, 19, $x + 17, $y + 28, $text, $fg, $font);
    return $w;
}

function buddies_render_card(array $p): GdImage {
    $w = 1200; $h = 630;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);
    imagesavealpha($im, true);

    $font = buddies_font_regular();
    $bg = buddies_rgb($im, '#ffffff');
    $white = buddies_rgb($im, '#ffffff');
    $ink = buddies_rgb($im, '#111111');
    $muted = buddies_rgb($im, '#747474');
    $line = buddies_rgb($im, '#eeeeee');
    $soft = buddies_rgb($im, '#f7f7f7');

    imagefilledrectangle($im, 0, 0, $w, $h, $bg);
    imagefilledrectangle($im, 78, 92, 82, 538, $ink);
    imageline($im, 78, 538, 1122, 538, $line);

    buddies_text($im, 24, 110, 146, 'Buddies profile', $muted, $font);

    $avatarSize = 170;
    imagefilledellipse($im, 222, 308, $avatarSize + 16, $avatarSize + 16, $soft);
    $src = buddies_load_image(buddies_avatar_url($p));
    if ($src) {
        buddies_draw_cover_circle($im, $src, 222, 308, $avatarSize);
    } else {
        imagefilledellipse($im, 222, 308, $avatarSize, $avatarSize, $soft);
        buddies_text($im, 66, 196, 334, mb_substr((string)$p['display_name'], 0, 1), $ink, $font);
    }
    imageellipse($im, 222, 308, $avatarSize, $avatarSize, $line);

    $name = trim((string)$p['display_name']) ?: 'Buddies';
    $nameLines = buddies_wrap($name, 16, 2);
    foreach ($nameLines as $i => $lineText) {
        buddies_text($im, $i === 0 ? 58 : 46, 360, 222 + $i * 62, $lineText, $ink, $font);
    }

    $oshis = array_values(array_filter([$p['oshi_member'] ?? null, $p['oshi_member_2'] ?? null, $p['oshi_member_3'] ?? null]));
    $summary = $oshis ? implode(' / ', array_slice($oshis, 0, 3)) . ' 推し' : '櫻坂46ファンのプロフィール';
    buddies_text($im, 25, 364, 348, $summary, $muted, $font);

    $meta = array_values(array_filter([
        !empty($p['location']) ? $p['location'] : null,
        !empty($p['age']) ? $p['age'] . '歳' : null,
        !empty($p['buddies_since']) ? 'Buddies歴 ' . $p['buddies_since'] : null,
    ]));
    if ($meta) buddies_text($im, 22, 364, 398, implode('  /  ', $meta), $muted, $font);

    $tags = array_values(array_filter(array_merge($p['tags'] ?? [], $p['favorite_songs'] ?? [])));
    $lineText = $tags ? implode('  ・  ', array_slice($tags, 0, 3)) : 'プロフィールを開いて詳しく見る';
    $lineText = mb_strlen($lineText) > 32 ? mb_substr($lineText, 0, 31) . '…' : $lineText;
    buddies_rounded_rect($im, 364, 438, 972, 492, 27, $soft, true);
    buddies_text($im, 21, 394, 474, $lineText, $muted, $font);

    if (!empty($p['username'])) {
        buddies_text($im, 20, 110, 500, '@' . (string)$p['username'], $muted, $font);
    }

    buddies_text($im, 26, 970, 592, 'Buddies', $ink, $font);

    return $im;
}
