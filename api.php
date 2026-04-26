<?php
/**
 * Buddies API
 *
 * SakuLabo の sakulabo_users / sakulabo_sessions テーブルを共有使用。
 * 追加テーブル: buddies_profiles, buddies_exchanges, buddies_favorites
 *
 * Endpoints (action=):
 *   auth_register             POST ユーザー登録（sakulabo共通）
 *   auth_login                POST ログイン（sakulabo共通）
 *   auth_logout               POST ログアウト
 *   auth_me                   GET  ログインユーザー情報（Buddies拡張フィールド含む）
 *
 *   buddies_profile_get       GET  Buddiesプロフィール取得（自分 or 他ユーザー）
 *   buddies_profile_update    POST Buddiesプロフィール更新
 *   buddies_icon_update       POST プロフィール画像更新（ブログ画像から選択）
 *   buddies_icon_clear        POST プロフィール画像をクリア
 *
 *   buddies_exchange_add      POST 交換相手を追加
 *   buddies_exchange_list     GET  交換済み一覧
 *   buddies_exchange_remove   POST 交換相手を削除
 *
 *   buddies_favorite_toggle   POST お気に入りトグル
 *   buddies_favorite_list     GET  お気に入り一覧
 *
 *   buddies_search            GET  ユーザー検索
 *   buddies_similar           GET  おすすめユーザー（同じ推しメン）
 *
 *   buddies_blog_images       GET  ブログ画像一覧（フィルタ・ソート対応）
 */

// ── 設定 ─────────────────────────────────────────────────
$config = require __DIR__ . '/../../../api/config.php';

define('SESSION_EXPIRE_HOURS', 720);
define('ALLOWED_ORIGINS', ['*']);
define('SCHEMA_FLAG', __DIR__ . '/.buddies_schema_v4.lock');
define('BLOG_DATA_PATH', __DIR__ . '/../data/blogs.json');
define('MEMBER_DATA_PATH', __DIR__ . '/../data/member.json');

// ── ヘッダー ─────────────────────────────────────────────
if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
if (in_array('*', ALLOWED_ORIGINS, true) || in_array($origin, ALLOWED_ORIGINS, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Session-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── DB 接続 ──────────────────────────────────────────────
function db(): PDO {
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
    if (!file_exists(SCHEMA_FLAG)) {
        runMigrations($pdo);
        @file_put_contents(SCHEMA_FLAG, date('Y-m-d H:i:s'));
    }
    return $pdo;
}

function runMigrations(PDO $pdo): void {
    // ─ sakulabo_users に Buddies 拡張カラムを追加 ─
    $cols = array_column($pdo->query('DESCRIBE sakulabo_users')->fetchAll(), 'Field');

    if (!in_array('oshi_member_2', $cols)) {
        $pdo->exec("ALTER TABLE sakulabo_users ADD COLUMN oshi_member_2 VARCHAR(64) NULL DEFAULT NULL");
    }
    if (!in_array('oshi_member_3', $cols)) {
        $pdo->exec("ALTER TABLE sakulabo_users ADD COLUMN oshi_member_3 VARCHAR(64) NULL DEFAULT NULL");
    }
    try {
        $pdo->exec("ALTER TABLE sakulabo_users MODIFY COLUMN user_icon VARCHAR(2048) NULL DEFAULT NULL");
    } catch (\Throwable $e) {}

    // ─ Buddies プロフィール拡張テーブル（完全版） ─
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_profiles (
        id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id        BIGINT UNSIGNED NOT NULL UNIQUE,
        birthday       DATE NULL DEFAULT NULL,
        age            TINYINT UNSIGNED NULL DEFAULT NULL COMMENT 'birthday から自動計算・キャッシュ',
        gender         VARCHAR(10) NULL DEFAULT NULL,
        location       VARCHAR(64) NULL DEFAULT NULL COMMENT '都道府県',
        buddies_since  VARCHAR(20) NULL DEFAULT NULL COMMENT '推し始めた年（YYYY）',
        bio            VARCHAR(500) NULL DEFAULT NULL,
        tags           TEXT NULL DEFAULT NULL COMMENT 'JSON array',
        favorite_songs TEXT NULL DEFAULT NULL COMMENT 'JSON array',
        sns_links      TEXT NULL DEFAULT NULL COMMENT 'JSON array of {type,url}',
        follow_stance  VARCHAR(20) NULL DEFAULT NULL COMMENT 'silent_ok / hello_please',
        post_template  TEXT NULL DEFAULT NULL COMMENT 'SNS紹介テンプレート',
        updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ─ 既存テーブルへのカラム追加（冪等・新カラムのみ） ─
    $bpCols = array_column($pdo->query('DESCRIBE buddies_profiles')->fetchAll(), 'Field');
    $alterMap = [
        'follow_stance' => "ALTER TABLE buddies_profiles ADD COLUMN follow_stance VARCHAR(20) NULL DEFAULT NULL",
        'post_template' => "ALTER TABLE buddies_profiles ADD COLUMN post_template TEXT NULL DEFAULT NULL",
        'birthday'      => "ALTER TABLE buddies_profiles ADD COLUMN birthday DATE NULL DEFAULT NULL",
    ];
    foreach ($alterMap as $col => $sql) {
        if (!in_array($col, $bpCols)) {
            $pdo->exec($sql);
        }
    }

    // ─ 交換済みプロフィールテーブル ─
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_exchanges (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     BIGINT UNSIGNED NOT NULL,
        target_id   BIGINT UNSIGNED NOT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_exchange (user_id, target_id),
        KEY idx_user (user_id),
        KEY idx_target (target_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ─ お気に入りテーブル ─
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_favorites (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     BIGINT UNSIGNED NOT NULL,
        target_id   BIGINT UNSIGNED NOT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_favorite (user_id, target_id),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ── ヘルパー ─────────────────────────────────────────────
function ok(mixed $data = null): never {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function body(): array {
    static $b = null;
    if ($b !== null) return $b;
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    return $b;
}
function req(string $k): string {
    $v = trim(body()[$k] ?? '');
    if ($v === '') err("$k は必須です。");
    return $v;
}
function opt(string $k, mixed $default = null): mixed {
    $body = body();
    $v = array_key_exists($k, $body) ? $body[$k] : $default;
    return is_string($v) ? trim($v) : $v;
}

// ── 認証 ─────────────────────────────────────────────────
function getToken(): ?string {
    $h = $_SERVER['HTTP_X_SESSION_TOKEN']
      ?? $_SERVER['HTTP_AUTHORIZATION']
      ?? ($_COOKIE['sakulabo_token'] ?? null);
    if (!$h) return null;
    return preg_replace('/^Bearer\s+/i', '', trim($h));
}
function currentUser(): ?array {
    $token = getToken();
    if (!$token) return null;
    $st = db()->prepare(
        'SELECT u.* FROM sakulabo_users u
         JOIN sakulabo_sessions s ON s.user_id = u.id
         WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1'
    );
    $st->execute([$token]);
    return $st->fetch() ?: null;
}
function requireAuth(): array {
    $u = currentUser();
    if (!$u) err('ログインが必要です。', 401);
    return $u;
}
function generateToken(): string { return bin2hex(random_bytes(32)); }
function setSessionCookie(string $token): void {
    setcookie('sakulabo_token', $token, [
        'expires'  => time() + SESSION_EXPIRE_HOURS * 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ── 誕生日から年齢計算 ────────────────────────────────────
function calcAgeFromBirthday(string $birthday): ?int {
    $ts = strtotime($birthday);
    if (!$ts) return null;
    $bd  = new DateTime(date('Y-m-d', $ts));
    $now = new DateTime('today');
    if ($bd > $now) return null;
    return $now->diff($bd)->y;
}

// ── buddies_profiles の行を取得 ───────────────────────────
function getBuddiesProfile(int $userId): ?array {
    $st = db()->prepare('SELECT * FROM buddies_profiles WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

// ── ユーザー情報ビルダー（sakulabo_users + buddies_profiles） ─
function buildUserData(array $u, ?array $bp = null, bool $includePrivate = false): array {
    $data = [
        'id'            => (int)$u['id'],
        'username'      => $u['username'],
        'display_name'  => $u['display_name'],
        'oshi_member'   => $u['oshi_member']   ?? null,
        'oshi_member_2' => $u['oshi_member_2'] ?? null,
        'oshi_member_3' => $u['oshi_member_3'] ?? null,
        'user_icon'     => $u['user_icon']      ?? null,
    ];

    if ($bp) {
        $birthday = $bp['birthday'] ?? null;
        $data['birthday']       = $birthday;
        $data['age']            = $birthday ? calcAgeFromBirthday($birthday)
                                            : ($bp['age'] !== null ? (int)$bp['age'] : null);
        $data['gender']         = $bp['gender']        ?? null;
        $data['location']       = $bp['location']      ?? null;
        $data['buddies_since']  = $bp['buddies_since'] ?? null;
        $data['bio']            = $bp['bio']           ?? null;
        $data['tags']           = $bp['tags']           ? json_decode($bp['tags'],           true) : [];
        $data['favorite_songs'] = $bp['favorite_songs'] ? json_decode($bp['favorite_songs'], true) : [];
        $data['sns_links']      = $bp['sns_links']      ? json_decode($bp['sns_links'],      true) : [];
        $data['follow_stance']  = $bp['follow_stance'] ?? null;
        $data['post_template']  = $bp['post_template'] ?? null;
    } else {
        $data['birthday']       = null;
        $data['age']            = null;
        $data['gender']         = null;
        $data['location']       = null;
        $data['buddies_since']  = null;
        $data['bio']            = null;
        $data['tags']           = [];
        $data['favorite_songs'] = [];
        $data['sns_links']      = [];
        $data['follow_stance']  = null;
        $data['post_template']  = null;
    }
    return $data;
}

// ── JOIN結果行ビルダー（リスト系クエリ用） ────────────────
// birthday が含まれている場合は age を再計算する
function buildRowData(array $r): array {
    $birthday = $r['birthday'] ?? null;
    $age      = $birthday ? calcAgeFromBirthday($birthday)
                          : (isset($r['age']) && $r['age'] !== null ? (int)$r['age'] : null);
    return [
        'id'            => (int)$r['id'],
        'username'      => $r['username'],
        'display_name'  => $r['display_name'],
        'oshi_member'   => $r['oshi_member']   ?? null,
        'oshi_member_2' => $r['oshi_member_2'] ?? null,
        'oshi_member_3' => $r['oshi_member_3'] ?? null,
        'user_icon'     => $r['user_icon']      ?? null,
        'birthday'      => $birthday,
        'age'           => $age,
        'gender'        => $r['gender']        ?? null,
        'location'      => $r['location']      ?? null,
        'buddies_since' => $r['buddies_since'] ?? null,
        'bio'           => $r['bio']           ?? null,
        'tags'          => isset($r['tags'])           && $r['tags']           ? json_decode($r['tags'],           true) : [],
        'favorite_songs'=> isset($r['favorite_songs']) && $r['favorite_songs'] ? json_decode($r['favorite_songs'], true) : [],
        'sns_links'     => isset($r['sns_links'])      && $r['sns_links']      ? json_decode($r['sns_links'],      true) : [],
        'follow_stance' => $r['follow_stance'] ?? null,
        'post_template' => $r['post_template'] ?? null,
        'exchanged_at'  => $r['exchanged_at']  ?? null,
        'favorited_at'  => $r['favorited_at']  ?? null,
    ];
}

// ── アクション振り分け ────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? body()['action'] ?? '';

match ($action) {
    'auth_register'           => actionRegister(),
    'auth_login'              => actionLogin(),
    'auth_logout'             => actionLogout(),
    'auth_me'                 => actionMe(),
    'auth_username_change'    => actionUsernameChange(),
    'auth_password_change'    => actionPasswordChange(),

    'buddies_profile_get'     => actionProfileGet(),
    'buddies_profile_update'  => actionProfileUpdate(),
    'buddies_icon_update'     => actionIconUpdate(),
    'buddies_icon_clear'      => actionIconClear(),

    'buddies_exchange_add'    => actionExchangeAdd(),
    'buddies_exchange_list'   => actionExchangeList(),
    'buddies_exchange_remove' => actionExchangeRemove(),

    'buddies_favorite_toggle' => actionFavoriteToggle(),
    'buddies_favorite_list'   => actionFavoriteList(),

    'buddies_search'          => actionSearch(),
    'buddies_similar'         => actionSimilar(),

    'buddies_blog_images'     => actionBlogImages(),

    default => err('不明なアクションです。'),
};

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  ユーザー登録
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionRegister(): void {
    $username = req('username');
    $password = req('password');
    $display  = opt('display_name') ?: $username;

    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username))
        err('ユーザー名は3〜32文字の半角英数字・アンダースコアで入力してください。');
    if (strlen($password) < 8)
        err('パスワードは8文字以上で入力してください。');
    if (mb_strlen($display) > 64)
        err('表示名は64文字以内で入力してください。');

    $ck = db()->prepare('SELECT id FROM sakulabo_users WHERE username = ? LIMIT 1');
    $ck->execute([$username]);
    if ($ck->fetch()) err('そのユーザー名はすでに使用されています。');

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare('INSERT INTO sakulabo_users (username, password_hash, display_name) VALUES (?,?,?)')
       ->execute([$username, $hash, $display]);
    $uid   = (int)db()->lastInsertId();
    $token = generateToken();
    $exp   = date('Y-m-d H:i:s', strtotime('+' . SESSION_EXPIRE_HOURS . ' hours'));
    db()->prepare('INSERT INTO sakulabo_sessions (token, user_id, expires_at) VALUES (?,?,?)')
       ->execute([$token, $uid, $exp]);
    setSessionCookie($token);

    db()->prepare('INSERT IGNORE INTO buddies_profiles (user_id) VALUES (?)')->execute([$uid]);
    $bp = getBuddiesProfile($uid);

    $newUser = db()->prepare('SELECT * FROM sakulabo_users WHERE id=? LIMIT 1');
    $newUser->execute([$uid]);
    $u = $newUser->fetch();

    ok(['token' => $token, 'user' => buildUserData($u, $bp, true)]);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  ログイン
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionLogin(): void {
    $username = req('username');
    $password = req('password');
    $st = db()->prepare('SELECT * FROM sakulabo_users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $user = $st->fetch();
    if (!$user || !password_verify($password, $user['password_hash']))
        err('ユーザー名またはパスワードが正しくありません。', 401);

    db()->prepare('DELETE FROM sakulabo_sessions WHERE user_id = ? OR expires_at < NOW()')
       ->execute([$user['id']]);
    $token = generateToken();
    $exp   = date('Y-m-d H:i:s', strtotime('+' . SESSION_EXPIRE_HOURS . ' hours'));
    db()->prepare('INSERT INTO sakulabo_sessions (token, user_id, expires_at) VALUES (?,?,?)')
       ->execute([$token, $user['id'], $exp]);
    setSessionCookie($token);

    db()->prepare('INSERT IGNORE INTO buddies_profiles (user_id) VALUES (?)')->execute([$user['id']]);
    $bp = getBuddiesProfile((int)$user['id']);

    ok(['token' => $token, 'user' => buildUserData($user, $bp, true)]);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  ログアウト
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionLogout(): void {
    $token = getToken();
    if ($token) db()->prepare('DELETE FROM sakulabo_sessions WHERE token = ?')->execute([$token]);
    setcookie('sakulabo_token', '', ['expires' => time() - 3600, 'path' => '/']);
    ok();
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  ユーザー名変更
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionUsernameChange(): void {
    $me  = requireAuth();
    $uid = (int)$me['id'];
    $b   = body();
    $newUsername     = trim($b['new_username'] ?? '');
    $currentPassword = $b['current_password'] ?? '';

    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $newUsername))
        err('ユーザー名は3〜32文字の半角英数字・アンダースコアで入力してください。');

    $st = db()->prepare('SELECT * FROM sakulabo_users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    $user = $st->fetch();
    if (!$user) err('ユーザーが見つかりません。', 404);
    if (!password_verify($currentPassword, $user['password_hash']))
        err('現在のパスワードが正しくありません。');

    $ck = db()->prepare('SELECT id FROM sakulabo_users WHERE username = ? AND id != ? LIMIT 1');
    $ck->execute([$newUsername, $uid]);
    if ($ck->fetch()) err('そのユーザー名はすでに使用されています。');

    db()->prepare('UPDATE sakulabo_users SET username = ? WHERE id = ?')
       ->execute([$newUsername, $uid]);

    ok(['username' => $newUsername]);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  パスワード変更
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionPasswordChange(): void {
    $me  = requireAuth();
    $uid = (int)$me['id'];
    $b   = body();
    $currentPassword = $b['current_password'] ?? '';
    $newPassword     = $b['new_password']      ?? '';

    if (strlen($newPassword) < 8)
        err('新しいパスワードは8文字以上で入力してください。');

    $st = db()->prepare('SELECT * FROM sakulabo_users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    $user = $st->fetch();
    if (!$user) err('ユーザーが見つかりません。', 404);
    if (!password_verify($currentPassword, $user['password_hash']))
        err('現在のパスワードが正しくありません。');

    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare('UPDATE sakulabo_users SET password_hash = ? WHERE id = ?')
       ->execute([$hash, $uid]);

    ok();
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  ログインユーザー情報
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionMe(): void {
    $me = currentUser();
    if (!$me) { ok(null); return; }

    db()->prepare('INSERT IGNORE INTO buddies_profiles (user_id) VALUES (?)')->execute([$me['id']]);
    $bp = getBuddiesProfile((int)$me['id']);

    ok(buildUserData($me, $bp, true));
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  プロフィール取得（自分 or 他ユーザー）
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionProfileGet(): void {
    $targetId = (int)($_GET['user_id'] ?? 0);
    if ($targetId <= 0) {
        $me = requireAuth();
        $targetId = (int)$me['id'];
    }

    $st = db()->prepare('SELECT * FROM sakulabo_users WHERE id = ? LIMIT 1');
    $st->execute([$targetId]);
    $u = $st->fetch();
    if (!$u) err('ユーザーが見つかりません。', 404);

    $bp = getBuddiesProfile($targetId);
    ok(buildUserData($u, $bp));
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  プロフィール更新
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionProfileUpdate(): void {
    $me = requireAuth();
    $b  = body();

    // ─ sakulabo_users（推しメン・表示名） ─
    $display = opt('display_name', $me['display_name']);
    $oshi1   = opt('oshi_member',   $me['oshi_member']   ?? null);
    $oshi2   = opt('oshi_member_2', $me['oshi_member_2'] ?? null);
    $oshi3   = opt('oshi_member_3', $me['oshi_member_3'] ?? null);

    if (mb_strlen($display) < 1 || mb_strlen($display) > 64)
        err('表示名は1〜64文字で入力してください。');

    db()->prepare(
        'UPDATE sakulabo_users SET display_name=?, oshi_member=?, oshi_member_2=?, oshi_member_3=? WHERE id=?'
    )->execute([$display, $oshi1 ?: null, $oshi2 ?: null, $oshi3 ?: null, $me['id']]);

    // ─ buddies_profiles ─
    $birthdayRaw = opt('birthday', null);
    $birthday    = null;
    if ($birthdayRaw) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdayRaw) && strtotime($birthdayRaw)) {
            $birthday = $birthdayRaw;
        } else {
            err('誕生日の形式が正しくありません（YYYY-MM-DD）。');
        }
    }
    $age          = $birthday ? calcAgeFromBirthday($birthday) : null;
    $gender       = opt('gender',        null);
    $location     = opt('location',      null);
    $buddiesSince = opt('buddies_since', null);
    $bio          = opt('bio',           null);

    $tags         = isset($b['tags'])           && is_array($b['tags'])           ? array_slice($b['tags'],           0, 5) : null;
    $favSongs     = isset($b['favorite_songs']) && is_array($b['favorite_songs']) ? array_slice($b['favorite_songs'], 0, 3) : null;
    $snsLinks     = isset($b['sns_links'])      && is_array($b['sns_links'])      ? array_slice($b['sns_links'],      0, 4) : null;

    $followStance = opt('follow_stance', null);
    if ($followStance && !in_array($followStance, ['silent_ok', 'hello_please'], true)) $followStance = null;

    $postTemplate = opt('post_template', null);

    if ($bio          && mb_strlen($bio)          > 500)  err('自己紹介は500文字以内で入力してください。');
    if ($postTemplate && mb_strlen($postTemplate) > 1000) err('SNS紹介タグは1000文字以内で入力してください。');

    // SNSリンクの url を簡易バリデーション（空文字は除去）
    if ($snsLinks !== null) {
        $snsLinks = array_values(array_filter($snsLinks, fn($s) => !empty($s['url'])));
        foreach ($snsLinks as $s) {
            if (!filter_var($s['url'], FILTER_VALIDATE_URL)) err('SNSリンクのURLが無効です: ' . $s['url']);
        }
    }

    db()->prepare('INSERT INTO buddies_profiles
        (user_id, birthday, age, gender, location, buddies_since, bio,
         tags, favorite_songs, sns_links, follow_stance, post_template)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          birthday=VALUES(birthday), age=VALUES(age),
          gender=VALUES(gender), location=VALUES(location),
          buddies_since=VALUES(buddies_since), bio=VALUES(bio),
          tags=VALUES(tags), favorite_songs=VALUES(favorite_songs),
          sns_links=VALUES(sns_links), follow_stance=VALUES(follow_stance),
          post_template=VALUES(post_template)')
    ->execute([
        $me['id'],
        $birthday,
        $age,
        $gender       ?: null,
        $location     ?: null,
        $buddiesSince ?: null,
        $bio          ?: null,
        $tags         !== null ? json_encode($tags,         JSON_UNESCAPED_UNICODE) : null,
        $favSongs     !== null ? json_encode($favSongs,     JSON_UNESCAPED_UNICODE) : null,
        $snsLinks     !== null ? json_encode(array_values($snsLinks), JSON_UNESCAPED_UNICODE) : null,
        $followStance,
        $postTemplate ?: null,
    ]);

    $newUser = db()->prepare('SELECT * FROM sakulabo_users WHERE id=? LIMIT 1');
    $newUser->execute([$me['id']]);
    $u  = $newUser->fetch();
    $bp = getBuddiesProfile((int)$me['id']);
    ok(buildUserData($u, $bp, true));
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  プロフィール画像更新
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionIconUpdate(): void {
    $me  = requireAuth();
    $url = req('user_icon');
    if (strlen($url) > 2048) err('URLが長すぎます。');
    if (!filter_var($url, FILTER_VALIDATE_URL)) err('有効なURLを指定してください。');
    db()->prepare('UPDATE sakulabo_users SET user_icon=? WHERE id=?')->execute([$url, $me['id']]);
    ok(['user_icon' => $url]);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  プロフィール画像クリア
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionIconClear(): void {
    $me = requireAuth();
    db()->prepare('UPDATE sakulabo_users SET user_icon=NULL WHERE id=?')->execute([$me['id']]);
    ok(['user_icon' => null]);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  交換追加
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionExchangeAdd(): void {
    $me       = requireAuth();
    $targetId = (int)req('target_id');
    if ($targetId === (int)$me['id']) err('自分自身を追加できません。');

    $ck = db()->prepare('SELECT id FROM sakulabo_users WHERE id = ? LIMIT 1');
    $ck->execute([$targetId]);
    if (!$ck->fetch()) err('ユーザーが見つかりません。', 404);

    $ins = db()->prepare('INSERT IGNORE INTO buddies_exchanges (user_id, target_id) VALUES (?,?)');
    $ins->execute([(int)$me['id'], $targetId]);
    $ins->execute([$targetId, (int)$me['id']]);

    ok(['added' => true]);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  交換済み一覧
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionExchangeList(): void {
    $me = requireAuth();
    $st = db()->prepare(
        'SELECT u.id, u.username, u.display_name, u.oshi_member, u.oshi_member_2, u.oshi_member_3,
                u.user_icon,
                bp.birthday, bp.age, bp.gender, bp.location, bp.buddies_since, bp.bio,
                bp.tags, bp.favorite_songs, bp.sns_links, bp.follow_stance, bp.post_template,
                e.created_at AS exchanged_at
         FROM buddies_exchanges e
         JOIN sakulabo_users u ON u.id = e.target_id
         LEFT JOIN buddies_profiles bp ON bp.user_id = u.id
         WHERE e.user_id = ?
         ORDER BY e.created_at DESC'
    );
    $st->execute([$me['id']]);
    ok(array_map(fn($r) => buildRowData($r), $st->fetchAll()));
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  交換削除
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionExchangeRemove(): void {
    $me       = requireAuth();
    $targetId = (int)req('target_id');
    db()->prepare('DELETE FROM buddies_exchanges WHERE user_id=? AND target_id=?')
       ->execute([(int)$me['id'], $targetId]);
    ok(['removed' => true]);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  お気に入りトグル
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionFavoriteToggle(): void {
    $me       = requireAuth();
    $targetId = (int)req('target_id');
    if ($targetId === (int)$me['id']) err('自分自身はお気に入りにできません。');

    $ck = db()->prepare('SELECT id FROM buddies_favorites WHERE user_id=? AND target_id=? LIMIT 1');
    $ck->execute([$me['id'], $targetId]);
    if ($ck->fetch()) {
        db()->prepare('DELETE FROM buddies_favorites WHERE user_id=? AND target_id=?')
           ->execute([$me['id'], $targetId]);
        ok(['favorited' => false]);
    } else {
        db()->prepare('INSERT IGNORE INTO buddies_favorites (user_id, target_id) VALUES (?,?)')
           ->execute([$me['id'], $targetId]);
        ok(['favorited' => true]);
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  お気に入り一覧
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionFavoriteList(): void {
    $me = requireAuth();
    $st = db()->prepare(
        'SELECT u.id, u.username, u.display_name, u.oshi_member, u.oshi_member_2, u.oshi_member_3,
                u.user_icon,
                bp.birthday, bp.age, bp.gender, bp.location, bp.buddies_since, bp.bio,
                bp.tags, bp.favorite_songs, bp.sns_links, bp.follow_stance, bp.post_template,
                f.created_at AS favorited_at
         FROM buddies_favorites f
         JOIN sakulabo_users u ON u.id = f.target_id
         LEFT JOIN buddies_profiles bp ON bp.user_id = u.id
         WHERE f.user_id = ?
         ORDER BY f.created_at DESC'
    );
    $st->execute([$me['id']]);
    ok(array_map(fn($r) => buildRowData($r), $st->fetchAll()));
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  ユーザー検索（名前・推しメン・タグ・都道府県）
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionSearch(): void {
    $q      = trim($_GET['q'] ?? '');
    $limit  = min((int)($_GET['limit'] ?? 30), 100);
    $offset = max((int)($_GET['offset'] ?? 0), 0);
    $me     = currentUser();
    $myId   = $me ? (int)$me['id'] : 0;

    // 推しメン絞り込み（複数指定 + AND/OR + 第一推し指定）
    $oshisRaw = $_GET['oshis'] ?? '';
    $oshis    = array_values(array_filter(array_map('trim', explode(',', $oshisRaw))));
    $oshis    = array_slice(array_unique($oshis), 0, 10);
    $oshiMode = strtolower(trim($_GET['oshi_mode'] ?? 'or')) === 'and' ? 'and' : 'or';
    $primary  = trim($_GET['primary_oshi'] ?? '');

    $selectCols = '
        u.id, u.username, u.display_name, u.oshi_member, u.oshi_member_2, u.oshi_member_3,
        u.user_icon,
        bp.birthday, bp.age, bp.gender, bp.location, bp.buddies_since, bp.bio,
        bp.tags, bp.favorite_songs, bp.sns_links, bp.follow_stance, bp.post_template';

    $where  = ['u.id != ?'];
    $params = [$myId ?: 0];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(u.display_name LIKE ? OR u.username LIKE ?
                    OR u.oshi_member LIKE ? OR u.oshi_member_2 LIKE ? OR u.oshi_member_3 LIKE ?
                    OR bp.tags LIKE ? OR bp.location LIKE ?)';
        for ($i = 0; $i < 7; $i++) $params[] = $like;
    }

    // 第一推し指定（最優先で oshi_member 列に固定）
    if ($primary !== '') {
        $where[]  = 'u.oshi_member = ?';
        $params[] = $primary;
    }

    // 複数推しメン（AND: 全員いずれかのスロットに含む / OR: いずれか1人でも一致）
    if (!empty($oshis)) {
        $oshiCols = ['u.oshi_member', 'u.oshi_member_2', 'u.oshi_member_3'];
        if ($oshiMode === 'and') {
            // 全員が3スロットのいずれかに含まれる必要がある
            foreach ($oshis as $name) {
                if ($primary !== '' && $primary === $name) continue; // 第一推しと同一なら重複条件不要
                $where[]  = '(' . implode(' OR ', array_map(fn($c) => "$c = ?", $oshiCols)) . ')';
                $params[] = $name; $params[] = $name; $params[] = $name;
            }
        } else {
            // OR: いずれか1人がいずれかのスロットに含まれる
            $clauses = [];
            foreach ($oshis as $name) {
                foreach ($oshiCols as $c) {
                    $clauses[] = "$c = ?";
                    $params[]  = $name;
                }
            }
            if ($clauses) $where[] = '(' . implode(' OR ', $clauses) . ')';
        }
    }

    $sql = "SELECT $selectCols
            FROM sakulabo_users u
            LEFT JOIN buddies_profiles bp ON bp.user_id = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $st = db()->prepare($sql);
    $st->execute($params);
    ok(array_map(fn($r) => buildRowData($r), $st->fetchAll()));
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  おすすめ（スコアリング：第一推し＞推しメン＞興味＞年齢＞都道府県＞SNSスタンス）
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionSimilar(): void {
    $me = currentUser();
    if (!$me) { ok([]); return; }

    $myId  = (int)$me['id'];
    $limit = min((int)($_GET['limit'] ?? 25), 50);

    // 自分のプロフィールデータを取得
    $myBp      = getBuddiesProfile($myId);
    $myOshi1   = $me['oshi_member']   ?? null;
    $myOshi2   = $me['oshi_member_2'] ?? null;
    $myOshi3   = $me['oshi_member_3'] ?? null;
    $myOshis   = array_values(array_filter([$myOshi1, $myOshi2, $myOshi3]));
    $myTags    = ($myBp && $myBp['tags'])   ? (json_decode($myBp['tags'],   true) ?? []) : [];
    $myLocation = $myBp['location']         ?? null;
    $myStance  = $myBp['follow_stance']     ?? null;
    $myAge     = null;
    if ($myBp) {
        $bd    = $myBp['birthday'] ?? null;
        $myAge = $bd ? calcAgeFromBirthday($bd) : ($myBp['age'] !== null ? (int)$myBp['age'] : null);
    }

    // お気に入り・交換済みのIDセットを取得（優先度を下げるため）
    $favSt = db()->prepare('SELECT target_id FROM buddies_favorites WHERE user_id = ?');
    $favSt->execute([$myId]);
    $favIds = array_map('intval', array_column($favSt->fetchAll(), 'target_id'));

    $exSt  = db()->prepare('SELECT target_id FROM buddies_exchanges WHERE user_id = ?');
    $exSt->execute([$myId]);
    $exIds = array_map('intval', array_column($exSt->fetchAll(), 'target_id'));

    $deprioritized = array_flip(array_merge($favIds, $exIds));

    // お気に入りした人たちの推しメン・タグ・都道府県を集約（類似ユーザー検出用）
    $favOshiCount     = []; // name => count
    $favTagCount      = []; // tag  => count
    $favLocationCount = []; // pref => count
    if (!empty($favIds)) {
        $ph = implode(',', array_fill(0, count($favIds), '?'));
        $favSt2 = db()->prepare("
            SELECT u.oshi_member, u.oshi_member_2, u.oshi_member_3,
                   bp.tags, bp.location
            FROM sakulabo_users u
            LEFT JOIN buddies_profiles bp ON bp.user_id = u.id
            WHERE u.id IN ($ph)");
        $favSt2->execute($favIds);
        foreach ($favSt2->fetchAll() as $fr) {
            foreach ([$fr['oshi_member'], $fr['oshi_member_2'], $fr['oshi_member_3']] as $o) {
                if ($o) $favOshiCount[$o] = ($favOshiCount[$o] ?? 0) + 1;
            }
            $tg = $fr['tags'] ? (json_decode($fr['tags'], true) ?? []) : [];
            foreach ($tg as $t) {
                if (is_string($t) && $t !== '') $favTagCount[$t] = ($favTagCount[$t] ?? 0) + 1;
            }
            if (!empty($fr['location'])) {
                $favLocationCount[$fr['location']] = ($favLocationCount[$fr['location']] ?? 0) + 1;
            }
        }
    }

    $selectCols = '
        u.id, u.username, u.display_name, u.oshi_member, u.oshi_member_2, u.oshi_member_3,
        u.user_icon,
        bp.birthday, bp.age, bp.gender, bp.location, bp.buddies_since, bp.bio,
        bp.tags, bp.favorite_songs, bp.sns_links, bp.follow_stance, bp.post_template';

    // 候補を最大150件をランダム取得し PHP 側でスコアリング
    $st = db()->prepare("
        SELECT $selectCols
        FROM sakulabo_users u
        LEFT JOIN buddies_profiles bp ON bp.user_id = u.id
        WHERE u.id != ?
        ORDER BY RAND() LIMIT 150");
    $st->execute([$myId]);
    $candidates = $st->fetchAll();

    $scored = [];
    foreach ($candidates as $c) {
        $score   = 0.0;
        $reasons = [];
        $cId     = (int)$c['id'];

        // ① 第一推しが一致（最高優先度: 15点）
        $cOshi1 = $c['oshi_member'] ?? null;
        if ($myOshi1 && $cOshi1 && $myOshi1 === $cOshi1) {
            $score  += 15;
            $reasons[] = '第一推しが一緒';
        }

        // ② 順番関係なく推しメンが被っている（1人につき5点）
        $cOshis      = array_values(array_filter([$c['oshi_member'] ?? null, $c['oshi_member_2'] ?? null, $c['oshi_member_3'] ?? null]));
        $oshiOverlap = array_intersect($myOshis, $cOshis);
        // 第一推し一致分はここでは加点しない（①で済み）
        $extraOverlap = $oshiOverlap;
        if ($myOshi1 && $cOshi1 && $myOshi1 === $cOshi1) {
            $extraOverlap = array_diff($oshiOverlap, [$myOshi1]);
        }
        if ($extraOverlap) {
            $score  += count($extraOverlap) * 5;
            $reasons[] = '推しメンが一緒';
        } elseif ($oshiOverlap && !in_array('第一推しが一緒', $reasons)) {
            // 第一推し同士の一致はないが被りあり
            $score  += count($oshiOverlap) * 5;
            $reasons[] = '推しメンが一緒';
        }

        // ③ 興味・タグ一致（1タグ3点）
        $cTags      = ($c['tags']) ? (json_decode($c['tags'], true) ?? []) : [];
        $tagOverlap = array_intersect($myTags, $cTags);
        if ($tagOverlap) {
            $score  += count($tagOverlap) * 3;
            $reasons[] = '興味が一緒';
        }

        // ④ 年齢一致（同い年4点、±1歳2点）
        $cAge = null;
        if (!empty($c['birthday'])) {
            $cAge = calcAgeFromBirthday($c['birthday']);
        } elseif ($c['age'] !== null) {
            $cAge = (int)$c['age'];
        }
        if ($myAge !== null && $cAge !== null) {
            $diff = abs($myAge - $cAge);
            if ($diff === 0)      { $score += 4; $reasons[] = '年齢が一緒'; }
            elseif ($diff === 1)  { $score += 2; }
        }

        // ⑤ 都道府県一致（4点）
        if ($myLocation && !empty($c['location']) && $myLocation === $c['location']) {
            $score  += 4;
            $reasons[] = '都道府県が一緒';
        }

        // ⑥ SNSフォロースタンス一致（2点）
        if ($myStance && !empty($c['follow_stance']) && $myStance === $c['follow_stance']) {
            $score  += 2;
            $reasons[] = 'SNSスタンスが一緒';
        }

        // ⑦ お気に入りした人に似ている（推しメン傾向 / タグ傾向 / 都道府県）
        if (!empty($favOshiCount)) {
            $simBoost = 0;
            foreach ($cOshis as $o) {
                if (isset($favOshiCount[$o])) $simBoost += min(3, $favOshiCount[$o]) * 2;
            }
            if (!empty($cTags)) {
                foreach ($cTags as $t) {
                    if (isset($favTagCount[$t])) $simBoost += min(2, $favTagCount[$t]);
                }
            }
            if (!empty($c['location']) && isset($favLocationCount[$c['location']])) {
                $simBoost += min(2, $favLocationCount[$c['location']]);
            }
            if ($simBoost > 0) {
                $score += min(10, $simBoost);
                $reasons[] = 'お気に入りした人に似ている';
            }
        }

        // ⑧ SNSリンクを登録している人を優先（最大3点）
        $cSns = ($c['sns_links']) ? (json_decode($c['sns_links'], true) ?? []) : [];
        $cSns = is_array($cSns) ? array_values(array_filter($cSns, fn($s) => is_array($s) && !empty($s['url']))) : [];
        if (count($cSns) > 0) {
            $score += min(3, count($cSns));
            $reasons[] = 'SNSを登録している';
        }

        // お気に入り・交換済みは後方へ。ただし共通点があればスコア最低1を保証
        if (isset($deprioritized[$cId])) {
            if ($score > 0) {
                $score = max(1.0, $score - 50);
            } else {
                $score -= 50;
            }
        }

        // 同スコア内でシャッフルされるよう小さなランダムジッター（0〜2）を加える
        $score += mt_rand(0, 200) / 100.0;

        $row = buildRowData($c);
        $row['match_score']   = $score;
        $row['match_reasons'] = array_values(array_unique($reasons));
        $scored[] = $row;
    }

    // スコア降順ソート
    usort($scored, fn($a, $b) => $b['match_score'] <=> $a['match_score']);

    ok(array_slice($scored, 0, $limit));
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  ブログ画像一覧（アイコン選択用）
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionBlogImages(): void {
    $me = requireAuth();

    $memberFilter = trim($_GET['member'] ?? '');
    $sort         = $_GET['sort'] ?? 'newest';
    $limit        = min((int)($_GET['limit'] ?? 200), 500);
    $offset       = max((int)($_GET['offset'] ?? 0), 0);
    $favoriteOnly = !empty($_GET['favorite_only']) && $_GET['favorite_only'] !== '0';

    // ─ お気に入り画像のみ ─（sakulabo_blog_image_likes から取得）
    if ($favoriteOnly) {
        try {
            $st = db()->prepare(
                'SELECT image_url, blog_id, created_at
                 FROM sakulabo_blog_image_likes
                 WHERE user_id = ?
                 ORDER BY created_at ' . ($sort === 'oldest' ? 'ASC' : 'DESC')
            );
            $st->execute([(int)$me['id']]);
            $rows = $st->fetchAll();
        } catch (\Throwable $e) { $rows = []; }

        // blog_id → blog（member, date）の対応を作る
        $blogsPath = BLOG_DATA_PATH;
        $blogByLink = [];
        if (file_exists($blogsPath)) {
            $blogs = json_decode(file_get_contents($blogsPath), true);
            if (is_array($blogs)) {
                foreach ($blogs as $b) {
                    if (!empty($b['link'])) $blogByLink[$b['link']] = $b;
                }
            }
        }

        $items = [];
        foreach ($rows as $r) {
            $b = $blogByLink[$r['blog_id']] ?? null;
            $member = $b['member'] ?? '';
            if ($memberFilter !== '' && $member !== $memberFilter) continue;
            $items[] = [
                'url'    => $r['image_url'],
                'member' => $member,
                'date'   => $b['date'] ?? '',
            ];
        }
        ok(['total' => count($items), 'items' => array_slice($items, $offset, $limit)]);
        return;
    }

    $blogsPath = BLOG_DATA_PATH;
    if (!file_exists($blogsPath)) { ok(['total' => 0, 'items' => []]); return; }
    $blogs = json_decode(file_get_contents($blogsPath), true);
    if (!is_array($blogs)) { ok(['total' => 0, 'items' => []]); return; }

    if ($memberFilter !== '') {
        $blogs = array_values(array_filter($blogs, fn($b) => ($b['member'] ?? '') === $memberFilter));
    }

    usort($blogs, fn($a, $b) =>
        $sort === 'oldest'
            ? strcmp($a['date'] ?? '', $b['date'] ?? '')
            : strcmp($b['date'] ?? '', $a['date'] ?? '')
    );

    $images = [];
    foreach ($blogs as $blog) {
        $blogImages = $blog['images'] ?? [];
        if (!is_array($blogImages)) $blogImages = [];
        foreach ($blogImages as $imgUrl) {
            if (!is_string($imgUrl) || $imgUrl === '') continue;
            $images[] = ['url' => $imgUrl, 'member' => $blog['member'] ?? '', 'date' => $blog['date'] ?? ''];
        }
        if (empty($blogImages) && !empty($blog['image'])) {
            $images[] = ['url' => $blog['image'], 'member' => $blog['member'] ?? '', 'date' => $blog['date'] ?? ''];
        }
    }

    // 重複排除
    $seen = [];
    $unique = [];
    foreach ($images as $img) {
        if (!isset($seen[$img['url']])) {
            $seen[$img['url']] = true;
            $unique[] = $img;
        }
    }

    ok(['total' => count($unique), 'items' => array_slice($unique, $offset, $limit)]);
}
