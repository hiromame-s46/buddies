<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
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
 *   buddies_public_profile_get GET  公開プロフィール取得（一般ユーザー用 view.html）
 *   buddies_profile_update    POST Buddiesプロフィール更新
 *   buddies_next_live_update  POST NEXT LIVE参加予定・座席情報更新
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
define('SCHEMA_FLAG', __DIR__ . '/.buddies_schema_v13.lock');
define('BLOG_DATA_PATH', __DIR__ . '/../data/blogs.json');
define('SAKUMIMI_DATA_PATH', __DIR__ . '/../data/sakumimi_data.json');
define('MEMBER_DATA_PATH', __DIR__ . '/../data/member.json');
define('SAKUMAP_EVENTS_PATH', __DIR__ . '/../sakumap/events.json');
define('SAKUMAP_STAMPS_PATH', __DIR__ . '/../sakumap/stamps.json');
define('SAKUMAP_BASE_URL', 'https://buddies46.stars.ne.jp/satellite/sakumap/');

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
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Session-Token, X-Verified-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
set_exception_handler(function(\Throwable $e): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'サーバーエラーが発生しました。時間をおいて再度お試しください。',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

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
    } else {
        ensureBuddiesProfileSakulaboColumns($pdo);
        ensureBuddiesHistoryTable($pdo);
        ensureVerifiedCollaboratorTables($pdo);
    }
    return $pdo;
}

function tableColumns(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query('DESCRIBE `' . str_replace('`', '``', $table) . '`')->fetchAll();
        return array_column($rows, 'Field');
    } catch (\Throwable $e) {
        return [];
    }
}

function ensureVerifiedCollaboratorTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_verified_collaborators (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'manager',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        invited_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
        accepted_at DATETIME NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_account_user (account_id, user_id),
        KEY idx_user_status (user_id, status),
        KEY idx_account_status (account_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_verified_collab_invites (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        target_user_id BIGINT UNSIGNED NOT NULL,
        invited_by_user_id BIGINT UNSIGNED NOT NULL,
        token VARCHAR(128) NOT NULL UNIQUE,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        expires_at DATETIME NOT NULL,
        accepted_at DATETIME NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_token_status (token, status),
        KEY idx_account_status (account_id, status),
        KEY idx_target_status (target_user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureBuddiesProfileSakulaboColumns(PDO $pdo): void {
    $cols = tableColumns($pdo, 'buddies_profiles');
    if (!$cols) return;

    $alterMap = [
        'show_favorite_mimis' => "ALTER TABLE buddies_profiles ADD COLUMN show_favorite_mimis TINYINT(1) NOT NULL DEFAULT 0 COMMENT '公開プロフィールにさくみみお気に入りを表示'",
        'show_favorite_blogs' => "ALTER TABLE buddies_profiles ADD COLUMN show_favorite_blogs TINYINT(1) NOT NULL DEFAULT 0 COMMENT '公開プロフィールにブログお気に入りを表示'",
        'show_sakumap_stamps' => "ALTER TABLE buddies_profiles ADD COLUMN show_sakumap_stamps TINYINT(1) NOT NULL DEFAULT 0 COMMENT '公開プロフィールにSakuMap獲得スタンプを表示'",
        'show_sakumv_quiz' => "ALTER TABLE buddies_profiles ADD COLUMN show_sakumv_quiz TINYINT(1) NOT NULL DEFAULT 1 COMMENT '公開プロフィールにSakuMV Quiz自己ベストを表示'",
        'next_lives' => "ALTER TABLE buddies_profiles ADD COLUMN next_lives TEXT NULL DEFAULT NULL COMMENT 'JSON array of selected future live performance ids' AFTER favorite_songs",
        'next_live_seats' => "ALTER TABLE buddies_profiles ADD COLUMN next_live_seats TEXT NULL DEFAULT NULL COMMENT 'JSON object of seat info by live id' AFTER next_lives",
    ];

    foreach ($alterMap as $col => $sql) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec($sql);
            $cols[] = $col;
        }
    }
}

function ensureBuddiesHistoryTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_history (
        id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id        BIGINT UNSIGNED NOT NULL,
        happened_year  SMALLINT UNSIGNED NOT NULL,
        happened_month TINYINT UNSIGNED NULL DEFAULT NULL,
        happened_day   TINYINT UNSIGNED NULL DEFAULT NULL,
        title          VARCHAR(160) NOT NULL,
        note           VARCHAR(300) NULL DEFAULT NULL,
        live_id        VARCHAR(260) NULL DEFAULT NULL,
        image_url      VARCHAR(2048) NULL DEFAULT NULL,
        image_mime     VARCHAR(64) NULL DEFAULT NULL,
        image_size     INT UNSIGNED NULL DEFAULT NULL,
        sort_order     INT NOT NULL DEFAULT 0,
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_user_date (user_id, happened_year, happened_month, happened_day),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $cols = tableColumns($pdo, 'buddies_history');
    $alterMap = [
        'live_id' => "ALTER TABLE buddies_history ADD COLUMN live_id VARCHAR(260) NULL DEFAULT NULL AFTER note",
        'image_url' => "ALTER TABLE buddies_history ADD COLUMN image_url VARCHAR(2048) NULL DEFAULT NULL AFTER note",
        'image_mime' => "ALTER TABLE buddies_history ADD COLUMN image_mime VARCHAR(64) NULL DEFAULT NULL AFTER image_url",
        'image_size' => "ALTER TABLE buddies_history ADD COLUMN image_size INT UNSIGNED NULL DEFAULT NULL AFTER image_mime",
    ];
    foreach ($alterMap as $col => $sql) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec($sql);
            $cols[] = $col;
        }
    }
}

function runMigrations(PDO $pdo): void {
    // ─ sakulabo_users に Buddies 拡張カラムを追加 ─
    $cols = tableColumns($pdo, 'sakulabo_users');

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
        next_lives     TEXT NULL DEFAULT NULL COMMENT 'JSON array of selected future live performance ids',
        next_live_seats TEXT NULL DEFAULT NULL COMMENT 'JSON object of seat info by live id',
        sns_links      TEXT NULL DEFAULT NULL COMMENT 'JSON array of {type,url}',
        follow_stance  VARCHAR(20) NULL DEFAULT NULL COMMENT 'silent_ok / hello_please',
        post_template  TEXT NULL DEFAULT NULL COMMENT 'SNS紹介テンプレート',
        show_favorite_mimis TINYINT(1) NOT NULL DEFAULT 0 COMMENT '公開プロフィールにさくみみお気に入りを表示',
        show_favorite_blogs TINYINT(1) NOT NULL DEFAULT 0 COMMENT '公開プロフィールにブログお気に入りを表示',
        show_sakumap_stamps TINYINT(1) NOT NULL DEFAULT 0 COMMENT '公開プロフィールにSakuMap獲得スタンプを表示',
        show_sakumv_quiz TINYINT(1) NOT NULL DEFAULT 1 COMMENT '公開プロフィールにSakuMV Quiz自己ベストを表示',
        updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ─ 既存テーブルへのカラム追加（冪等・新カラムのみ） ─
    $bpCols = tableColumns($pdo, 'buddies_profiles');
    $alterMap = [
        'follow_stance' => "ALTER TABLE buddies_profiles ADD COLUMN follow_stance VARCHAR(20) NULL DEFAULT NULL",
        'post_template' => "ALTER TABLE buddies_profiles ADD COLUMN post_template TEXT NULL DEFAULT NULL",
        'birthday'      => "ALTER TABLE buddies_profiles ADD COLUMN birthday DATE NULL DEFAULT NULL",
        'next_lives'    => "ALTER TABLE buddies_profiles ADD COLUMN next_lives TEXT NULL DEFAULT NULL COMMENT 'JSON array of selected future live performance ids' AFTER favorite_songs",
        'next_live_seats' => "ALTER TABLE buddies_profiles ADD COLUMN next_live_seats TEXT NULL DEFAULT NULL COMMENT 'JSON object of seat info by live id' AFTER next_lives",
        'show_favorite_mimis' => "ALTER TABLE buddies_profiles ADD COLUMN show_favorite_mimis TINYINT(1) NOT NULL DEFAULT 0 COMMENT '公開プロフィールにさくみみお気に入りを表示'",
        'show_favorite_blogs' => "ALTER TABLE buddies_profiles ADD COLUMN show_favorite_blogs TINYINT(1) NOT NULL DEFAULT 0 COMMENT '公開プロフィールにブログお気に入りを表示'",
        'show_sakumap_stamps' => "ALTER TABLE buddies_profiles ADD COLUMN show_sakumap_stamps TINYINT(1) NOT NULL DEFAULT 0 COMMENT '公開プロフィールにSakuMap獲得スタンプを表示'",
        'show_sakumv_quiz' => "ALTER TABLE buddies_profiles ADD COLUMN show_sakumv_quiz TINYINT(1) NOT NULL DEFAULT 1 COMMENT '公開プロフィールにSakuMV Quiz自己ベストを表示'",
    ];
    foreach ($alterMap as $col => $sql) {
        if (!in_array($col, $bpCols, true)) {
            $pdo->exec($sql);
            $bpCols[] = $col;
        }
    }
    ensureBuddiesProfileSakulaboColumns($pdo);
    ensureBuddiesHistoryTable($pdo);

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

    // ─ コミュニティアカウント（協力・イベント用） ─
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_verified_accounts (
        id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id                  BIGINT UNSIGNED NULL DEFAULT NULL UNIQUE,
        login_id                 VARCHAR(64) NOT NULL UNIQUE,
        password_hash            VARCHAR(255) NOT NULL,
        display_name             VARCHAR(100) NOT NULL,
        account_type             VARCHAR(32) NOT NULL DEFAULT 'verified',
        label                    VARCHAR(80) NOT NULL DEFAULT 'コミュニティアカウント',
        description              TEXT NULL DEFAULT NULL,
        icon_url                 VARCHAR(2048) NULL DEFAULT NULL,
        banner_url               VARCHAR(2048) NULL DEFAULT NULL,
        primary_link_url         VARCHAR(2048) NULL DEFAULT NULL,
        primary_link_label       VARCHAR(80) NULL DEFAULT NULL,
        secondary_link_url       VARCHAR(2048) NULL DEFAULT NULL,
        secondary_link_label     VARCHAR(80) NULL DEFAULT NULL,
        x_url                    VARCHAR(2048) NULL DEFAULT NULL,
        sns_links                TEXT NULL DEFAULT NULL COMMENT 'JSON array of {type,url,label}',
        cta_label                VARCHAR(80) NULL DEFAULT NULL,
        cta_url                  VARCHAR(2048) NULL DEFAULT NULL,
        promotion_title          VARCHAR(160) NULL DEFAULT NULL,
        promotion_body           TEXT NULL DEFAULT NULL,
        hashtags                 TEXT NULL DEFAULT NULL COMMENT 'JSON array',
        recommend_priority       INT NOT NULL DEFAULT 0,
        status                   VARCHAR(20) NOT NULL DEFAULT 'active',
        created_by_admin_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
        last_login_at            DATETIME NULL DEFAULT NULL,
        created_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_status_priority (status, recommend_priority),
        KEY idx_user (user_id),
        KEY idx_login (login_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $verifiedCols = array_column($pdo->query('DESCRIBE buddies_verified_accounts')->fetchAll(), 'Field');
    if (!in_array('user_id', $verifiedCols, true)) {
        $pdo->exec("ALTER TABLE buddies_verified_accounts ADD COLUMN user_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER id");
    }
    if (!in_array('sns_links', $verifiedCols, true)) {
        $pdo->exec("ALTER TABLE buddies_verified_accounts ADD COLUMN sns_links TEXT NULL DEFAULT NULL COMMENT 'JSON array of {type,url,label}' AFTER x_url");
    }
    if (!in_array('cta_label', $verifiedCols, true)) {
        $pdo->exec("ALTER TABLE buddies_verified_accounts ADD COLUMN cta_label VARCHAR(80) NULL DEFAULT NULL AFTER sns_links");
    }
    if (!in_array('cta_url', $verifiedCols, true)) {
        $pdo->exec("ALTER TABLE buddies_verified_accounts ADD COLUMN cta_url VARCHAR(2048) NULL DEFAULT NULL AFTER cta_label");
    }
    try {
        $idx = $pdo->query("SHOW INDEX FROM buddies_verified_accounts WHERE Key_name='uq_verified_user_id'")->fetchAll();
        if (!$idx) $pdo->exec("ALTER TABLE buddies_verified_accounts ADD UNIQUE KEY uq_verified_user_id (user_id)");
    } catch (\Throwable $e) {}
    try {
        $idx = $pdo->query("SHOW INDEX FROM buddies_verified_accounts WHERE Key_name='uq_verified_login_id'")->fetchAll();
        if (!$idx) $pdo->exec("ALTER TABLE buddies_verified_accounts ADD UNIQUE KEY uq_verified_login_id (login_id)");
    } catch (\Throwable $e) {}
    try {
        $pdo->exec("UPDATE buddies_verified_accounts a
                   JOIN sakulabo_users u ON u.username = a.login_id
                   SET a.user_id = u.id
                   WHERE a.user_id IS NULL");
    } catch (\Throwable $e) {}
    try {
        $pdo->exec("UPDATE buddies_verified_accounts
                   SET account_type = 'verified',
                       label = CASE
                           WHEN label IN ('コミュニティパートナー', 'プロアカウント', '認証アカウント', '') THEN 'コミュニティアカウント'
                           ELSE label
                       END
                   WHERE account_type IN ('official', 'official_account', 'official_collab', 'event', 'partner')");
    } catch (\Throwable $e) {}

    // ─ イベント ─
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_events (
        id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id    BIGINT UNSIGNED NOT NULL COMMENT '主催コミュニティアカウント',
        title         VARCHAR(160) NOT NULL,
        description   TEXT NULL DEFAULT NULL,
        venue         VARCHAR(200) NULL DEFAULT NULL,
        starts_at     DATETIME NULL DEFAULT NULL,
        ends_at       DATETIME NULL DEFAULT NULL,
        cover_url     VARCHAR(2048) NULL DEFAULT NULL,
        attachments   TEXT NULL DEFAULT NULL COMMENT 'JSON array of event files',
        capacity      INT NULL DEFAULT NULL,
        external_url  VARCHAR(2048) NULL DEFAULT NULL,
        external_label VARCHAR(80) NULL DEFAULT NULL,
        external_button_highlight TINYINT(1) NOT NULL DEFAULT 0,
        participant_enabled TINYINT(1) NOT NULL DEFAULT 1,
        checkin_enabled TINYINT(1) NOT NULL DEFAULT 0,
        fee_items TEXT NULL DEFAULT NULL COMMENT 'JSON array of {label,amount}',
        visibility    VARCHAR(16) NOT NULL DEFAULT 'public' COMMENT 'public | unlisted',
        status        VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_account (account_id, status),
        KEY idx_starts (starts_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $eventCols = array_column($pdo->query('DESCRIBE buddies_events')->fetchAll(), 'Field');
    if (!in_array('visibility', $eventCols, true)) {
        $pdo->exec("ALTER TABLE buddies_events ADD COLUMN visibility VARCHAR(16) NOT NULL DEFAULT 'public' AFTER external_label");
    }
    if (!in_array('attachments', $eventCols, true)) {
        $pdo->exec("ALTER TABLE buddies_events ADD COLUMN attachments TEXT NULL DEFAULT NULL COMMENT 'JSON array of event files' AFTER cover_url");
    }
    if (!in_array('external_button_highlight', $eventCols, true)) {
        $pdo->exec("ALTER TABLE buddies_events ADD COLUMN external_button_highlight TINYINT(1) NOT NULL DEFAULT 0 AFTER external_label");
    }
    if (!in_array('participant_enabled', $eventCols, true)) {
        $pdo->exec("ALTER TABLE buddies_events ADD COLUMN participant_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER external_button_highlight");
    }
    if (!in_array('checkin_enabled', $eventCols, true)) {
        $pdo->exec("ALTER TABLE buddies_events ADD COLUMN checkin_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER participant_enabled");
    }
    if (!in_array('fee_items', $eventCols, true)) {
        $pdo->exec("ALTER TABLE buddies_events ADD COLUMN fee_items TEXT NULL DEFAULT NULL COMMENT 'JSON array of {label,amount}' AFTER checkin_enabled");
    }

    // ─ コミュニティアカウント掲示板 ─
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_verified_posts (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id  BIGINT UNSIGNED NOT NULL,
        body        TEXT NULL DEFAULT NULL,
        link_url    VARCHAR(2048) NULL DEFAULT NULL,
        link_label  VARCHAR(120) NULL DEFAULT NULL,
        files       TEXT NULL DEFAULT NULL COMMENT 'JSON array of post files',
        file_url    VARCHAR(2048) NULL DEFAULT NULL,
        file_name   VARCHAR(160) NULL DEFAULT NULL,
        file_mime   VARCHAR(80) NULL DEFAULT NULL,
        file_size   INT UNSIGNED NULL DEFAULT NULL,
        status      VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_account_status_created (account_id, status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ─ イベント参加（join=公開, like=非公開いいね） ─
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_event_participants (
        id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id   BIGINT UNSIGNED NOT NULL,
        user_id    BIGINT UNSIGNED NOT NULL,
        kind       VARCHAR(10) NOT NULL DEFAULT 'join' COMMENT 'join | like',
        checked_in_at DATETIME NULL DEFAULT NULL,
        checked_in_by_account_id BIGINT UNSIGNED NULL DEFAULT NULL,
        registered_on_site TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_event_user_kind (event_id, user_id, kind),
        KEY idx_event_kind (event_id, kind),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ─ サブイベントと参加者 ─
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_subevents (
        id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id       BIGINT UNSIGNED NOT NULL,
        title          VARCHAR(160) NOT NULL,
        description    TEXT NULL DEFAULT NULL,
        venue          VARCHAR(200) NULL DEFAULT NULL,
        starts_at      DATETIME NULL DEFAULT NULL,
        ends_at        DATETIME NULL DEFAULT NULL,
        cover_url      VARCHAR(2048) NULL DEFAULT NULL,
        capacity       INT NULL DEFAULT NULL,
        external_url   VARCHAR(2048) NULL DEFAULT NULL,
        external_label VARCHAR(80) NULL DEFAULT NULL,
        external_button_highlight TINYINT(1) NOT NULL DEFAULT 0,
        participant_enabled TINYINT(1) NOT NULL DEFAULT 1,
        sort_order     INT NOT NULL DEFAULT 0,
        status         VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_event (event_id, status),
        KEY idx_starts (starts_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_subevent_participants (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        subevent_id BIGINT UNSIGNED NOT NULL,
        user_id     BIGINT UNSIGNED NOT NULL,
        checked_in_at DATETIME NULL DEFAULT NULL,
        checked_in_by_account_id BIGINT UNSIGNED NULL DEFAULT NULL,
        registered_on_site TINYINT(1) NOT NULL DEFAULT 0,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_subevent_user (subevent_id, user_id),
        KEY idx_subevent (subevent_id),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ─ コミュニティフォームと回答 ─
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_forms (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(160) NOT NULL,
        description TEXT NULL DEFAULT NULL,
        questions TEXT NOT NULL,
        form_mode VARCHAR(16) NOT NULL DEFAULT 'form',
        allow_anonymous_vote TINYINT(1) NOT NULL DEFAULT 0,
        show_results TINYINT(1) NOT NULL DEFAULT 0,
        visibility VARCHAR(16) NOT NULL DEFAULT 'public',
        collect_profile TINYINT(1) NOT NULL DEFAULT 0,
        one_response TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_account (account_id, status),
        KEY idx_visibility (visibility, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_form_responses (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        form_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NULL DEFAULT NULL,
        answers TEXT NOT NULL,
        respondent_name VARCHAR(120) NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_form (form_id),
        KEY idx_form_user (form_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $formCols = array_column($pdo->query('DESCRIBE buddies_forms')->fetchAll(), 'Field');
        if (!in_array('form_mode', $formCols, true)) {
            $pdo->exec("ALTER TABLE buddies_forms ADD COLUMN form_mode VARCHAR(16) NOT NULL DEFAULT 'form' AFTER questions");
        }
        if (!in_array('allow_anonymous_vote', $formCols, true)) {
            $pdo->exec("ALTER TABLE buddies_forms ADD COLUMN allow_anonymous_vote TINYINT(1) NOT NULL DEFAULT 0 AFTER form_mode");
        }
        if (!in_array('show_results', $formCols, true)) {
            $pdo->exec("ALTER TABLE buddies_forms ADD COLUMN show_results TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_anonymous_vote");
        }
    } catch (\Throwable $e) {}

    try {
        $participantCols = array_column($pdo->query('DESCRIBE buddies_event_participants')->fetchAll(), 'Field');
        if (!in_array('checked_in_at', $participantCols, true)) {
            $pdo->exec("ALTER TABLE buddies_event_participants ADD COLUMN checked_in_at DATETIME NULL DEFAULT NULL AFTER kind");
        }
        if (!in_array('checked_in_by_account_id', $participantCols, true)) {
            $pdo->exec("ALTER TABLE buddies_event_participants ADD COLUMN checked_in_by_account_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER checked_in_at");
        }
        if (!in_array('registered_on_site', $participantCols, true)) {
            $pdo->exec("ALTER TABLE buddies_event_participants ADD COLUMN registered_on_site TINYINT(1) NOT NULL DEFAULT 0 AFTER checked_in_by_account_id");
        }
        $subParticipantCols = array_column($pdo->query('DESCRIBE buddies_subevent_participants')->fetchAll(), 'Field');
        if (!in_array('checked_in_at', $subParticipantCols, true)) {
            $pdo->exec("ALTER TABLE buddies_subevent_participants ADD COLUMN checked_in_at DATETIME NULL DEFAULT NULL AFTER user_id");
        }
        if (!in_array('checked_in_by_account_id', $subParticipantCols, true)) {
            $pdo->exec("ALTER TABLE buddies_subevent_participants ADD COLUMN checked_in_by_account_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER checked_in_at");
        }
        if (!in_array('registered_on_site', $subParticipantCols, true)) {
            $pdo->exec("ALTER TABLE buddies_subevent_participants ADD COLUMN registered_on_site TINYINT(1) NOT NULL DEFAULT 0 AFTER checked_in_by_account_id");
        }
    } catch (\Throwable $e) {}
    // 既存テーブルの UNIQUE 制約を (event_id, user_id) → (event_id, user_id, kind) に置き換え
    try {
        $idx = $pdo->query("SHOW INDEX FROM buddies_event_participants WHERE Key_name='uq_event_user'")->fetchAll();
        if ($idx) {
            $pdo->exec("ALTER TABLE buddies_event_participants DROP INDEX uq_event_user");
            $pdo->exec("ALTER TABLE buddies_event_participants ADD UNIQUE KEY uq_event_user_kind (event_id, user_id, kind)");
        }
        $subCols = array_column($pdo->query('DESCRIBE buddies_subevents')->fetchAll(), 'Field');
        $subRequired = [
            'description' => "ALTER TABLE buddies_subevents ADD COLUMN description TEXT NULL DEFAULT NULL AFTER title",
            'venue' => "ALTER TABLE buddies_subevents ADD COLUMN venue VARCHAR(200) NULL DEFAULT NULL AFTER description",
            'starts_at' => "ALTER TABLE buddies_subevents ADD COLUMN starts_at DATETIME NULL DEFAULT NULL AFTER venue",
            'ends_at' => "ALTER TABLE buddies_subevents ADD COLUMN ends_at DATETIME NULL DEFAULT NULL AFTER starts_at",
            'cover_url' => "ALTER TABLE buddies_subevents ADD COLUMN cover_url VARCHAR(2048) NULL DEFAULT NULL AFTER ends_at",
            'capacity' => "ALTER TABLE buddies_subevents ADD COLUMN capacity INT NULL DEFAULT NULL AFTER cover_url",
            'external_url' => "ALTER TABLE buddies_subevents ADD COLUMN external_url VARCHAR(2048) NULL DEFAULT NULL AFTER capacity",
            'external_label' => "ALTER TABLE buddies_subevents ADD COLUMN external_label VARCHAR(80) NULL DEFAULT NULL AFTER external_url",
            'external_button_highlight' => "ALTER TABLE buddies_subevents ADD COLUMN external_button_highlight TINYINT(1) NOT NULL DEFAULT 0 AFTER external_label",
            'participant_enabled' => "ALTER TABLE buddies_subevents ADD COLUMN participant_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER external_button_highlight",
            'sort_order' => "ALTER TABLE buddies_subevents ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER external_label",
            'status' => "ALTER TABLE buddies_subevents ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER sort_order",
            'created_at' => "ALTER TABLE buddies_subevents ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status",
            'updated_at' => "ALTER TABLE buddies_subevents ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];
        foreach ($subRequired as $col => $sql) {
            if (!in_array($col, $subCols, true)) $pdo->exec($sql);
        }
    } catch (\Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_verified_sessions (
        id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        token      VARCHAR(128) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_account (account_id),
        KEY idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_verified_collaborators (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'manager',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        invited_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
        accepted_at DATETIME NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_account_user (account_id, user_id),
        KEY idx_user_status (user_id, status),
        KEY idx_account_status (account_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_verified_collab_invites (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        target_user_id BIGINT UNSIGNED NOT NULL,
        invited_by_user_id BIGINT UNSIGNED NOT NULL,
        token VARCHAR(128) NOT NULL UNIQUE,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        expires_at DATETIME NOT NULL,
        accepted_at DATETIME NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_token_status (token, status),
        KEY idx_account_status (account_id, status),
        KEY idx_target_status (target_user_id, status)
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
function qrSigningKey(): string {
    global $config;
    return hash('sha256', (string)($config['password'] ?? '') . '|buddies-profile-qr');
}
function signedQrUserId(string $token): int {
    $parts = explode(':', trim($token));
    if (count($parts) !== 4 || $parts[0] !== 'v2') return 0;
    [, $uidRaw, $expiresRaw, $signature] = $parts;
    $uid = (int)$uidRaw;
    $expires = (int)$expiresRaw;
    if ($uid <= 0 || $expires < time() || $expires > time() + 180) return 0;
    $expected = hash_hmac('sha256', $uid . ':' . $expires, qrSigningKey());
    return hash_equals($expected, $signature) ? $uid : 0;
}
function setSessionCookie(string $token): void {
    setcookie('sakulabo_token', $token, [
        'expires'  => time() + SESSION_EXPIRE_HOURS * 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
function getVerifiedToken(): ?string {
    $h = $_SERVER['HTTP_X_VERIFIED_TOKEN'] ?? ($_COOKIE['buddies_verified_token'] ?? null);
    return $h ? trim($h) : null;
}
function setVerifiedCookie(string $token): void {
    setcookie('buddies_verified_token', $token, [
        'expires'  => time() + SESSION_EXPIRE_HOURS * 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
function verifiedAccountByUserId(int $userId): ?array {
    if ($userId <= 0) return null;
    $st = db()->prepare("SELECT * FROM buddies_verified_accounts WHERE user_id = ? AND status != 'disabled' LIMIT 1");
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}
function verifiedAccountById(int $id): ?array {
    if ($id <= 0) return null;
    $st = db()->prepare("SELECT * FROM buddies_verified_accounts WHERE id = ? AND status != 'disabled' LIMIT 1");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function collaboratorVerifiedAccountByUserId(int $userId): ?array {
    if ($userId <= 0) return null;
    $st = db()->prepare(
        "SELECT a.*, c.role AS _access_role, c.user_id AS _access_user_id
           FROM buddies_verified_collaborators c
           JOIN buddies_verified_accounts a ON a.id = c.account_id
          WHERE c.user_id = ? AND c.status = 'active' AND a.status != 'disabled'
          ORDER BY c.accepted_at DESC, c.created_at DESC
          LIMIT 1"
    );
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}
function markVerifiedOwner(array $a, ?int $userId = null): array {
    $a['_access_role'] = 'owner';
    $a['_access_user_id'] = $userId ?: (isset($a['user_id']) ? (int)$a['user_id'] : null);
    return $a;
}
function developerVerifiedAccount(?array $u = null): ?array {
    $u ??= currentUser();
    if (!isHiromameAdmin($u)) return null;
    $st = db()->prepare('SELECT * FROM buddies_verified_accounts WHERE user_id = ? AND account_type = ? LIMIT 1');
    $st->execute([(int)$u['id'], 'developer']);
    $a = $st->fetch();
    if ($a) return $a;

    $st = db()->prepare('SELECT * FROM buddies_verified_accounts WHERE created_by_admin_user_id = ? AND account_type = ? LIMIT 1');
    $st->execute([(int)$u['id'], 'developer']);
    $a = $st->fetch();
    if ($a) {
        if (empty($a['user_id'])) {
            db()->prepare('UPDATE buddies_verified_accounts SET user_id=? WHERE id=?')->execute([(int)$u['id'], $a['id']]);
            $a['user_id'] = (int)$u['id'];
        }
        return $a;
    }

    $login = $u['username'] ?: ('developer_' . (int)$u['id']);
    $hash = $u['password_hash'] ?? password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare('INSERT INTO buddies_verified_accounts
        (user_id, login_id, password_hash, display_name, account_type, label, description,
         primary_link_label, recommend_priority, status, created_by_admin_user_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([
        (int)$u['id'],
        $login,
        $hash,
        $u['display_name'] ?: 'Hiromame',
        'developer',
        '開発者アカウント',
        'Buddies profile の開発・運営を行うアカウントです。',
        'Buddies profileを見る',
        1000,
        'active',
        (int)$u['id'],
    ]);
    $id = (int)db()->lastInsertId();
    $st = db()->prepare('SELECT * FROM buddies_verified_accounts WHERE id=? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function currentVerifiedAccount(): ?array {
    $u = currentUser();
    if ($u) {
        $a = verifiedAccountByUserId((int)$u['id']);
        if ($a) return markVerifiedOwner($a, (int)$u['id']);
        if (isHiromameAdmin($u)) {
            $dev = developerVerifiedAccount($u);
            if ($dev) return markVerifiedOwner($dev, (int)$u['id']);
        }
        $a = collaboratorVerifiedAccountByUserId((int)$u['id']);
        if ($a) return $a;
    }

    $token = getVerifiedToken();
    if (!$token) return null;
    $st = db()->prepare(
        "SELECT a.* FROM buddies_verified_accounts a
         JOIN buddies_verified_sessions s ON s.account_id = a.id
         WHERE s.token = ? AND s.expires_at > NOW() AND a.status != 'disabled'
         LIMIT 1"
    );
    $st->execute([$token]);
    $a = $st->fetch();
    return $a ? markVerifiedOwner($a, isset($a['user_id']) ? (int)$a['user_id'] : null) : null;
}
function requireVerifiedAccount(): array {
    $a = currentVerifiedAccount();
    if (!$a) err('コミュニティアカウントでのログインが必要です。', 401);
    return $a;
}
function isVerifiedOwner(array $a): bool {
    return ($a['_access_role'] ?? 'owner') === 'owner';
}
function requireVerifiedOwner(): array {
    $a = requireVerifiedAccount();
    if (!isVerifiedOwner($a)) err('この操作はコミュニティアカウント本人のみ利用できます。', 403);
    return $a;
}
function isHiromameAdmin(?array $u): bool {
    return $u && (int)$u['id'] === 1;
}
function requireHiromameAdmin(): array {
    $u = requireAuth();
    if (!isHiromameAdmin($u)) err('開発者アカウント（uid=1）でのログインが必要です。', 403);
    return $u;
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
        $data['next_lives']     = !empty($bp['next_lives']) ? json_decode($bp['next_lives'], true) : [];
        $data['next_live_seats'] = !empty($bp['next_live_seats']) ? (json_decode($bp['next_live_seats'], true) ?: []) : [];
        $data['sns_links']      = $bp['sns_links']      ? json_decode($bp['sns_links'],      true) : [];
        $data['follow_stance']  = $bp['follow_stance'] ?? null;
        $data['post_template']  = $bp['post_template'] ?? null;
        $data['show_favorite_mimis'] = !empty($bp['show_favorite_mimis']);
        $data['show_favorite_blogs'] = !empty($bp['show_favorite_blogs']);
        $data['show_sakumap_stamps'] = !empty($bp['show_sakumap_stamps']);
        $data['show_sakumv_quiz'] = !array_key_exists('show_sakumv_quiz', $bp) || !empty($bp['show_sakumv_quiz']);
    } else {
        $data['birthday']       = null;
        $data['age']            = null;
        $data['gender']         = null;
        $data['location']       = null;
        $data['buddies_since']  = null;
        $data['bio']            = null;
        $data['tags']           = [];
        $data['favorite_songs'] = [];
        $data['next_lives']     = [];
        $data['next_live_seats'] = [];
        $data['sns_links']      = [];
        $data['follow_stance']  = null;
        $data['post_template']  = null;
        $data['show_favorite_mimis'] = false;
        $data['show_favorite_blogs'] = false;
        $data['show_sakumap_stamps'] = false;
        $data['show_sakumv_quiz'] = true;
    }
    $data['sakumap_linked'] = sakumapLinkStatus((int)$u['id'])['linked'];
    $verified = verifiedAccountByUserId((int)$u['id']);
    if (!$verified && isHiromameAdmin($u)) $verified = developerVerifiedAccount($u);
    $data['account_role'] = $verified ? normalizeVerifiedType($verified['account_type'] ?? 'verified') : 'user';
    $data['verified_account'] = $verified ? buildVerifiedData($verified, $includePrivate) : null;
    $data['sakulabo_favorites'] = getSakulaboFavoritesForProfile(
        (int)$u['id'],
        !empty($data['show_favorite_mimis']),
        !empty($data['show_favorite_blogs'])
    );
    $data['sakumap_stamps'] = !empty($data['show_sakumap_stamps'])
        ? getSakumapStampsForProfile((int)$u['id'])
        : [];
    $data['sakumv_quiz_linked'] = hasSakumvQuizRecords((int)$u['id']);
    $data['sakumv_quiz_records'] = (!empty($data['show_sakumv_quiz']) && $data['sakumv_quiz_linked'])
        ? getSakumvQuizBestRecords((int)$u['id'])
        : [];
    $data['buddies_history'] = getBuddiesHistoryForProfile((int)$u['id']);
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
        'next_lives'    => isset($r['next_lives'])     && $r['next_lives']     ? json_decode($r['next_lives'],     true) : [],
        'next_live_seats' => isset($r['next_live_seats']) && $r['next_live_seats'] ? (json_decode($r['next_live_seats'], true) ?: []) : [],
        'sns_links'     => isset($r['sns_links'])      && $r['sns_links']      ? json_decode($r['sns_links'],      true) : [],
        'follow_stance' => $r['follow_stance'] ?? null,
        'post_template' => $r['post_template'] ?? null,
        'show_favorite_mimis' => !empty($r['show_favorite_mimis']),
        'show_favorite_blogs' => !empty($r['show_favorite_blogs']),
        'show_sakumap_stamps' => !empty($r['show_sakumap_stamps']),
        'exchanged_at'  => $r['exchanged_at']  ?? null,
        'favorited_at'  => $r['favorited_at']  ?? null,
    ];
}

function decodeJsonArray(?string $json): array {
    if (!$json) return [];
    $arr = json_decode($json, true);
    return is_array($arr) ? array_values(array_filter($arr, fn($v) => is_string($v) && trim($v) !== '')) : [];
}

function normalizeNextLiveSeats($value, array $allowedLives = []): array {
    if (!is_array($value)) return [];
    $allowed = array_flip(array_map('strval', $allowedLives));
    $out = [];
    foreach ($value as $liveId => $seat) {
        $liveId = trim((string)$liveId);
        if ($liveId === '' || mb_strlen($liveId) > 260) continue;
        if ($allowedLives && !isset($allowed[$liveId])) continue;
        if (!is_array($seat)) continue;
        $floor = preg_replace('/[^0-9]/', '', (string)($seat['floor'] ?? ''));
        $area = trim((string)($seat['area'] ?? ''));
        $block = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($seat['block'] ?? '')));
        if ($area !== '' && !in_array($area, ['arena', 'stand'], true)) $area = '';
        if ($floor === '' && $area === '' && $block === '') continue;
        $out[$liveId] = [
            'floor' => mb_substr($floor, 0, 2),
            'area' => $area,
            'block' => mb_substr($block, 0, 12),
        ];
    }
    return $out;
}

function truthyFlag($value): int {
    if (is_bool($value)) return $value ? 1 : 0;
    if (is_int($value)) return $value ? 1 : 0;
    $v = strtolower(trim((string)$value));
    return in_array($v, ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
}

function getBuddiesHistoryForProfile(int $userId): array {
    try {
        ensureBuddiesHistoryTable(db());
        $st = db()->prepare(
            'SELECT id, happened_year, happened_month, happened_day, title, note, live_id, image_url, image_mime, image_size
               FROM buddies_history
              WHERE user_id = ?
              ORDER BY happened_year DESC,
                       COALESCE(happened_month, 0) DESC,
                       COALESCE(happened_day, 0) DESC,
                       sort_order ASC,
                       id DESC
              LIMIT 50'
        );
        $st->execute([$userId]);
        return array_map(function($r) {
            return [
                'id' => (int)$r['id'],
                'year' => (int)$r['happened_year'],
                'month' => $r['happened_month'] !== null ? (int)$r['happened_month'] : null,
                'day' => $r['happened_day'] !== null ? (int)$r['happened_day'] : null,
                'title' => (string)$r['title'],
                'note' => $r['note'] !== null ? (string)$r['note'] : '',
                'live_id' => $r['live_id'] !== null ? (string)$r['live_id'] : '',
                'image_url' => $r['image_url'] !== null ? (string)$r['image_url'] : '',
                'image_mime' => $r['image_mime'] !== null ? (string)$r['image_mime'] : '',
                'image_size' => $r['image_size'] !== null ? (int)$r['image_size'] : 0,
            ];
        }, $st->fetchAll());
    } catch (\Throwable $e) {
        return [];
    }
}

function normalizeBuddiesHistoryItems($items): array {
    if (!is_array($items)) err('My Buddies history の形式が正しくありません。');
    $items = array_slice($items, 0, 50);
    $out = [];
    $maxYear = (int)date('Y') + 2;
    foreach ($items as $i => $item) {
        if (!is_array($item)) continue;
        $year = (int)($item['year'] ?? 0);
        $month = isset($item['month']) && $item['month'] !== '' && $item['month'] !== null ? (int)$item['month'] : null;
        $day = isset($item['day']) && $item['day'] !== '' && $item['day'] !== null ? (int)$item['day'] : null;
        $title = trim((string)($item['title'] ?? ''));
        $note = trim((string)($item['note'] ?? ''));
        $liveId = trim((string)($item['live_id'] ?? ''));
        if ($year < 1990 || $year > $maxYear) err('My Buddies history の年が正しくありません。');
        if ($month !== null && ($month < 1 || $month > 12)) err('My Buddies history の月が正しくありません。');
        if ($day !== null && $month === null) err('日を入力する場合は月も入力してください。');
        if ($day !== null && ($day < 1 || $day > 31)) err('My Buddies history の日が正しくありません。');
        if ($month !== null && $day !== null && !checkdate($month, $day, $year)) err('My Buddies history の日付が正しくありません。');
        if ($title === '' || mb_strlen($title) > 160) err('My Buddies history のタイトルは1〜160文字で入力してください。');
        if (mb_strlen($note) > 300) err('My Buddies history の一言は300文字以内で入力してください。');
        if (mb_strlen($liveId) > 260) err('紐づけるライブ情報が正しくありません。');
        $out[] = [
            'id' => isset($item['id']) && is_numeric($item['id']) ? (int)$item['id'] : 0,
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'title' => $title,
            'note' => $note,
            'live_id' => $liveId,
            'image_url' => trim((string)($item['image_url'] ?? '')),
            'image_data' => (string)($item['image_data'] ?? ''),
            'sort_order' => $i,
        ];
    }
    usort($out, function($a, $b) {
        return [$b['year'], $b['month'] ?? 0, $b['day'] ?? 0, -$b['sort_order']]
            <=> [$a['year'], $a['month'] ?? 0, $a['day'] ?? 0, -$a['sort_order']];
    });
    return array_values($out);
}

function historyUploadUserDirName(array $user): string {
    $name = trim((string)($user['username'] ?? ('user_' . (int)$user['id'])));
    $name = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $name);
    $name = trim((string)$name, "._-\t\n\r\0\x0B ");
    return $name !== '' ? mb_substr($name, 0, 80) : ('user_' . (int)$user['id']);
}

function historyUploadBaseDir(array $user): string {
    return __DIR__ . '/uploads/history/img/' . historyUploadUserDirName($user);
}

function isHistoryImageUrlForUser(string $url, array $user): bool {
    if ($url === '' || preg_match('/^https?:\/\//', $url)) return false;
    $prefix = 'uploads/history/img/' . historyUploadUserDirName($user) . '/';
    $clean = strtok($url, '?') ?: $url;
    return strpos($clean, $prefix) === 0;
}

function saveHistoryImageData(string $dataUrl, array $user, array &$newFiles): array {
    if (!preg_match('/^data:(image\/png|image\/jpeg|image\/webp);base64,/', $dataUrl, $m)) {
        err('My Buddies history の画像はPNG/JPEG/WebPのみ指定できます。');
    }
    $mime = $m[1];
    $raw = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
    if ($raw === false) err('My Buddies history の画像を読み込めませんでした。');
    if (strlen($raw) > 5 * 1024 * 1024) err('My Buddies history の画像サイズは5MB以内にしてください。');
    if (!@getimagesizefromstring($raw)) err('有効な画像ファイルではありません。');
    $ext = match ($mime) {
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        default => 'webp',
    };
    $dir = historyUploadBaseDir($user);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) err('アップロード先を作成できません。', 500);
    $fileId = bin2hex(random_bytes(16));
    while (is_file($dir . '/' . $fileId . '.' . $ext)) $fileId = bin2hex(random_bytes(16));
    $path = $dir . '/' . $fileId . '.' . $ext;
    if (file_put_contents($path, $raw) === false) err('画像を保存できませんでした。', 500);
    $newFiles[] = $path;
    return [
        'url' => 'uploads/history/img/' . historyUploadUserDirName($user) . '/' . $fileId . '.' . $ext,
        'mime' => $mime,
        'size' => strlen($raw),
    ];
}

function deleteHistoryImageUrl(string $url, array $user): void {
    if (!isHistoryImageUrlForUser($url, $user)) return;
    deletePathInside(__DIR__ . '/' . ltrim((string)(strtok($url, '?') ?: $url), '/'), historyUploadBaseDir($user));
}

function loadBlogIndexById(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    if (!file_exists(BLOG_DATA_PATH)) return $map;
    $blogs = json_decode((string)file_get_contents(BLOG_DATA_PATH), true);
    if (!is_array($blogs)) return $map;
    foreach ($blogs as $b) {
        if (!is_array($b)) continue;
        foreach (['id', 'blog_id', 'link', 'url'] as $key) {
            if (!empty($b[$key])) $map[(string)$b[$key]] = $b;
        }
    }
    return $map;
}

function cleanDisplayText($value): string {
    return trim(preg_replace('/[\s\x{3000}]+/u', ' ', (string)$value));
}

function loadSakumimiIndexById(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    if (!file_exists(SAKUMIMI_DATA_PATH)) return $map;
    $items = json_decode((string)file_get_contents(SAKUMIMI_DATA_PATH), true);
    if (!is_array($items)) return $map;
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        foreach (['id', 'episode_id'] as $key) {
            if (!empty($item[$key])) $map[(string)$item[$key]] = $item;
        }
        if (isset($item['episode']) && $item['episode'] !== '') {
            $map[(string)$item['episode']] = $item;
        }
    }
    return $map;
}

function sakumimiLatestEpisode(): ?int {
    $latest = null;
    foreach (loadSakumimiIndexById() as $item) {
        if (!is_array($item) || !isset($item['episode'])) continue;
        $episode = (int)$item['episode'];
        if ($episode <= 0) continue;
        $latest = $latest === null ? $episode : max($latest, $episode);
    }
    return $latest;
}

function sakumimiOfficialPageUrl($episode, int $perPage): ?string {
    $episode = (int)$episode;
    $latest = sakumimiLatestEpisode();
    if ($episode <= 0 || !$latest) return null;
    $page = max(0, intdiv(max(0, $latest - $episode), $perPage));
    return 'https://sakurazaka46.com/s/s46/diary/radio/list?ima=0000&page=' . $page . '&cd=radio';
}

function compactSakulaboBlogItem(array $row): ?array {
    $blogId = (string)($row['blog_id'] ?? '');
    $blog = loadBlogIndexById()[$blogId] ?? null;
    if (!is_array($blog) || !$blog) return null;
    $url = $blog['link'] ?? $blog['url'] ?? $blogId;
    $title = cleanDisplayText($blog['title'] ?? $blog['name'] ?? '');
    $member = cleanDisplayText($blog['member'] ?? $blog['author'] ?? '');
    $date = cleanDisplayText($blog['date'] ?? $blog['published_at'] ?? '');
    $images = isset($blog['images']) && is_array($blog['images']) ? $blog['images'] : [];
    $image = cleanDisplayText($blog['thumb'] ?? $blog['thumbnail'] ?? $blog['image'] ?? ($images[0] ?? ''));
    if ($title === '') {
        $title = $member !== '' ? $member . 'のブログ' : 'ブログ';
    }
    return [
        'id' => $blogId,
        'title' => $title,
        'member' => $member,
        'date' => $date,
        'url' => filter_var($url, FILTER_VALIDATE_URL) ? $url : null,
        'image_url' => filter_var($image, FILTER_VALIDATE_URL) ? $image : null,
        'created_at' => $row['created_at'] ?? null,
    ];
}

function compactSakulaboMimiItem(array $row): array {
    $episodeId = (string)($row['episode_id'] ?? '');
    $item = loadSakumimiIndexById()[$episodeId] ?? [];
    $episode = cleanDisplayText($item['episode'] ?? '');
    $members = [];
    if (!empty($item['members']) && is_array($item['members'])) {
        $members = array_values(array_filter(array_map('cleanDisplayText', $item['members'])));
    }
    $date = cleanDisplayText($item['date'] ?? '');
    $content = cleanDisplayText($item['content'] ?? '');
    $title = $episode !== '' ? 'EP.' . $episode : 'さくみみ';
    $link = cleanDisplayText($item['link'] ?? '');
    $officialUrlMobile = sakumimiOfficialPageUrl($episode, 10);
    $officialUrlPc = sakumimiOfficialPageUrl($episode, 12);
    return [
        'id' => $episodeId,
        'title' => $title,
        'episode' => $episode,
        'members' => $members,
        'date' => $date,
        'content' => $content,
        'official_link' => $link !== '' ? $link : null,
        'url' => $officialUrlMobile,
        'url_mobile' => $officialUrlMobile,
        'url_pc' => $officialUrlPc,
        'created_at' => $row['created_at'] ?? null,
    ];
}

function getSakulaboFavoritesForProfile(int $userId, bool $includeMimis, bool $includeBlogs): array {
    $out = ['mimis' => [], 'blogs' => []];
    if ($includeMimis) {
        try {
            $st = db()->prepare('SELECT episode_id, created_at FROM sakulabo_mimi_favorites WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
            $st->execute([$userId]);
            $out['mimis'] = array_map('compactSakulaboMimiItem', $st->fetchAll());
        } catch (\Throwable $e) { $out['mimis'] = []; }
    }
    if ($includeBlogs) {
        try {
            $st = db()->prepare('SELECT blog_id, created_at FROM sakulabo_blog_likes WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
            $st->execute([$userId]);
            $out['blogs'] = array_values(array_filter(array_map('compactSakulaboBlogItem', $st->fetchAll())));
        } catch (\Throwable $e) { $out['blogs'] = []; }
    }
    return $out;
}

function sakumapLinkStatus(int $sakulaboUserId): array {
    if ($sakulaboUserId <= 0) return ['linked' => false, 'map_user_id' => null];
    try {
        $st = db()->prepare('SELECT map_user_id, linked_at FROM map_buddies_links WHERE sakulabo_user_id = ? LIMIT 1');
        $st->execute([$sakulaboUserId]);
        $row = $st->fetch();
        if (!$row) return ['linked' => false, 'map_user_id' => null];
        return ['linked' => true, 'map_user_id' => (int)$row['map_user_id'], 'linked_at' => $row['linked_at'] ?? null];
    } catch (\Throwable $e) {
        return ['linked' => false, 'map_user_id' => null];
    }
}

function loadSakumapEventsById(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    if (!is_file(SAKUMAP_EVENTS_PATH)) return $map;
    $json = json_decode((string)file_get_contents(SAKUMAP_EVENTS_PATH), true);
    foreach (($json['events'] ?? []) as $event) {
        if (is_array($event) && !empty($event['id'])) $map[(string)$event['id']] = $event;
    }
    return $map;
}

function loadSakumapStampsById(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    if (!is_file(SAKUMAP_STAMPS_PATH)) return $map;
    $json = json_decode((string)file_get_contents(SAKUMAP_STAMPS_PATH), true);
    foreach (($json['stamps'] ?? []) as $stamp) {
        if (is_array($stamp) && !empty($stamp['id'])) $map[(string)$stamp['id']] = $stamp;
    }
    return $map;
}

function loadSakumapStampCatalog(): array {
    $events = loadSakumapEventsById();
    $stamps = loadSakumapStampsById();
    synthesizeSakumapAllSpotRally($events, $stamps);
    return [$events, $stamps];
}

function synthesizeSakumapAllSpotRally(array &$events, array &$stamps): void {
    $settings = [];
    if (is_file(SAKUMAP_EVENTS_PATH)) {
        $json = json_decode((string)file_get_contents(SAKUMAP_EVENTS_PATH), true);
        $settings = is_array($json['settings'] ?? null) ? $json['settings'] : [];
    }
    $rally = is_array($settings['all_spots_rally'] ?? null) ? $settings['all_spots_rally'] : [];
    if (array_key_exists('enabled', $rally) && $rally['enabled'] === false) return;

    try {
        $rows = db()->query(
            'SELECT s.id, s.title, s.summary, s.address, s.lat, s.lng,
                    (SELECT url FROM map_spot_images i WHERE i.spot_id = s.id ORDER BY i.sort_order LIMIT 1) AS thumb
               FROM map_spots s
              WHERE s.is_approved = 1
              ORDER BY s.id DESC'
        )->fetchAll();
    } catch (\Throwable $e) {
        return;
    }

    $eventId = 'all_spots';
    $stampIds = [];
    $defaultImage = trim((string)($rally['default_stamp_image'] ?? ''));
    foreach ($rows as $spot) {
        if (empty($spot['id'])) continue;
        $stampId = 'spot:' . (int)$spot['id'];
        $stampIds[] = $stampId;
        if (!isset($stamps[$stampId])) {
            $stamps[$stampId] = [
                'id' => $stampId,
                'spot_id' => (int)$spot['id'],
                'name' => trim((string)($spot['title'] ?: $spot['summary'] ?: ('スポット ' . $spot['id']))),
                'description' => trim((string)($spot['summary'] ?: $spot['address'] ?: '')),
                'image' => trim((string)($spot['thumb'] ?? '')),
                'stamp_image' => $defaultImage,
                'icon_emoji' => (string)($rally['icon_emoji'] ?? '🌸'),
            ];
        }
    }

    $events[$eventId] = [
        'id' => $eventId,
        'name' => trim((string)($rally['name'] ?? 'SakuMap 全スポットラリー')),
        'default_stamp_image' => $defaultImage,
        'stamp_ids' => $stampIds,
        'is_active' => true,
    ];
}

function sakumapAssetUrl(?string $path): ?string {
    $path = trim((string)$path);
    if ($path === '') return null;
    if (preg_match('#^https?://#i', $path)) return $path;
    return SAKUMAP_BASE_URL . ltrim($path, '/');
}

function sakumvQuizTableName(int $questionCount, string $soundMode): string {
    return 'sakumv_rankings_' . $questionCount . '_' . $soundMode;
}

function sakumvQuizSections(): array {
    return [
        ['question_count' => 5, 'sound_mode' => 'muted', 'label' => '5問 音なし'],
        ['question_count' => 5, 'sound_mode' => 'unmuted', 'label' => '5問 音あり'],
        ['question_count' => 10, 'sound_mode' => 'muted', 'label' => '10問 音なし'],
        ['question_count' => 10, 'sound_mode' => 'unmuted', 'label' => '10問 音あり'],
    ];
}

function sakumvQuizTableExists(string $table): bool {
    try {
        $st = db()->prepare('SHOW TABLES LIKE ?');
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (\Throwable $e) {
        return false;
    }
}

function hasSakumvQuizRecords(int $userId): bool {
    foreach (sakumvQuizSections() as $section) {
        $table = sakumvQuizTableName((int)$section['question_count'], (string)$section['sound_mode']);
        if (!sakumvQuizTableExists($table)) continue;
        try {
            $st = db()->prepare("SELECT 1 FROM {$table} WHERE buddies_user_id = ? LIMIT 1");
            $st->execute([$userId]);
            if ($st->fetchColumn()) return true;
        } catch (\Throwable $e) {}
    }
    return false;
}

function getSakumvQuizBestRecords(int $userId): array {
    $records = [];
    foreach (sakumvQuizSections() as $section) {
        $table = sakumvQuizTableName((int)$section['question_count'], (string)$section['sound_mode']);
        if (!sakumvQuizTableExists($table)) continue;
        try {
            $rows = db()->query(
                "SELECT id, correct_count, time_seconds, buddies_user_id, created_at
                 FROM {$table}
                 ORDER BY correct_count DESC, time_seconds ASC, created_at ASC, id ASC"
            )->fetchAll();
        } catch (\Throwable $e) {
            continue;
        }
        foreach ($rows as $index => $row) {
            if ((int)($row['buddies_user_id'] ?? 0) !== $userId) continue;
            $records[] = [
                'section' => $section['label'],
                'question_count' => (int)$section['question_count'],
                'sound_mode' => (string)$section['sound_mode'],
                'rank' => $index + 1,
                'correct_count' => (int)$row['correct_count'],
                'time_seconds' => (float)$row['time_seconds'],
                'created_at' => $row['created_at'],
            ];
            break;
        }
    }
    return $records;
}

function getSakumapStampsForProfile(int $sakulaboUserId): array {
    $link = sakumapLinkStatus($sakulaboUserId);
    if (empty($link['linked']) || empty($link['map_user_id'])) return [];
    try {
        $st = db()->prepare(
            'SELECT event_id, stamp_id, claimed_at
               FROM map_event_stamp_claims
              WHERE user_id = ?
              ORDER BY claimed_at DESC
              LIMIT 100'
        );
        $st->execute([(int)$link['map_user_id']]);
    } catch (\Throwable $e) {
        return [];
    }

    [$events, $stamps] = loadSakumapStampCatalog();
    $items = [];
    foreach ($st->fetchAll() as $row) {
        [$eventId, $stampId] = normalizeSakumapClaimIds((string)($row['event_id'] ?? ''), (string)($row['stamp_id'] ?? ''));
        $stamp = $stamps[$stampId] ?? null;
        if (!$stamp) continue;
        $event = $events[$eventId] ?? null;
        $rawImage = trim((string)($stamp['stamp_image'] ?? ''));
        if ($rawImage === '') $rawImage = trim((string)($event['default_stamp_image'] ?? ''));
        $image = sakumapAssetUrl($rawImage);
        $spotId = isset($stamp['spot_id']) && $stamp['spot_id'] !== '' ? (int)$stamp['spot_id'] : null;
        $items[] = [
            'event_id' => $eventId,
            'event_name' => (string)($event['name'] ?? ''),
            'stamp_id' => $stampId,
            'spot_id' => $spotId,
            'spot_url' => $spotId ? (SAKUMAP_BASE_URL . 'index.html?spot=' . rawurlencode((string)$spotId) . '#map') : null,
            'name' => (string)($stamp['name'] ?? 'スタンプ'),
            'description' => (string)($stamp['description'] ?? ''),
            'image_url' => $image,
            'claimed_at' => $row['claimed_at'] ?? null,
        ];
    }
    return $items;
}

function normalizeSakumapClaimIds(string $eventId, string $stampId): array {
    if ($eventId === '' && str_contains($stampId, '::')) {
        [$eventId, $stampId] = explode('::', $stampId, 2);
    }
    return [trim($eventId), trim($stampId)];
}

function cleanUrl(?string $url): ?string {
    $url = trim((string)$url);
    if ($url === '') return null;
    if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) err('URLの形式が正しくありません。');
    return $url;
}
function ensureLoginNameAvailable(string $name, ?int $excludeUserId = null, ?int $excludeVerifiedId = null, string $label = 'ユーザー名'): void {
    $sql = 'SELECT id FROM sakulabo_users WHERE username = ?';
    $params = [$name];
    if ($excludeUserId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeUserId;
    }
    $sql .= ' LIMIT 1';
    $ck = db()->prepare($sql);
    $ck->execute($params);
    if ($ck->fetch()) err("その{$label}はすでに使用されています。");

    $sql = 'SELECT id FROM buddies_verified_accounts WHERE login_id = ?';
    $params = [$name];
    if ($excludeVerifiedId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeVerifiedId;
    }
    $sql .= ' LIMIT 1';
    $ck = db()->prepare($sql);
    $ck->execute($params);
    if ($ck->fetch()) err("その{$label}はすでに使用されています。");
}
function normalizeSnsLinks($links, int $limit = 10): array {
    if (!is_array($links)) return [];
    $allowed = ['x', 'threads', 'instagram', 'tiktok', 'youtube', 'github', 'link'];
    $out = [];
    foreach ($links as $link) {
        if (!is_array($link)) continue;
        $url = cleanUrl($link['url'] ?? null);
        if (!$url) continue;
        $type = strtolower(trim((string)($link['type'] ?? 'link')));
        if (!in_array($type, $allowed, true)) $type = 'link';
        $label = trim((string)($link['label'] ?? ''));
        if (mb_strlen($label) > 80) err('SNSリンクのラベルは80文字以内で入力してください。');
        $out[] = ['type' => $type, 'url' => $url, 'label' => $label ?: null];
        if (count($out) >= $limit) break;
    }
    return $out;
}
function buildVerifiedData(array $a, bool $includePrivate = false): array {
    $type = normalizeVerifiedType($a['account_type'] ?? 'verified');
    $data = [
        'id'                   => (int)$a['id'],
        'user_id'              => isset($a['user_id']) ? (int)$a['user_id'] : null,
        'login_id'             => $includePrivate ? $a['login_id'] : null,
        'display_name'         => $a['display_name'],
        'account_type'         => $type,
        'label'                => normalizeVerifiedLabel($a['label'] ?? '', $type),
        'account_definition'   => verifiedTypeDefinition($type),
        'description'          => $a['description'] ?? '',
        'icon_url'             => $a['icon_url'] ?? null,
        'banner_url'           => $a['banner_url'] ?? null,
        'primary_link_url'     => $a['primary_link_url'] ?? null,
        'primary_link_label'   => $a['primary_link_label'] ?? null,
        'secondary_link_url'   => $a['secondary_link_url'] ?? null,
        'secondary_link_label' => $a['secondary_link_label'] ?? null,
        'x_url'                => $a['x_url'] ?? null,
        'sns_links'            => isset($a['sns_links']) && $a['sns_links'] ? (json_decode($a['sns_links'], true) ?: []) : [],
        'cta_label'            => $a['cta_label'] ?? null,
        'cta_url'              => $a['cta_url'] ?? null,
        'promotion_title'      => $a['promotion_title'] ?? null,
        'promotion_body'       => $a['promotion_body'] ?? null,
        'hashtags'             => decodeJsonArray($a['hashtags'] ?? null),
        'recommend_priority'   => (int)($a['recommend_priority'] ?? 0),
        'status'               => $a['status'] ?? 'active',
        'access_role'          => $a['_access_role'] ?? 'owner',
        'access_user_id'       => isset($a['_access_user_id']) ? (int)$a['_access_user_id'] : null,
        'is_owner'             => (($a['_access_role'] ?? 'owner') === 'owner'),
        'updated_at'           => $a['updated_at'] ?? null,
    ];
    if (!$includePrivate) unset($data['login_id']);
    return $data;
}
function normalizeVerifiedType(string $type): string {
    return match ($type) {
        'developer' => 'developer',
        default => 'verified',
    };
}
function verifiedTypeLabel(string $type): string {
    return match ($type) {
        'developer' => '開発者アカウント',
        default => 'コミュニティアカウント',
    };
}
function normalizeVerifiedLabel(?string $label, string $type): string {
    $label = trim((string)$label);
    if ($label === '' || in_array($label, ['認証アカウント', 'プロアカウント', 'コミュニティパートナー'], true)) return verifiedTypeLabel($type);
    return $label;
}
function verifiedTypeDefinition(string $type): string {
    return match ($type) {
        'developer' => 'Buddies profile の開発・運営を行うアカウントです。',
        default => 'イベント、フォーム、掲示板を運営できるコミュニティアカウントです。',
    };
}
function verifiedUploadDir(string $kind, int $accountId): string {
    $dir = __DIR__ . '/uploads/verified/' . $accountId . '/' . $kind;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) err('アップロード先を作成できません。', 500);
    return $dir;
}
function publicUploadUrl(string $kind, int $accountId, string $file): string {
    return 'uploads/verified/' . $accountId . '/' . $kind . '/' . $file;
}
function canUseVerifiedBoard(array $a): bool {
    $type = normalizeVerifiedType((string)($a['account_type'] ?? ''));
    return in_array($type, ['developer', 'verified'], true);
}
function ensureVerifiedPostTables(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS buddies_verified_posts (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id  BIGINT UNSIGNED NOT NULL,
        body        TEXT NULL DEFAULT NULL,
        link_url    VARCHAR(2048) NULL DEFAULT NULL,
        link_label  VARCHAR(120) NULL DEFAULT NULL,
        files       TEXT NULL DEFAULT NULL,
        file_url    VARCHAR(2048) NULL DEFAULT NULL,
        file_name   VARCHAR(160) NULL DEFAULT NULL,
        file_mime   VARCHAR(80) NULL DEFAULT NULL,
        file_size   INT UNSIGNED NULL DEFAULT NULL,
        status      VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_account_status_created (account_id, status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $cols = array_column(db()->query('DESCRIBE buddies_verified_posts')->fetchAll(), 'Field');
        if (!in_array('files', $cols, true)) {
            db()->exec("ALTER TABLE buddies_verified_posts ADD COLUMN files TEXT NULL DEFAULT NULL AFTER link_label");
        }
    } catch (\Throwable $e) {}
}
function verifiedPostFiles(array $p): array {
    $files = [];
    if (!empty($p['files'])) {
        $decoded = json_decode($p['files'], true);
        if (is_array($decoded)) $files = $decoded;
    }
    if (!$files && !empty($p['file_url'])) {
        $files[] = [
            'url' => $p['file_url'],
            'name' => $p['file_name'] ?? null,
            'mime' => $p['file_mime'] ?? null,
            'size' => isset($p['file_size']) ? (int)$p['file_size'] : null,
        ];
    }
    return array_values(array_filter(array_map(function($f) {
        if (!is_array($f) || empty($f['url'])) return null;
        return [
            'url' => (string)$f['url'],
            'name' => (string)($f['name'] ?? '添付ファイル'),
            'mime' => (string)($f['mime'] ?? ''),
            'size' => isset($f['size']) ? (int)$f['size'] : null,
        ];
    }, $files)));
}
function buildVerifiedPostData(array $p): array {
    return [
        'id' => (int)$p['id'],
        'account_id' => (int)$p['account_id'],
        'body' => $p['body'] ?? '',
        'link_url' => $p['link_url'] ?? null,
        'link_label' => $p['link_label'] ?? null,
        'files' => verifiedPostFiles($p),
        'file_url' => $p['file_url'] ?? null,
        'file_name' => $p['file_name'] ?? null,
        'file_mime' => $p['file_mime'] ?? null,
        'file_size' => isset($p['file_size']) ? (int)$p['file_size'] : null,
        'created_at' => $p['created_at'] ?? null,
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
    'buddies_public_profile_get' => actionPublicProfileGet(),
    'buddies_profile_update'  => actionProfileUpdate(),
    'buddies_next_live_update'=> actionNextLiveUpdate(),
    'buddies_live_participants' => actionLiveParticipants(),
    'buddies_history_update'  => actionHistoryUpdate(),
    'buddies_history_on_day'  => actionHistoryOnDay(),
    'buddies_icon_update'     => actionIconUpdate(),
    'buddies_icon_clear'      => actionIconClear(),

    'buddies_exchange_add'    => actionExchangeAdd(),
    'buddies_exchange_list'   => actionExchangeList(),
    'buddies_exchange_remove' => actionExchangeRemove(),
    'buddies_qr_token'        => actionQrToken(),
    'buddies_qr_verify'       => actionQrVerify(),

    'buddies_favorite_toggle' => actionFavoriteToggle(),
    'buddies_favorite_list'   => actionFavoriteList(),

    'buddies_search'          => actionSearch(),
    'buddies_similar'         => actionSimilar(),
    'buddies_filter_options'  => actionFilterOptions(),

    'buddies_blog_images'     => actionBlogImages(),

    'admin_tag_list'          => actionAdminTagList(),
    'admin_tag_merge'         => actionAdminTagMerge(),

    'verified_login'          => actionVerifiedLogin(),
    'verified_logout'         => actionVerifiedLogout(),
    'verified_me'             => actionVerifiedMe(),
    'verified_update'         => actionVerifiedUpdate(),
    'verified_change_password'=> actionVerifiedChangePassword(),
    'verified_upload_icon'    => actionVerifiedUpload('icons'),
    'verified_upload_banner'  => actionVerifiedUpload('banners'),
    'verified_public_list'    => actionVerifiedPublicList(),
    'verified_public_get'     => actionVerifiedPublicGet(),
    'verified_admin_list'     => actionVerifiedAdminList(),
    'verified_admin_create'   => actionVerifiedAdminCreate(),
    'verified_admin_update'   => actionVerifiedAdminUpdate(),
    'verified_admin_disable'  => actionVerifiedAdminDisable(),
    'verified_admin_reset_password' => actionVerifiedAdminResetPassword(),
    'verified_collaborator_search_users' => actionVerifiedCollaboratorSearchUsers(),
    'verified_collaborator_list' => actionVerifiedCollaboratorList(),
    'verified_collaborator_invite_create' => actionVerifiedCollaboratorInviteCreate(),
    'verified_collaborator_invite_accept' => actionVerifiedCollaboratorInviteAccept(),
    'verified_post_list'      => actionVerifiedPostList(),
    'verified_post_create'    => actionVerifiedPostCreate(),
    'verified_post_delete'    => actionVerifiedPostDelete(),

    'event_list_by_account'   => actionEventListByAccount(),
    'event_get'               => actionEventGet(),
    'event_participants'      => actionEventParticipants(),
    'event_participants_admin'=> actionEventParticipantsAdmin(),
    'event_mine_list'         => actionEventMineList(),
    'event_create'            => actionEventCreate(),
    'event_update'            => actionEventUpdate(),
    'event_delete'            => actionEventDelete(),
    'event_upload_cover'      => actionEventUploadCover(),
    'event_upload_attachment' => actionEventUploadAttachment(),
    'event_delete_attachment' => actionEventDeleteAttachment(),
    'event_join'              => actionEventJoin(),
    'event_my_status'         => actionEventMyStatus(),
    'event_my_participations' => actionEventMyParticipations(),
    'event_checkin_scan'      => actionEventCheckinScan(),
    'event_checkin_manage'    => actionEventCheckinManage(),
    'subevent_checkin_scan'   => actionSubeventCheckinScan(),
    'subevent_checkin_manage' => actionSubeventCheckinManage(),

    'subevent_list'           => actionSubeventList(),
    'subevent_get'            => actionSubeventGet(),
    'subevent_mine_list'      => actionSubeventMineList(),
    'subevent_create'         => actionSubeventCreate(),
    'subevent_update'         => actionSubeventUpdate(),
    'subevent_delete'         => actionSubeventDelete(),
    'subevent_upload_cover'   => actionSubeventUploadCover(),
    'subevent_join'           => actionSubeventJoin(),
    'subevent_participants'   => actionSubeventParticipants(),
    'subevent_participants_admin' => actionSubeventParticipantsAdmin(),
    'form_mine_list'          => actionFormMineList(),
    'form_list_by_account'    => actionFormListByAccount(),
    'form_create'             => actionFormCreate(),
    'form_update'             => actionFormUpdate(),
    'form_delete'             => actionFormDelete(),
    'form_public_get'         => actionFormPublicGet(),
    'form_submit'             => actionFormSubmit(),
    'form_responses'          => actionFormResponses(),
    'form_results'            => actionFormResults(),

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

    ensureLoginNameAvailable($username, null, null, 'ユーザー名');

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO sakulabo_users (username, password_hash, display_name) VALUES (?,?,?)')
            ->execute([$username, $hash, $display]);
        $uid   = (int)$pdo->lastInsertId();
        $token = generateToken();
        $exp   = date('Y-m-d H:i:s', strtotime('+' . SESSION_EXPIRE_HOURS . ' hours'));
        $pdo->prepare('INSERT INTO sakulabo_sessions (token, user_id, expires_at) VALUES (?,?,?)')
            ->execute([$token, $uid, $exp]);
        $pdo->prepare('INSERT IGNORE INTO buddies_profiles (user_id) VALUES (?)')->execute([$uid]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    setSessionCookie($token);

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

    $verified = verifiedAccountByUserId($uid);
    ensureLoginNameAvailable($newUsername, $uid, $verified ? (int)$verified['id'] : null, 'ユーザー名');

    db()->prepare('UPDATE sakulabo_users SET username = ? WHERE id = ?')->execute([$newUsername, $uid]);
    if ($verified) {
        db()->prepare('UPDATE buddies_verified_accounts SET login_id = ? WHERE id = ?')->execute([$newUsername, $verified['id']]);
    }

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
//  公開プロフィール取得（一般ユーザー用 view.html）
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionPublicProfileGet(): void {
    $targetId = (int)($_GET['user_id'] ?? $_GET['id'] ?? 0);
    if ($targetId <= 0) err('user_id は必須です。');

    $st = db()->prepare('SELECT * FROM sakulabo_users WHERE id = ? LIMIT 1');
    $st->execute([$targetId]);
    $u = $st->fetch();
    if (!$u) err('ユーザーが見つかりません。', 404);

    $bp = getBuddiesProfile($targetId);
    $data = buildUserData($u, $bp);
    unset($data['birthday'], $data['post_template']);

    $me = currentUser();
    $data['is_self'] = $me ? ((int)$me['id'] === $targetId) : false;
    $data['favorited'] = false;
    if ($me && (int)$me['id'] !== $targetId) {
        $ck = db()->prepare('SELECT id FROM buddies_favorites WHERE user_id=? AND target_id=? LIMIT 1');
        $ck->execute([(int)$me['id'], $targetId]);
        $data['favorited'] = (bool)$ck->fetch();
    }

    $showMimis = !empty($data['show_favorite_mimis']);
    $showBlogs = !empty($data['show_favorite_blogs']);
    $data['sakulabo_favorites'] = getSakulaboFavoritesForProfile($targetId, $showMimis, $showBlogs);

    ok($data);
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
    $nextLives    = isset($b['next_lives'])     && is_array($b['next_lives'])     ? array_slice($b['next_lives'],     0, 60) : null;
    $nextLiveSeats = isset($b['next_live_seats']) && is_array($b['next_live_seats']) ? $b['next_live_seats'] : null;
    $snsLinks     = isset($b['sns_links'])      && is_array($b['sns_links'])      ? array_slice($b['sns_links'],      0, 4) : null;

    $followStance = opt('follow_stance', null);
    if ($followStance && !in_array($followStance, ['silent_ok', 'hello_please'], true)) $followStance = null;

    $postTemplate = opt('post_template', null);
    $showFavoriteMimis = truthyFlag($b['show_favorite_mimis'] ?? 0);
    $showFavoriteBlogs = truthyFlag($b['show_favorite_blogs'] ?? 0);
    $showSakumapStamps = sakumapLinkStatus((int)$me['id'])['linked']
        ? truthyFlag($b['show_sakumap_stamps'] ?? 0)
        : 0;
    $showSakumvQuiz = hasSakumvQuizRecords((int)$me['id'])
        ? truthyFlag($b['show_sakumv_quiz'] ?? 0)
        : 1;

    if ($bio          && mb_strlen($bio)          > 500)  err('自己紹介は500文字以内で入力してください。');
    if ($postTemplate && mb_strlen($postTemplate) > 1000) err('SNS紹介タグは1000文字以内で入力してください。');
    if ($nextLives !== null) {
        $nextLives = array_values(array_unique(array_filter(array_map(function($v) {
            $v = trim((string)$v);
            return $v !== '' && mb_strlen($v) <= 260 ? $v : '';
        }, $nextLives))));
    }
    $nextLiveSeats = normalizeNextLiveSeats($nextLiveSeats, $nextLives ?? []);

    // SNSリンクの url を簡易バリデーション（空文字は除去）
    if ($snsLinks !== null) {
        $snsLinks = array_values(array_filter($snsLinks, fn($s) => !empty($s['url'])));
        foreach ($snsLinks as $s) {
            if (!filter_var($s['url'], FILTER_VALIDATE_URL)) err('SNSリンクのURLが無効です: ' . $s['url']);
        }
    }

        db()->prepare('INSERT INTO buddies_profiles
        (user_id, birthday, age, gender, location, buddies_since, bio,
         tags, favorite_songs, next_lives, next_live_seats, sns_links, follow_stance, post_template, show_favorite_mimis, show_favorite_blogs, show_sakumap_stamps, show_sakumv_quiz)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          birthday=VALUES(birthday), age=VALUES(age),
          gender=VALUES(gender), location=VALUES(location),
          buddies_since=VALUES(buddies_since), bio=VALUES(bio),
          tags=VALUES(tags), favorite_songs=VALUES(favorite_songs),
          next_lives=VALUES(next_lives),
          next_live_seats=VALUES(next_live_seats),
          sns_links=VALUES(sns_links), follow_stance=VALUES(follow_stance),
          post_template=VALUES(post_template),
          show_favorite_mimis=VALUES(show_favorite_mimis),
          show_favorite_blogs=VALUES(show_favorite_blogs),
          show_sakumap_stamps=VALUES(show_sakumap_stamps),
          show_sakumv_quiz=VALUES(show_sakumv_quiz)')
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
        $nextLives    !== null ? json_encode($nextLives,    JSON_UNESCAPED_UNICODE) : null,
        $nextLiveSeats ? json_encode($nextLiveSeats, JSON_UNESCAPED_UNICODE) : null,
        $snsLinks     !== null ? json_encode(array_values($snsLinks), JSON_UNESCAPED_UNICODE) : null,
        $followStance,
        $postTemplate ?: null,
        $showFavoriteMimis,
        $showFavoriteBlogs,
        $showSakumapStamps,
        $showSakumvQuiz,
    ]);

    $newUser = db()->prepare('SELECT * FROM sakulabo_users WHERE id=? LIMIT 1');
    $newUser->execute([$me['id']]);
    $u  = $newUser->fetch();
    $bp = getBuddiesProfile((int)$me['id']);
    ok(buildUserData($u, $bp, true));
}

function actionNextLiveUpdate(): void {
    $me = requireAuth();
    $b = body();

    $currentBp = getBuddiesProfile((int)$me['id']);
    $currentLives = ($currentBp && !empty($currentBp['next_lives']))
        ? (json_decode($currentBp['next_lives'], true) ?: [])
        : [];
    $currentSeats = ($currentBp && !empty($currentBp['next_live_seats']))
        ? (json_decode($currentBp['next_live_seats'], true) ?: [])
        : [];

    $nextLives = isset($b['next_lives']) && is_array($b['next_lives']) ? $b['next_lives'] : $currentLives;
    $nextLives = array_slice($nextLives, 0, 60);
    $nextLives = array_values(array_unique(array_filter(array_map(function($v) {
        $v = trim((string)$v);
        return $v !== '' && mb_strlen($v) <= 260 ? $v : '';
    }, $nextLives))));

    $nextLiveSeats = isset($b['next_live_seats']) && is_array($b['next_live_seats']) ? $b['next_live_seats'] : $currentSeats;
    $nextLiveSeats = normalizeNextLiveSeats($nextLiveSeats, $nextLives);

    db()->prepare('INSERT INTO buddies_profiles (user_id, next_lives, next_live_seats)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE next_lives=VALUES(next_lives), next_live_seats=VALUES(next_live_seats)')
    ->execute([
        (int)$me['id'],
        $nextLives ? json_encode($nextLives, JSON_UNESCAPED_UNICODE) : null,
        $nextLiveSeats ? json_encode($nextLiveSeats, JSON_UNESCAPED_UNICODE) : null,
    ]);

    $newUser = db()->prepare('SELECT * FROM sakulabo_users WHERE id=? LIMIT 1');
    $newUser->execute([(int)$me['id']]);
    ok(buildUserData($newUser->fetch(), getBuddiesProfile((int)$me['id']), true));
}

function actionLiveParticipants(): void {
    $liveId = trim((string)($_GET['live_id'] ?? ''));
    $limit = min(max((int)($_GET['limit'] ?? 500), 1), 1000);

    $selectCols = '
        u.id, u.username, u.display_name, u.oshi_member, u.oshi_member_2, u.oshi_member_3,
        u.user_icon,
        bp.birthday, bp.age, bp.gender, bp.location, bp.buddies_since, bp.bio,
        bp.tags, bp.favorite_songs, bp.next_lives, bp.next_live_seats, bp.sns_links, bp.follow_stance, bp.post_template,
        bp.show_favorite_mimis, bp.show_favorite_blogs, bp.show_sakumap_stamps';
    $params = [];
    $where = ["bp.next_lives IS NOT NULL", "bp.next_lives <> ''", "bp.next_lives <> '[]'"];
    if ($liveId !== '') {
        $where[] = 'bp.next_lives LIKE ?';
        $params[] = '%"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $liveId) . '"%';
    }
    $params[] = $limit;
    $sql = "SELECT $selectCols
            FROM buddies_profiles bp
            JOIN sakulabo_users u ON u.id = bp.user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY u.display_name ASC, u.username ASC
            LIMIT ?";
    $st = db()->prepare($sql);
    $st->execute($params);
    ok(array_map(fn($r) => buildRowData($r), $st->fetchAll()));
}

function actionHistoryUpdate(): void {
    $me = requireAuth();
    $b = body();
    $items = normalizeBuddiesHistoryItems($b['history'] ?? []);
    $nextLives = isset($b['next_lives']) && is_array($b['next_lives'])
        ? array_slice($b['next_lives'], 0, 60)
        : null;
    if ($nextLives !== null) {
        $nextLives = array_values(array_unique(array_filter(array_map(function($v) {
            $v = trim((string)$v);
            return $v !== '' && mb_strlen($v) <= 260 ? $v : '';
        }, $nextLives))));
    }
    ensureBuddiesHistoryTable(db());
    $pdo = db();
    $oldRows = $pdo->prepare('SELECT id, image_url, image_mime, image_size FROM buddies_history WHERE user_id = ?');
    $oldRows->execute([(int)$me['id']]);
    $oldById = [];
    $oldImageUrls = [];
    foreach ($oldRows->fetchAll() as $row) {
        $oldById[(int)$row['id']] = $row;
        if (!empty($row['image_url'])) $oldImageUrls[] = (string)$row['image_url'];
    }
    $newFiles = [];
    $keptImageUrls = [];
    foreach ($items as &$item) {
        $image = ['url' => null, 'mime' => null, 'size' => null];
        if ($item['image_data'] !== '') {
            $image = saveHistoryImageData($item['image_data'], $me, $newFiles);
        } elseif ($item['image_url'] !== '' && isHistoryImageUrlForUser($item['image_url'], $me)) {
            $old = $oldById[$item['id']] ?? null;
            if ($old && (string)($old['image_url'] ?? '') === $item['image_url']) {
                $image = [
                    'url' => (string)$old['image_url'],
                    'mime' => $old['image_mime'] !== null ? (string)$old['image_mime'] : null,
                    'size' => $old['image_size'] !== null ? (int)$old['image_size'] : null,
                ];
            }
        }
        $item['image'] = $image;
        if (!empty($image['url'])) $keptImageUrls[] = $image['url'];
    }
    unset($item);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM buddies_history WHERE user_id = ?')->execute([(int)$me['id']]);
        $ins = $pdo->prepare(
            'INSERT INTO buddies_history
                (user_id, happened_year, happened_month, happened_day, title, note, live_id, image_url, image_mime, image_size, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($items as $i => $item) {
            $ins->execute([
                (int)$me['id'],
                $item['year'],
                $item['month'],
                $item['day'],
                $item['title'],
                $item['note'] !== '' ? $item['note'] : null,
                $item['live_id'] !== '' ? $item['live_id'] : null,
                $item['image']['url'] ?? null,
                $item['image']['mime'] ?? null,
                $item['image']['size'] ?? null,
                $i,
            ]);
        }
        if ($nextLives !== null) {
            $pdo->prepare(
                'INSERT INTO buddies_profiles (user_id, next_lives) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE next_lives=VALUES(next_lives)'
            )->execute([(int)$me['id'], json_encode($nextLives, JSON_UNESCAPED_UNICODE)]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        foreach ($newFiles as $path) {
            if (is_file($path)) @unlink($path);
        }
        err('My Buddies history の保存に失敗しました。');
    }
    foreach (array_diff(array_unique($oldImageUrls), array_unique($keptImageUrls)) as $url) {
        deleteHistoryImageUrl((string)$url, $me);
    }
    $bp = getBuddiesProfile((int)$me['id']);
    ok([
        'history' => getBuddiesHistoryForProfile((int)$me['id']),
        'next_lives' => !empty($bp['next_lives']) ? (json_decode($bp['next_lives'], true) ?: []) : [],
    ]);
}

function actionHistoryOnDay(): void {
    $date = trim((string)($_GET['date'] ?? ''));
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        err('date は YYYY-MM-DD 形式で指定してください。');
    }
    $month = (int)$m[2];
    $day = (int)$m[3];
    if (!checkdate($month, $day, (int)$m[1])) err('date が正しくありません。');

    ensureBuddiesHistoryTable(db());
    $st = db()->prepare(
        'SELECT h.id, h.user_id, h.happened_year, h.happened_month, h.happened_day,
                h.title, h.note, h.live_id, h.image_url,
                u.username, u.display_name, u.user_icon, u.oshi_member
           FROM buddies_history h
           JOIN sakulabo_users u ON u.id = h.user_id
      LEFT JOIN buddies_profiles bp ON bp.user_id = u.id
          WHERE h.happened_month = ?
            AND h.happened_day = ?
            AND h.live_id IS NOT NULL
            AND h.live_id <> \'\'
          ORDER BY h.happened_year DESC, h.sort_order ASC, h.id DESC
          LIMIT 80'
    );
    $st->execute([$month, $day]);
    ok(array_map(function($r) {
        return [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'username' => (string)($r['username'] ?? ''),
            'display_name' => (string)($r['display_name'] ?: $r['username'] ?: '名無し'),
            'user_icon' => (string)($r['user_icon'] ?? ''),
            'oshi_member' => (string)($r['oshi_member'] ?? ''),
            'year' => (int)$r['happened_year'],
            'month' => (int)$r['happened_month'],
            'day' => (int)$r['happened_day'],
            'title' => (string)$r['title'],
            'note' => $r['note'] !== null ? (string)$r['note'] : '',
            'live_id' => $r['live_id'] !== null ? (string)$r['live_id'] : '',
            'image_url' => $r['image_url'] !== null ? (string)$r['image_url'] : '',
        ];
    }, $st->fetchAll()));
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
                bp.tags, bp.favorite_songs, bp.next_lives, bp.next_live_seats, bp.sns_links, bp.follow_stance, bp.post_template,
                bp.show_favorite_mimis, bp.show_favorite_blogs, bp.show_sakumap_stamps,
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
    if ($targetId <= 0) err('対象ユーザーが正しくありません。');

    // 一般ユーザー同士のみ利用可能
    $meVerified = verifiedAccountByUserId((int)$me['id']);
    if ($meVerified || isHiromameAdmin($me)) err('お気に入りは一般ユーザー同士でのみ利用できます。', 403);

    $exists = db()->prepare('SELECT * FROM sakulabo_users WHERE id=? LIMIT 1');
    $exists->execute([$targetId]);
    $targetUser = $exists->fetch();
    if (!$targetUser) err('対象ユーザーが見つかりません。', 404);
    $targetVerified = verifiedAccountByUserId($targetId);
    if ($targetVerified || isHiromameAdmin($targetUser)) err('お気に入りは一般ユーザー同士でのみ利用できます。', 403);

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
                bp.tags, bp.favorite_songs, bp.next_lives, bp.next_live_seats, bp.sns_links, bp.follow_stance, bp.post_template,
                bp.show_favorite_mimis, bp.show_favorite_blogs, bp.show_sakumap_stamps,
                f.created_at AS favorited_at
         FROM buddies_favorites f
         JOIN sakulabo_users u ON u.id = f.target_id
         LEFT JOIN buddies_profiles bp ON bp.user_id = u.id
         WHERE f.user_id = ?
         ORDER BY f.created_at DESC'
    );
    $st->execute([$me['id']]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $verified = verifiedAccountByUserId((int)$r['id']);
        if ($verified || ((int)$r['id'] === 1)) continue;
        $rows[] = buildRowData($r);
    }
    ok($rows);
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
    $myBpForSearch = $myId ? getBuddiesProfile($myId) : null;
    $myNextLivesForSearch = ($myBpForSearch && !empty($myBpForSearch['next_lives']))
        ? (json_decode($myBpForSearch['next_lives'], true) ?: [])
        : [];

    // 推しメン絞り込み（複数指定 + AND/OR + 第一推し指定）
    $oshisRaw = $_GET['oshis'] ?? '';
    $oshis    = array_values(array_filter(array_map('trim', explode(',', $oshisRaw))));
    $oshis    = array_slice(array_unique($oshis), 0, 10);
    $oshiMode = strtolower(trim($_GET['oshi_mode'] ?? 'or')) === 'and' ? 'and' : 'or';
    $primary  = trim($_GET['primary_oshi'] ?? '');

    // 興味タグ絞り込み（OR: いずれかを含む）
    $tagsRaw = $_GET['tags'] ?? '';
    $tags    = array_values(array_filter(array_map('trim', explode(',', $tagsRaw))));
    $tags    = array_slice(array_unique($tags), 0, 10);

    // 楽曲絞り込み（OR: いずれかを含む）
    $songsRaw = $_GET['songs'] ?? '';
    $songs    = array_values(array_filter(array_map('trim', explode(',', $songsRaw))));
    $songs    = array_slice(array_unique($songs), 0, 10);

    // NEXTライブ絞り込み（OR: 同じ公演を含む）
    $livesRaw = $_GET['live_ids'] ?? '';
    $liveIds  = array_values(array_filter(array_map('trim', explode(',', $livesRaw))));
    $liveIds  = array_slice(array_unique($liveIds), 0, 20);
    $liveMode = strtolower(trim($_GET['live_mode'] ?? 'or')) === 'and' ? 'and' : 'or';

    // 公開設定による絞り込み
    $hasSns = !empty($_GET['has_sns']) && $_GET['has_sns'] !== '0' && $_GET['has_sns'] !== 'false';
    $hasPastLive = !empty($_GET['has_past_live']) && $_GET['has_past_live'] !== '0' && $_GET['has_past_live'] !== 'false';
    $showsMimis = !empty($_GET['shows_mimis']) && $_GET['shows_mimis'] !== '0' && $_GET['shows_mimis'] !== 'false';
    $showsBlogs = !empty($_GET['shows_blogs']) && $_GET['shows_blogs'] !== '0' && $_GET['shows_blogs'] !== 'false';
    $pastLiveIdsInput = json_decode((string)($_GET['past_live_ids'] ?? '[]'), true);
    $pastLiveIds = is_array($pastLiveIdsInput)
        ? array_slice(array_values(array_unique(array_filter(array_map('trim', $pastLiveIdsInput)))), 0, 100)
        : [];

    $selectCols = '
        u.id, u.username, u.display_name, u.oshi_member, u.oshi_member_2, u.oshi_member_3,
        u.user_icon,
        bp.birthday, bp.age, bp.gender, bp.location, bp.buddies_since, bp.bio,
        bp.tags, bp.favorite_songs, bp.next_lives, bp.next_live_seats, bp.sns_links, bp.follow_stance, bp.post_template,
        bp.show_favorite_mimis, bp.show_favorite_blogs, bp.show_sakumap_stamps';

    $where  = ['u.id != ?'];
    $params = [$myId ?: 0];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(u.display_name LIKE ? OR u.username LIKE ?
                    OR u.oshi_member LIKE ? OR u.oshi_member_2 LIKE ? OR u.oshi_member_3 LIKE ?
                    OR bp.tags LIKE ? OR bp.location LIKE ? OR bp.favorite_songs LIKE ? OR bp.next_lives LIKE ?)';
        for ($i = 0; $i < 9; $i++) $params[] = $like;
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

    // 興味タグ（OR: bp.tags JSON文字列に LIKE で部分一致）
    if (!empty($tags)) {
        $clauses = [];
        foreach ($tags as $t) {
            $clauses[] = 'bp.tags LIKE ?';
            $params[]  = '%"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $t) . '"%';
        }
        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }

    // 楽曲（OR: bp.favorite_songs JSON文字列に LIKE で部分一致）
    if (!empty($songs)) {
        $clauses = [];
        foreach ($songs as $s) {
            $clauses[] = 'bp.favorite_songs LIKE ?';
            $params[]  = '%"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"%';
        }
        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }

    if (!empty($liveIds)) {
        $clauses = [];
        foreach ($liveIds as $id) {
            $pattern = '%"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $id) . '"%';
            if ($liveMode === 'and') {
                $where[]  = 'bp.next_lives LIKE ?';
                $params[] = $pattern;
            } else {
                $clauses[] = 'bp.next_lives LIKE ?';
                $params[]  = $pattern;
            }
        }
        if ($liveMode !== 'and' && $clauses) {
            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }
    }

    // SNS設定済み（sns_links に少なくとも1件 url を含む）
    if ($hasSns) {
        $where[] = "(bp.sns_links IS NOT NULL AND bp.sns_links <> '' AND bp.sns_links <> '[]' AND bp.sns_links LIKE ?)";
        $params[] = '%"url"%';
    }
    if ($hasPastLive) {
        $hasAnyNextLiveExpr = "(bp.next_lives IS NOT NULL AND bp.next_lives <> '' AND bp.next_lives <> '[]')";
        if (!$pastLiveIds) {
            $where[] = $hasAnyNextLiveExpr;
        } else {
            $clauses = [];
            foreach ($pastLiveIds as $id) {
                $clauses[] = 'bp.next_lives LIKE ?';
                $params[] = '%"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $id) . '"%';
            }
            $clauses[] = $hasAnyNextLiveExpr;
            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }
    }
    if ($showsMimis) {
        $where[] = 'bp.show_favorite_mimis = 1 AND EXISTS (SELECT 1 FROM sakulabo_mimi_favorites smf WHERE smf.user_id = u.id LIMIT 1)';
    }
    if ($showsBlogs) {
        $where[] = 'bp.show_favorite_blogs = 1 AND EXISTS (SELECT 1 FROM sakulabo_blog_likes sbl WHERE sbl.user_id = u.id LIMIT 1)';
    }

    // 並び順:
    //  1) SNS設定済みを優先
    //  2) プロフィール完成度（推しメン/自己紹介/興味/楽曲/地域/SNS/誕生日/スタンス）
    //  3) 同一グループ内はシードベースのシャッフル（毎回ランダムだがページング中は順序維持）
    $seed = (int)($_GET['seed'] ?? 0);
    if ($seed === 0) $seed = rand(1, 2147483647);

    $hasSnsExpr = "(CASE WHEN bp.sns_links IS NOT NULL AND bp.sns_links <> '' AND bp.sns_links <> '[]' AND bp.sns_links LIKE '%\"url\"%' THEN 1 ELSE 0 END)";
    $liveMatchParts = [];
    foreach (array_slice(array_values(array_filter($myNextLivesForSearch, 'is_string')), 0, 20) as $id) {
        $pattern = '%"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $id) . '"%';
        $liveMatchParts[] = "(CASE WHEN bp.next_lives LIKE " . db()->quote($pattern) . " THEN 1 ELSE 0 END)";
    }
    $liveMatchExpr = $liveMatchParts ? '(' . implode(' + ', $liveMatchParts) . ')' : '0';
    $completionExpr =
        "(CASE WHEN u.oshi_member   IS NOT NULL AND u.oshi_member   <> '' THEN 1 ELSE 0 END) +
         (CASE WHEN u.oshi_member_2 IS NOT NULL AND u.oshi_member_2 <> '' THEN 1 ELSE 0 END) +
         (CASE WHEN u.oshi_member_3 IS NOT NULL AND u.oshi_member_3 <> '' THEN 1 ELSE 0 END) +
         (CASE WHEN bp.bio            IS NOT NULL AND bp.bio            <> '' THEN 1 ELSE 0 END) +
         (CASE WHEN bp.tags           IS NOT NULL AND bp.tags           <> '' AND bp.tags           <> '[]' THEN 1 ELSE 0 END) +
         (CASE WHEN bp.favorite_songs IS NOT NULL AND bp.favorite_songs <> '' AND bp.favorite_songs <> '[]' THEN 1 ELSE 0 END) +
         (CASE WHEN bp.next_lives     IS NOT NULL AND bp.next_lives     <> '' AND bp.next_lives     <> '[]' THEN 1 ELSE 0 END) +
         (CASE WHEN bp.location       IS NOT NULL AND bp.location       <> '' THEN 1 ELSE 0 END) +
         (CASE WHEN bp.birthday       IS NOT NULL THEN 1 ELSE 0 END) +
         (CASE WHEN bp.follow_stance  IS NOT NULL AND bp.follow_stance  <> '' THEN 1 ELSE 0 END) +
         (CASE WHEN u.user_icon       IS NOT NULL AND u.user_icon       <> '' THEN 1 ELSE 0 END)";

    $params[] = $seed;
    $sql = "SELECT $selectCols,
                   $hasSnsExpr     AS _has_sns,
                   $liveMatchExpr  AS _live_match,
                   $completionExpr AS _completion
            FROM sakulabo_users u
            LEFT JOIN buddies_profiles bp ON bp.user_id = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY _live_match DESC, _has_sns DESC, _completion DESC, RAND(?)
            LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $st = db()->prepare($sql);
    $st->execute($params);
    ok(array_map(fn($r) => buildRowData($r), $st->fetchAll()));
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  フィルター選択肢（登録ユーザー数の多い順に集計）
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function actionFilterOptions(): void {
    $st = db()->query('SELECT tags, favorite_songs, next_lives FROM buddies_profiles
                       WHERE tags IS NOT NULL OR favorite_songs IS NOT NULL OR next_lives IS NOT NULL');
    $tagCount  = [];
    $songCount = [];
    $liveCount = [];
    foreach ($st->fetchAll() as $r) {
        if (!empty($r['tags'])) {
            $arr = json_decode($r['tags'], true);
            if (is_array($arr)) {
                $seen = [];
                foreach ($arr as $t) {
                    if (!is_string($t)) continue;
                    $t = trim($t);
                    if ($t === '' || isset($seen[$t])) continue;
                    $seen[$t] = true;
                    $tagCount[$t] = ($tagCount[$t] ?? 0) + 1;
                }
            }
        }
        if (!empty($r['favorite_songs'])) {
            $arr = json_decode($r['favorite_songs'], true);
            if (is_array($arr)) {
                $seen = [];
                foreach ($arr as $s) {
                    if (!is_string($s)) continue;
                    $s = trim($s);
                    if ($s === '' || isset($seen[$s])) continue;
                    $seen[$s] = true;
                    $songCount[$s] = ($songCount[$s] ?? 0) + 1;
                }
            }
        }
        if (!empty($r['next_lives'])) {
            $arr = json_decode($r['next_lives'], true);
            if (is_array($arr)) {
                $seen = [];
                foreach ($arr as $id) {
                    if (!is_string($id)) continue;
                    $id = trim($id);
                    if ($id === '' || isset($seen[$id])) continue;
                    $seen[$id] = true;
                    $liveCount[$id] = ($liveCount[$id] ?? 0) + 1;
                }
            }
        }
    }

    $toList = function(array $counts) {
        arsort($counts);
        $out = [];
        foreach ($counts as $name => $c) {
            $out[] = ['name' => (string)$name, 'count' => (int)$c];
        }
        return $out;
    };

    ok([
        'tags'  => $toList($tagCount),
        'songs' => $toList($songCount),
        'lives' => $toList($liveCount),
    ]);
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
    $myNextLives = ($myBp && !empty($myBp['next_lives'])) ? (json_decode($myBp['next_lives'], true) ?? []) : [];
    $myNextLiveSeats = ($myBp && !empty($myBp['next_live_seats'])) ? (json_decode($myBp['next_live_seats'], true) ?? []) : [];
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
        bp.tags, bp.favorite_songs, bp.next_lives, bp.next_live_seats, bp.sns_links, bp.follow_stance, bp.post_template,
        bp.show_favorite_mimis, bp.show_favorite_blogs, bp.show_sakumap_stamps';

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

        // NEXTライブ一致（同じ公演に参加予定）
        $cNextLives = ($c['next_lives']) ? (json_decode($c['next_lives'], true) ?? []) : [];
        $liveOverlap = array_intersect($myNextLives, is_array($cNextLives) ? $cNextLives : []);
        if ($liveOverlap) {
            $score += count($liveOverlap) * 12;
            $reasons[] = '同じ公演に参加予定';
        }
        $cNextLiveSeats = ($c['next_live_seats']) ? (json_decode($c['next_live_seats'], true) ?? []) : [];
        foreach ($liveOverlap as $liveId) {
            $mySeat = is_array($myNextLiveSeats[$liveId] ?? null) ? $myNextLiveSeats[$liveId] : [];
            $cSeat = is_array($cNextLiveSeats[$liveId] ?? null) ? $cNextLiveSeats[$liveId] : [];
            if (!$mySeat || !$cSeat) continue;
            if (!empty($mySeat['area']) && !empty($cSeat['area']) && $mySeat['area'] === $cSeat['area']) {
                $score += 4;
                $reasons[] = '席エリアが一緒';
            }
            if (!empty($mySeat['block']) && !empty($cSeat['block']) && $mySeat['block'] === $cSeat['block']) {
                $score += 8;
                $reasons[] = 'ブロックが一緒';
            }
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

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  管理者: タグ一覧取得 / タグ統合
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function requireAdmin(): void {
    requireHiromameAdmin();
}

// タグ一覧（tags / favorite_songs 両方）を件数付きで返す
function actionAdminTagList(): void {
    requireAdmin();
    $type = $_GET['type'] ?? 'tags'; // 'tags' or 'songs'
    $col  = $type === 'songs' ? 'favorite_songs' : 'tags';

    $st = db()->query("SELECT user_id, $col AS val FROM buddies_profiles WHERE $col IS NOT NULL AND $col != '' AND $col != '[]'");
    $counts = [];
    foreach ($st->fetchAll() as $r) {
        $arr = json_decode($r['val'], true);
        if (!is_array($arr)) continue;
        $seen = [];
        foreach ($arr as $item) {
            if (!is_string($item)) continue;
            $item = trim($item);
            if ($item === '' || isset($seen[$item])) continue;
            $seen[$item] = true;
            $counts[$item] = ($counts[$item] ?? 0) + 1;
        }
    }
    arsort($counts);
    $out = [];
    foreach ($counts as $name => $c) {
        $out[] = ['name' => $name, 'count' => $c];
    }
    ok($out);
}

// タグ統合: $from を $to に置き換える（全ユーザーのプロフィールを更新）
function actionAdminTagMerge(): void {
    requireAdmin();
    $b    = body();
    $type = $b['type'] ?? 'tags'; // 'tags' or 'songs'
    $from = trim($b['from'] ?? '');
    $to   = trim($b['to']   ?? '');
    if ($from === '' || $to === '') err('from と to は必須です。');
    if ($from === $to) err('from と to が同じです。');
    $col  = $type === 'songs' ? 'favorite_songs' : 'tags';

    $st = db()->query("SELECT user_id, $col AS val FROM buddies_profiles WHERE $col IS NOT NULL AND $col != '' AND $col != '[]'");
    $rows = $st->fetchAll();

    $updated = 0;
    $upd = db()->prepare("UPDATE buddies_profiles SET $col = ? WHERE user_id = ?");
    foreach ($rows as $r) {
        $arr = json_decode($r['val'], true);
        if (!is_array($arr)) continue;

        $hasFrom = false;
        $hasTo   = false;
        $new     = [];
        foreach ($arr as $item) {
            if (!is_string($item)) { $new[] = $item; continue; }
            $t = trim($item);
            if ($t === $from) { $hasFrom = true; continue; } // 置換対象は一旦スキップ
            if ($t === $to)   { $hasTo   = true; }
            $new[] = $t;
        }
        if (!$hasFrom) continue; // このユーザーは $from を持っていない

        // $to がまだ入っていなければ先頭に追加
        if (!$hasTo) array_unshift($new, $to);

        $upd->execute([json_encode($new, JSON_UNESCAPED_UNICODE), $r['user_id']]);
        $updated++;
    }

    ok(['updated' => $updated, 'from' => $from, 'to' => $to, 'type' => $type]);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  コミュニティアカウント
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function verifiedEditableFields(array $b, bool $preserveDeveloperType = true): array {
    $hashtags = isset($b['hashtags']) && is_array($b['hashtags'])
        ? array_slice(array_values(array_filter(array_map(fn($v) => trim((string)$v), $b['hashtags']))), 0, 6)
        : null;
    $snsLinks = normalizeSnsLinks($b['sns_links'] ?? [], 10);
    $legacyXUrl = cleanUrl($b['x_url'] ?? null);
    if (!$legacyXUrl) {
        foreach ($snsLinks as $link) {
            if (($link['type'] ?? '') === 'x') {
                $legacyXUrl = $link['url'];
                break;
            }
        }
    }

    $fields = [
        'display_name'         => trim((string)($b['display_name'] ?? '')),
        'account_type'         => normalizeVerifiedType(trim((string)($b['account_type'] ?? 'verified'))),
        'label'                => trim((string)($b['label'] ?? '')),
        'description'          => trim((string)($b['description'] ?? '')),
        'primary_link_url'     => cleanUrl($b['primary_link_url'] ?? null),
        'primary_link_label'   => trim((string)($b['primary_link_label'] ?? '')),
        'secondary_link_url'   => cleanUrl($b['secondary_link_url'] ?? null),
        'secondary_link_label' => trim((string)($b['secondary_link_label'] ?? '')),
        'x_url'                => $legacyXUrl,
        'sns_links'            => json_encode($snsLinks, JSON_UNESCAPED_UNICODE),
        'cta_label'            => trim((string)($b['cta_label'] ?? '')),
        'cta_url'              => cleanUrl($b['cta_url'] ?? null),
        'promotion_title'      => trim((string)($b['promotion_title'] ?? '')),
        'promotion_body'       => trim((string)($b['promotion_body'] ?? '')),
        'hashtags'             => $hashtags !== null ? json_encode($hashtags, JSON_UNESCAPED_UNICODE) : null,
    ];

    if ($fields['display_name'] === '' || mb_strlen($fields['display_name']) > 100) err('表示名は1〜100文字で入力してください。');
    $current = $preserveDeveloperType ? currentVerifiedAccount() : null;
    if ($current && normalizeVerifiedType($current['account_type'] ?? '') === 'developer') {
        $fields['account_type'] = 'developer';
    }
    if ($fields['label'] === '') $fields['label'] = verifiedTypeLabel($fields['account_type']);
    if ($fields['label'] === '' || mb_strlen($fields['label']) > 80) err('ラベルは1〜80文字で入力してください。');
    if (mb_strlen($fields['description']) > 2000) err('説明文は2000文字以内で入力してください。');
    if (mb_strlen($fields['promotion_title']) > 160) err('告知タイトルは160文字以内で入力してください。');
    if (mb_strlen($fields['promotion_body']) > 3000) err('告知本文は3000文字以内で入力してください。');
    foreach (['primary_link_label', 'secondary_link_label', 'cta_label'] as $k) {
        if (mb_strlen($fields[$k]) > 80) err('リンクボタン文言は80文字以内で入力してください。');
        if ($fields[$k] === '') $fields[$k] = null;
    }
    foreach (['description', 'promotion_title', 'promotion_body'] as $k) {
        if ($fields[$k] === '') $fields[$k] = null;
    }
    return $fields;
}

function actionVerifiedLogin(): void {
    $login = req('login_id');
    $pass  = req('password');
    $st = db()->prepare('SELECT * FROM sakulabo_users WHERE username = ? LIMIT 1');
    $st->execute([$login]);
    $user = $st->fetch();
    if (!$user || !password_verify($pass, $user['password_hash'])) err('ログインIDまたはパスワードが正しくありません。', 401);

    $a = verifiedAccountByUserId((int)$user['id']);
    if ($a) $a = markVerifiedOwner($a, (int)$user['id']);
    if (!$a && isHiromameAdmin($user)) {
        $dev = developerVerifiedAccount($user);
        if ($dev) $a = markVerifiedOwner($dev, (int)$user['id']);
    }
    if (!$a) $a = collaboratorVerifiedAccountByUserId((int)$user['id']);
    if (!$a) err('コミュニティアカウントではありません。', 403);

    db()->prepare('DELETE FROM sakulabo_sessions WHERE user_id = ? OR expires_at < NOW()')->execute([$user['id']]);
    $token = generateToken();
    $exp   = date('Y-m-d H:i:s', strtotime('+' . SESSION_EXPIRE_HOURS . ' hours'));
    db()->prepare('INSERT INTO sakulabo_sessions (token, user_id, expires_at) VALUES (?,?,?)')->execute([$token, $user['id'], $exp]);
    db()->prepare('UPDATE buddies_verified_accounts SET last_login_at = NOW() WHERE id = ?')->execute([$a['id']]);
    setSessionCookie($token);
    ok(['token' => $token, 'account' => buildVerifiedData($a, true)]);
}

function actionVerifiedLogout(): void {
    $token = getToken();
    if ($token) db()->prepare('DELETE FROM sakulabo_sessions WHERE token = ?')->execute([$token]);
    $legacyToken = getVerifiedToken();
    if ($legacyToken) db()->prepare('DELETE FROM buddies_verified_sessions WHERE token = ?')->execute([$legacyToken]);
    setcookie('sakulabo_token', '', ['expires' => time() - 3600, 'path' => '/']);
    setcookie('buddies_verified_token', '', ['expires' => time() - 3600, 'path' => '/']);
    ok();
}

function actionVerifiedMe(): void {
    $a = currentVerifiedAccount() ?: developerVerifiedAccount();
    ok($a ? buildVerifiedData($a, true) : null);
}

function actionVerifiedUpdate(): void {
    $a = requireVerifiedAccount();
    $f = verifiedEditableFields(body());
    db()->prepare('UPDATE buddies_verified_accounts SET
        display_name=?, account_type=?, label=?, description=?,
        primary_link_url=?, primary_link_label=?, secondary_link_url=?, secondary_link_label=?,
        x_url=?, sns_links=?, cta_label=?, cta_url=?, promotion_title=?, promotion_body=?, hashtags=?
        WHERE id=?')
    ->execute([
        $f['display_name'], $f['account_type'], $f['label'], $f['description'],
        $f['primary_link_url'], $f['primary_link_label'], $f['secondary_link_url'], $f['secondary_link_label'],
        $f['x_url'], $f['sns_links'], $f['cta_label'], $f['cta_url'], $f['promotion_title'], $f['promotion_body'], $f['hashtags'],
        $a['id'],
    ]);
    if (!empty($a['user_id'])) {
        db()->prepare('UPDATE sakulabo_users SET display_name=? WHERE id=?')->execute([$f['display_name'], $a['user_id']]);
    }
    $st = db()->prepare('SELECT * FROM buddies_verified_accounts WHERE id=? LIMIT 1');
    $st->execute([$a['id']]);
    ok(buildVerifiedData($st->fetch(), true));
}

function actionVerifiedChangePassword(): void {
    $a = requireVerifiedOwner();
    if (empty($a['user_id'])) err('連携ユーザーが見つかりません。管理者に連絡してください。', 409);
    $current = (string)(body()['current_password'] ?? '');
    $new = (string)(body()['new_password'] ?? '');
    if (strlen($new) < 8) err('新しいパスワードは8文字以上で入力してください。');
    $st = db()->prepare('SELECT * FROM sakulabo_users WHERE id=? LIMIT 1');
    $st->execute([(int)$a['user_id']]);
    $user = $st->fetch();
    if (!$user || !password_verify($current, $user['password_hash'])) err('現在のパスワードが正しくありません。');
    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare('UPDATE sakulabo_users SET password_hash=? WHERE id=?')->execute([$hash, $a['user_id']]);
    db()->prepare('UPDATE buddies_verified_accounts SET password_hash=? WHERE id=?')->execute([$hash, $a['id']]);
    db()->prepare('DELETE FROM sakulabo_sessions WHERE user_id=?')->execute([$a['user_id']]);
    ok();
}

function actionVerifiedUpload(string $kind): void {
    $a = requireVerifiedAccount();
    $dataUrl = (string)(body()['image'] ?? '');
    if (!preg_match('/^data:image\/(png|jpeg|webp);base64,/', $dataUrl, $m)) err('PNG/JPEG/WebP画像を指定してください。');
    $raw = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
    if ($raw === false) err('画像データを読み込めませんでした。');
    if (!@getimagesizefromstring($raw)) err('有効な画像ファイルではありません。');
    $limit = $kind === 'icons' ? 3 * 1024 * 1024 : 5 * 1024 * 1024;
    if (strlen($raw) > $limit) err('画像サイズが大きすぎます。');
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $accountId = (int)$a['id'];
    $file = $kind . '.' . $ext;
    $dir = verifiedUploadDir($kind, $accountId);
    // 旧フォーマット（拡張子違い）のファイルがあれば削除
    foreach (['png','jpg','webp'] as $oldExt) {
        $oldPath = $dir . '/' . $kind . '.' . $oldExt;
        if ($oldExt !== $ext && is_file($oldPath)) @unlink($oldPath);
    }
    $path = $dir . '/' . $file;
    if (file_put_contents($path, $raw) === false) err('画像を保存できませんでした。', 500);
    $url = publicUploadUrl($kind, $accountId, $file) . '?v=' . time();
    $col = $kind === 'icons' ? 'icon_url' : 'banner_url';
    db()->prepare("UPDATE buddies_verified_accounts SET $col=? WHERE id=?")->execute([$url, $a['id']]);
    ok([$col => $url]);
}

function actionVerifiedPublicList(): void {
    $limit = min(max((int)($_GET['limit'] ?? 12), 1), 50);
    $st = db()->prepare("SELECT * FROM buddies_verified_accounts WHERE status = 'active' ORDER BY recommend_priority DESC, updated_at DESC LIMIT ?");
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    ok(array_map(fn($a) => buildVerifiedData($a), $st->fetchAll()));
}

function actionVerifiedPublicGet(): void {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) err('id は必須です。');
    $st = db()->prepare("SELECT * FROM buddies_verified_accounts WHERE id=? AND status='active' LIMIT 1");
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) err('コミュニティアカウントが見つかりません。', 404);
    ok(buildVerifiedData($a));
}

function actionVerifiedAdminList(): void {
    requireHiromameAdmin();
    $st = db()->query('SELECT * FROM buddies_verified_accounts ORDER BY status ASC, recommend_priority DESC, updated_at DESC');
    ok(array_map(fn($a) => buildVerifiedData($a, true), $st->fetchAll()));
}

function actionVerifiedAdminCreate(): void {
    $admin = requireHiromameAdmin();
    $b = body();
    $login = trim((string)($b['login_id'] ?? ''));
    $pass  = (string)($b['password'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_]{3,64}$/', $login)) err('ログインIDは3〜64文字の半角英数字・アンダースコアで入力してください。');
    if (strlen($pass) < 8) err('初期パスワードは8文字以上で入力してください。');
    ensureLoginNameAvailable($login, null, null, 'ログインID');
    $f = verifiedEditableFields($b, false);
    if ($f['account_type'] === 'developer') err('開発者アカウントは新規作成できません。');
    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO sakulabo_users (username, password_hash, display_name) VALUES (?,?,?)')
            ->execute([$login, $hash, $f['display_name']]);
        $uid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT IGNORE INTO buddies_profiles (user_id) VALUES (?)')->execute([$uid]);
        $pdo->prepare('INSERT INTO buddies_verified_accounts
            (user_id, login_id, password_hash, display_name, account_type, label, description,
             primary_link_url, primary_link_label, secondary_link_url, secondary_link_label,
             x_url, sns_links, cta_label, cta_url, promotion_title, promotion_body, hashtags,
             recommend_priority, status, created_by_admin_user_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            $uid, $login, $hash, $f['display_name'], $f['account_type'], $f['label'], $f['description'],
            $f['primary_link_url'], $f['primary_link_label'], $f['secondary_link_url'], $f['secondary_link_label'],
            $f['x_url'], $f['sns_links'], $f['cta_label'], $f['cta_url'], $f['promotion_title'], $f['promotion_body'], $f['hashtags'],
            (int)($b['recommend_priority'] ?? 0), 'active', $admin['id'],
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $st = db()->prepare('SELECT * FROM buddies_verified_accounts WHERE id=? LIMIT 1');
    $st->execute([$id]);
    ok(buildVerifiedData($st->fetch(), true));
}

function actionVerifiedAdminUpdate(): void {
    requireHiromameAdmin();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    if ($id <= 0) err('id は必須です。');
    $st = db()->prepare('SELECT * FROM buddies_verified_accounts WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $before = $st->fetch();
    if (!$before) err('コミュニティアカウントが見つかりません。', 404);
    $f = verifiedEditableFields($b, false);
    $status = in_array(($b['status'] ?? 'active'), ['active', 'paused', 'disabled'], true) ? $b['status'] : 'active';
    db()->prepare('UPDATE buddies_verified_accounts SET
        display_name=?, account_type=?, label=?, description=?,
        primary_link_url=?, primary_link_label=?, secondary_link_url=?, secondary_link_label=?,
        x_url=?, sns_links=?, cta_label=?, cta_url=?, promotion_title=?, promotion_body=?, hashtags=?,
        recommend_priority=?, status=?
        WHERE id=?')
    ->execute([
        $f['display_name'], $f['account_type'], $f['label'], $f['description'],
        $f['primary_link_url'], $f['primary_link_label'], $f['secondary_link_url'], $f['secondary_link_label'],
        $f['x_url'], $f['sns_links'], $f['cta_label'], $f['cta_url'], $f['promotion_title'], $f['promotion_body'], $f['hashtags'],
        (int)($b['recommend_priority'] ?? 0), $status, $id,
    ]);
    if (!empty($before['user_id'])) {
        db()->prepare('UPDATE sakulabo_users SET display_name=? WHERE id=?')->execute([$f['display_name'], $before['user_id']]);
    }
    $st = db()->prepare('SELECT * FROM buddies_verified_accounts WHERE id=? LIMIT 1');
    $st->execute([$id]);
    ok(buildVerifiedData($st->fetch(), true));
}

function actionVerifiedAdminDisable(): void {
    requireHiromameAdmin();
    $id = (int)req('id');
    $st = db()->prepare('SELECT * FROM buddies_verified_accounts WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $a = $st->fetch();
    db()->prepare("UPDATE buddies_verified_accounts SET status='disabled' WHERE id=?")->execute([$id]);
    db()->prepare('DELETE FROM buddies_verified_sessions WHERE account_id=?')->execute([$id]);
    if ($a && !empty($a['user_id'])) db()->prepare('DELETE FROM sakulabo_sessions WHERE user_id=?')->execute([$a['user_id']]);
    ok(['disabled' => true]);
}

function actionVerifiedAdminResetPassword(): void {
    requireHiromameAdmin();
    $id = (int)req('id');
    $pass = (string)(body()['password'] ?? '');
    if (strlen($pass) < 8) err('新しいパスワードは8文字以上で入力してください。');
    $st = db()->prepare('SELECT * FROM buddies_verified_accounts WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) err('コミュニティアカウントが見つかりません。', 404);
    if (empty($a['user_id'])) err('連携ユーザーが見つかりません。', 409);
    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare('UPDATE sakulabo_users SET password_hash=? WHERE id=?')->execute([$hash, $a['user_id']]);
    db()->prepare('UPDATE buddies_verified_accounts SET password_hash=? WHERE id=?')->execute([$hash, $id]);
    db()->prepare('DELETE FROM buddies_verified_sessions WHERE account_id=?')->execute([$id]);
    db()->prepare('DELETE FROM sakulabo_sessions WHERE user_id=?')->execute([$a['user_id']]);
    ok();
}

function communityInviteUrl(string $token): string {
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
    $scheme = $https ? 'https' : 'http';
    $host = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'buddies46.stars.ne.jp'))[0] ?? 'buddies46.stars.ne.jp');
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/satellite/buddies/api.php'), '/\\');
    return $scheme . '://' . $host . $dir . '/verified/index.html?invite=' . rawurlencode($token);
}

function normalizeCollaboratorInviteToken(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    $parts = parse_url($raw);
    if (isset($parts['query'])) {
        parse_str((string)$parts['query'], $query);
        if (!empty($query['invite'])) $raw = (string)$query['invite'];
    }
    $raw = trim(rawurldecode($raw));
    return strtolower(preg_replace('/[^a-fA-F0-9]/', '', $raw) ?? '');
}

function actionVerifiedCollaboratorSearchUsers(): void {
    $a = requireVerifiedOwner();
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) ok([]);
    $like = '%' . $q . '%';
    $st = db()->prepare(
        "SELECT u.id, u.username, u.display_name, u.user_icon, u.oshi_member, p.location
           FROM sakulabo_users u
           LEFT JOIN buddies_profiles p ON p.user_id = u.id
          WHERE u.id != ? AND (u.display_name LIKE ? OR u.username LIKE ?)
          ORDER BY CASE WHEN u.display_name = ? OR u.username = ? THEN 0 ELSE 1 END, u.display_name ASC, u.username ASC
          LIMIT 20"
    );
    $st->execute([(int)($a['user_id'] ?? 0), $like, $like, $q, $q]);
    ok(array_map(fn($u) => [
        'id' => (int)$u['id'],
        'username' => (string)$u['username'],
        'display_name' => $u['display_name'] ?: $u['username'],
        'user_icon' => $u['user_icon'] ?? null,
        'oshi_member' => $u['oshi_member'] ?? null,
        'location' => $u['location'] ?? null,
    ], $st->fetchAll()));
}

function actionVerifiedCollaboratorList(): void {
    $a = requireVerifiedAccount();
    $st = db()->prepare(
        "SELECT c.*, u.username, u.display_name, u.user_icon
           FROM buddies_verified_collaborators c
           JOIN sakulabo_users u ON u.id = c.user_id
          WHERE c.account_id=? AND c.status='active'
          ORDER BY c.accepted_at DESC, c.created_at DESC"
    );
    $st->execute([(int)$a['id']]);
    ok([
        'is_owner' => isVerifiedOwner($a),
        'collaborators' => array_map(fn($r) => [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'display_name' => $r['display_name'] ?: $r['username'],
            'username' => (string)$r['username'],
            'user_icon' => $r['user_icon'] ?? null,
            'role' => (string)$r['role'],
            'accepted_at' => $r['accepted_at'],
        ], $st->fetchAll()),
    ]);
}

function actionVerifiedCollaboratorInviteCreate(): void {
    $a = requireVerifiedOwner();
    $targetId = (int)(body()['user_id'] ?? 0);
    if ($targetId <= 0) err('招待するユーザーを選択してください。');
    if (!empty($a['user_id']) && $targetId === (int)$a['user_id']) err('自分自身は招待できません。');
    $u = db()->prepare('SELECT id, username, display_name, user_icon FROM sakulabo_users WHERE id=? LIMIT 1');
    $u->execute([$targetId]);
    $target = $u->fetch();
    if (!$target) err('ユーザーが見つかりません。', 404);
    $exists = db()->prepare("SELECT id FROM buddies_verified_collaborators WHERE account_id=? AND user_id=? AND status='active' LIMIT 1");
    $exists->execute([(int)$a['id'], $targetId]);
    if ($exists->fetch()) err('このユーザーはすでに共同運営者です。', 409);

    $pending = db()->prepare("SELECT token, expires_at FROM buddies_verified_collab_invites WHERE account_id=? AND target_user_id=? AND status='pending' AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
    $pending->execute([(int)$a['id'], $targetId]);
    $existing = $pending->fetch();
    if ($existing) {
        ok([
            'token' => (string)$existing['token'],
            'invite_url' => communityInviteUrl((string)$existing['token']),
            'expires_at' => $existing['expires_at'],
            'reused' => true,
            'target_user' => [
                'id' => (int)$target['id'],
                'display_name' => $target['display_name'] ?: $target['username'],
                'username' => (string)$target['username'],
                'user_icon' => $target['user_icon'] ?? null,
            ],
        ]);
    }

    db()->prepare("UPDATE buddies_verified_collab_invites SET status='expired' WHERE account_id=? AND target_user_id=? AND status='pending' AND expires_at <= NOW()")
        ->execute([(int)$a['id'], $targetId]);
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+14 days'));
    db()->prepare("INSERT INTO buddies_verified_collab_invites (account_id,target_user_id,invited_by_user_id,token,expires_at) VALUES (?,?,?,?,?)")
        ->execute([(int)$a['id'], $targetId, (int)($a['_access_user_id'] ?? $a['user_id'] ?? 0), $token, $expires]);
    ok([
        'token' => $token,
        'invite_url' => communityInviteUrl($token),
        'expires_at' => $expires,
        'target_user' => [
            'id' => (int)$target['id'],
            'display_name' => $target['display_name'] ?: $target['username'],
            'username' => (string)$target['username'],
            'user_icon' => $target['user_icon'] ?? null,
        ],
    ]);
}

function actionVerifiedCollaboratorInviteAccept(): void {
    $u = requireAuth();
    $token = normalizeCollaboratorInviteToken((string)(body()['token'] ?? $_GET['token'] ?? ''));
    if ($token === '') err('招待リンクが正しくありません。');
    $st = db()->prepare(
        "SELECT i.*, a.display_name AS account_name, a.status AS account_status, c.status AS collaborator_status
           FROM buddies_verified_collab_invites i
           JOIN buddies_verified_accounts a ON a.id = i.account_id
           LEFT JOIN buddies_verified_collaborators c ON c.account_id = i.account_id AND c.user_id = ?
          WHERE i.token=?
          LIMIT 1"
    );
    $st->execute([(int)$u['id'], $token]);
    $invite = $st->fetch();
    if (!$invite) err('招待リンクが見つかりません。URLが途中で切れていないか確認してください。', 404);
    if ($invite['account_status'] === 'disabled') err('このコミュニティアカウントは現在利用できません。', 410);
    if ((int)$invite['target_user_id'] !== (int)$u['id']) err('この招待は現在ログイン中のアカウント宛ではありません。対象のBuddies profileでログインしてください。', 403);
    if ((string)$invite['status'] === 'accepted' && (string)($invite['collaborator_status'] ?? '') === 'active') {
        $a = verifiedAccountById((int)$invite['account_id']);
        ok(['account' => $a ? buildVerifiedData(['_access_role'=>'manager','_access_user_id'=>(int)$u['id']] + $a, true) : null, 'already_accepted' => true]);
    }
    if ((string)$invite['status'] === 'revoked') err('この招待リンクは新しい招待リンクに置き換えられています。主催者に最新のリンクを共有してもらってください。', 410);
    if ((string)$invite['status'] === 'expired' || strtotime((string)$invite['expires_at']) < time()) {
        db()->prepare("UPDATE buddies_verified_collab_invites SET status='expired' WHERE id=? AND status='pending'")
            ->execute([(int)$invite['id']]);
        err('招待リンクの有効期限が切れています。', 410);
    }
    if ((string)$invite['status'] !== 'pending') err('この招待リンクは利用できません。主催者に最新のリンクを共有してもらってください。', 410);
    db()->prepare("INSERT INTO buddies_verified_collaborators (account_id,user_id,role,status,invited_by_user_id,accepted_at)
                   VALUES (?,?, 'manager','active',?,NOW())
                   ON DUPLICATE KEY UPDATE status='active', role='manager', invited_by_user_id=VALUES(invited_by_user_id), accepted_at=NOW()")
        ->execute([(int)$invite['account_id'], (int)$u['id'], (int)$invite['invited_by_user_id']]);
    db()->prepare("UPDATE buddies_verified_collab_invites SET status='accepted', accepted_at=NOW() WHERE id=?")
        ->execute([(int)$invite['id']]);
    $a = verifiedAccountById((int)$invite['account_id']);
    ok(['account' => $a ? buildVerifiedData(['_access_role'=>'manager','_access_user_id'=>(int)$u['id']] + $a, true) : null]);
}

function actionVerifiedPostList(): void {
    ensureVerifiedPostTables();
    $accountId = (int)($_GET['account_id'] ?? 0);
    if ($accountId <= 0) err('account_id は必須です。');
    $a = verifiedAccountById($accountId);
    if (!$a || ($a['status'] ?? 'active') !== 'active' || !canUseVerifiedBoard($a)) ok([]);
    $limit = min(max((int)($_GET['limit'] ?? 20), 1), 50);
    $st = db()->prepare("SELECT * FROM buddies_verified_posts WHERE account_id=? AND status='active' ORDER BY created_at DESC, id DESC LIMIT ?");
    $st->bindValue(1, $accountId, PDO::PARAM_INT);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    ok(array_map(fn($p) => buildVerifiedPostData($p), $st->fetchAll()));
}

function actionVerifiedPostCreate(): void {
    ensureVerifiedPostTables();
    $a = requireVerifiedAccount();
    if (!canUseVerifiedBoard($a)) err('このコミュニティアカウントでは掲示板を利用できません。', 403);
    $b = body();
    $body = trim((string)($b['body'] ?? ''));
    if (mb_strlen($body) > 8000) err('本文は8000文字以内で入力してください。');
    $linkUrl = cleanUrl($b['link_url'] ?? null);
    $linkLabel = trim((string)($b['link_label'] ?? ''));
    if (mb_strlen($linkLabel) > 120) err('リンク文言は120文字以内で入力してください。');
    if ($linkLabel === '') $linkLabel = null;

    $files = [];
    $imageCount = 0;
    $pdfCount = 0;
    $incomingFiles = [];
    if (isset($b['files']) && is_array($b['files'])) {
        $incomingFiles = array_slice($b['files'], 0, 3);
    } elseif (!empty($b['file'])) {
        $incomingFiles[] = ['data' => $b['file'], 'name' => $b['file_name'] ?? null];
    }
    foreach ($incomingFiles as $incoming) {
        if (!is_array($incoming)) continue;
        $dataUrl = (string)($incoming['data'] ?? '');
        if ($dataUrl === '') continue;
        if (!preg_match('/^data:(image\/png|image\/jpeg|image\/webp|application\/pdf);base64,/', $dataUrl, $m)) {
            err('画像（PNG/JPEG/WebP）またはPDFファイルを指定してください。');
        }
        $fileMime = $m[1];
        if (strpos($fileMime, 'image/') === 0) {
            $imageCount++;
            if ($imageCount > 2) err('画像は2枚まで投稿できます。');
        } elseif ($fileMime === 'application/pdf') {
            $pdfCount++;
            if ($pdfCount > 1) err('PDFは1件まで投稿できます。');
        }
        $raw = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
        if ($raw === false) err('ファイルを読み込めませんでした。');
        if (strlen($raw) > 8 * 1024 * 1024) err('ファイルサイズは8MB以内にしてください。');
        if (strpos($fileMime, 'image/') === 0 && !@getimagesizefromstring($raw)) err('有効な画像ファイルではありません。');
        if ($fileMime === 'application/pdf' && substr($raw, 0, 4) !== '%PDF') err('有効なPDFファイルではありません。');
        $ext = match ($fileMime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'pdf',
        };
        $fileName = cleanUploadFileName((string)($incoming['name'] ?? ''), $ext === 'pdf' ? 'PDFファイル' : '画像');
        $dir = __DIR__ . '/uploads/verified/' . (int)$a['id'] . '/posts';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) err('アップロード先を作成できません。', 500);
        $fileId = bin2hex(random_bytes(16));
        while (is_file($dir . '/' . $fileId . '.' . $ext)) $fileId = bin2hex(random_bytes(16));
        $path = $dir . '/' . $fileId . '.' . $ext;
        if (file_put_contents($path, $raw) === false) err('ファイルを保存できませんでした。', 500);
        $files[] = [
            'url' => 'uploads/verified/' . (int)$a['id'] . '/posts/' . $fileId . '.' . $ext,
            'name' => $fileName,
            'mime' => $fileMime,
            'size' => strlen($raw),
        ];
    }
    if ($body === '' && !$linkUrl && !$files) err('本文、リンク、ファイルのいずれかを入力してください。');

    $first = $files[0] ?? null;
    db()->prepare("INSERT INTO buddies_verified_posts (account_id, body, link_url, link_label, files, file_url, file_name, file_mime, file_size)
                   VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([
           (int)$a['id'], $body !== '' ? $body : null, $linkUrl, $linkLabel,
           $files ? json_encode($files, JSON_UNESCAPED_UNICODE) : null,
           $first['url'] ?? null, $first['name'] ?? null, $first['mime'] ?? null, $first['size'] ?? null,
       ]);
    $id = (int)db()->lastInsertId();
    $st = db()->prepare("SELECT * FROM buddies_verified_posts WHERE id=? LIMIT 1");
    $st->execute([$id]);
    ok(buildVerifiedPostData($st->fetch()));
}

function actionVerifiedPostDelete(): void {
    ensureVerifiedPostTables();
    $a = requireVerifiedAccount();
    if (!canUseVerifiedBoard($a)) err('このコミュニティアカウントでは掲示板を利用できません。', 403);
    $id = (int)(body()['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) err('id は必須です。');
    $st = db()->prepare("SELECT * FROM buddies_verified_posts WHERE id=? AND status='active' LIMIT 1");
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p || (int)$p['account_id'] !== (int)$a['id']) err('削除権限がありません。', 403);
    foreach (verifiedPostFiles($p) as $file) {
        $base = __DIR__ . '/uploads/verified/' . (int)$a['id'];
        $rel = strtok((string)$file['url'], '?');
        if ($rel && !preg_match('/^https?:\/\//', $rel)) deletePathInside(__DIR__ . '/' . ltrim($rel, '/'), $base);
    }
    db()->prepare("UPDATE buddies_verified_posts SET status='disabled' WHERE id=?")->execute([$id]);
    ok();
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  イベント
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
function ensureEventTables(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_events (
        id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id    BIGINT UNSIGNED NOT NULL,
        title         VARCHAR(160) NOT NULL,
        description   TEXT NULL DEFAULT NULL,
        venue         VARCHAR(200) NULL DEFAULT NULL,
        starts_at     DATETIME NULL DEFAULT NULL,
        ends_at       DATETIME NULL DEFAULT NULL,
        cover_url     VARCHAR(2048) NULL DEFAULT NULL,
        attachments   TEXT NULL DEFAULT NULL,
        capacity      INT NULL DEFAULT NULL,
        external_url  VARCHAR(2048) NULL DEFAULT NULL,
        external_label VARCHAR(80) NULL DEFAULT NULL,
        external_button_highlight TINYINT(1) NOT NULL DEFAULT 0,
        participant_enabled TINYINT(1) NOT NULL DEFAULT 1,
        checkin_enabled TINYINT(1) NOT NULL DEFAULT 0,
        fee_items TEXT NULL DEFAULT NULL,
        visibility    VARCHAR(16) NOT NULL DEFAULT 'public',
        status        VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_account (account_id, status),
        KEY idx_starts (starts_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_event_participants (
        id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id   BIGINT UNSIGNED NOT NULL,
        user_id    BIGINT UNSIGNED NOT NULL,
        kind       VARCHAR(10) NOT NULL DEFAULT 'join',
        checked_in_at DATETIME NULL DEFAULT NULL,
        checked_in_by_account_id BIGINT UNSIGNED NULL DEFAULT NULL,
        registered_on_site TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_event_user_kind (event_id, user_id, kind),
        KEY idx_event_kind (event_id, kind),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_subevents (
        id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id       BIGINT UNSIGNED NOT NULL,
        title          VARCHAR(160) NOT NULL,
        description    TEXT NULL DEFAULT NULL,
        venue          VARCHAR(200) NULL DEFAULT NULL,
        starts_at      DATETIME NULL DEFAULT NULL,
        ends_at        DATETIME NULL DEFAULT NULL,
        cover_url      VARCHAR(2048) NULL DEFAULT NULL,
        capacity       INT NULL DEFAULT NULL,
        external_url   VARCHAR(2048) NULL DEFAULT NULL,
        external_label VARCHAR(80) NULL DEFAULT NULL,
        external_button_highlight TINYINT(1) NOT NULL DEFAULT 0,
        participant_enabled TINYINT(1) NOT NULL DEFAULT 1,
        sort_order     INT NOT NULL DEFAULT 0,
        status         VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_event (event_id, status),
        KEY idx_starts (starts_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_subevent_participants (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        subevent_id BIGINT UNSIGNED NOT NULL,
        user_id     BIGINT UNSIGNED NOT NULL,
        checked_in_at DATETIME NULL DEFAULT NULL,
        checked_in_by_account_id BIGINT UNSIGNED NULL DEFAULT NULL,
        registered_on_site TINYINT(1) NOT NULL DEFAULT 0,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_subevent_user (subevent_id, user_id),
        KEY idx_subevent (subevent_id),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $cols = array_column($pdo->query('DESCRIBE buddies_events')->fetchAll(), 'Field');
        if (!in_array('visibility', $cols, true)) {
            $pdo->exec("ALTER TABLE buddies_events ADD COLUMN visibility VARCHAR(16) NOT NULL DEFAULT 'public' AFTER external_label");
        }
        if (!in_array('attachments', $cols, true)) {
            $pdo->exec("ALTER TABLE buddies_events ADD COLUMN attachments TEXT NULL DEFAULT NULL AFTER cover_url");
        }
        if (!in_array('external_button_highlight', $cols, true)) {
            $pdo->exec("ALTER TABLE buddies_events ADD COLUMN external_button_highlight TINYINT(1) NOT NULL DEFAULT 0 AFTER external_label");
        }
        if (!in_array('participant_enabled', $cols, true)) {
            $pdo->exec("ALTER TABLE buddies_events ADD COLUMN participant_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER external_button_highlight");
        }
        if (!in_array('checkin_enabled', $cols, true)) {
            $pdo->exec("ALTER TABLE buddies_events ADD COLUMN checkin_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER participant_enabled");
        }
        if (!in_array('fee_items', $cols, true)) {
            $pdo->exec("ALTER TABLE buddies_events ADD COLUMN fee_items TEXT NULL DEFAULT NULL AFTER checkin_enabled");
        }
        $participantCols = array_column($pdo->query('DESCRIBE buddies_event_participants')->fetchAll(), 'Field');
        if (!in_array('checked_in_at', $participantCols, true)) {
            $pdo->exec("ALTER TABLE buddies_event_participants ADD COLUMN checked_in_at DATETIME NULL DEFAULT NULL AFTER kind");
        }
        if (!in_array('checked_in_by_account_id', $participantCols, true)) {
            $pdo->exec("ALTER TABLE buddies_event_participants ADD COLUMN checked_in_by_account_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER checked_in_at");
        }
        if (!in_array('registered_on_site', $participantCols, true)) {
            $pdo->exec("ALTER TABLE buddies_event_participants ADD COLUMN registered_on_site TINYINT(1) NOT NULL DEFAULT 0 AFTER checked_in_by_account_id");
        }
        $subParticipantCols = array_column($pdo->query('DESCRIBE buddies_subevent_participants')->fetchAll(), 'Field');
        if (!in_array('checked_in_at', $subParticipantCols, true)) {
            $pdo->exec("ALTER TABLE buddies_subevent_participants ADD COLUMN checked_in_at DATETIME NULL DEFAULT NULL AFTER user_id");
        }
        if (!in_array('checked_in_by_account_id', $subParticipantCols, true)) {
            $pdo->exec("ALTER TABLE buddies_subevent_participants ADD COLUMN checked_in_by_account_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER checked_in_at");
        }
        if (!in_array('registered_on_site', $subParticipantCols, true)) {
            $pdo->exec("ALTER TABLE buddies_subevent_participants ADD COLUMN registered_on_site TINYINT(1) NOT NULL DEFAULT 0 AFTER checked_in_by_account_id");
        }
        $idx = $pdo->query("SHOW INDEX FROM buddies_event_participants WHERE Key_name='uq_event_user'")->fetchAll();
        if ($idx) {
            $pdo->exec("ALTER TABLE buddies_event_participants DROP INDEX uq_event_user");
            $pdo->exec("ALTER TABLE buddies_event_participants ADD UNIQUE KEY uq_event_user_kind (event_id, user_id, kind)");
        }
        $subCols = array_column($pdo->query('DESCRIBE buddies_subevents')->fetchAll(), 'Field');
        $subRequired = [
            'description' => "ALTER TABLE buddies_subevents ADD COLUMN description TEXT NULL DEFAULT NULL AFTER title",
            'venue' => "ALTER TABLE buddies_subevents ADD COLUMN venue VARCHAR(200) NULL DEFAULT NULL AFTER description",
            'starts_at' => "ALTER TABLE buddies_subevents ADD COLUMN starts_at DATETIME NULL DEFAULT NULL AFTER venue",
            'ends_at' => "ALTER TABLE buddies_subevents ADD COLUMN ends_at DATETIME NULL DEFAULT NULL AFTER starts_at",
            'cover_url' => "ALTER TABLE buddies_subevents ADD COLUMN cover_url VARCHAR(2048) NULL DEFAULT NULL AFTER ends_at",
            'capacity' => "ALTER TABLE buddies_subevents ADD COLUMN capacity INT NULL DEFAULT NULL AFTER cover_url",
            'external_url' => "ALTER TABLE buddies_subevents ADD COLUMN external_url VARCHAR(2048) NULL DEFAULT NULL AFTER capacity",
            'external_label' => "ALTER TABLE buddies_subevents ADD COLUMN external_label VARCHAR(80) NULL DEFAULT NULL AFTER external_url",
            'external_button_highlight' => "ALTER TABLE buddies_subevents ADD COLUMN external_button_highlight TINYINT(1) NOT NULL DEFAULT 0 AFTER external_label",
            'participant_enabled' => "ALTER TABLE buddies_subevents ADD COLUMN participant_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER external_button_highlight",
            'sort_order' => "ALTER TABLE buddies_subevents ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER external_label",
            'status' => "ALTER TABLE buddies_subevents ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER sort_order",
            'created_at' => "ALTER TABLE buddies_subevents ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status",
            'updated_at' => "ALTER TABLE buddies_subevents ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];
        foreach ($subRequired as $col => $sql) {
            if (!in_array($col, $subCols, true)) $pdo->exec($sql);
        }
    } catch (\Throwable $e) {}
}

function eventVisibleDateCondition(string $alias = ''): string {
    $p = $alias !== '' ? $alias . '.' : '';
    // 終了日時が指定されている場合だけ終了判定する。
    // ends_at が null のイベントは「終了未定」として、開始日時が過去でも表示を維持する。
    return "({$p}ends_at IS NULL OR {$p}ends_at >= NOW())";
}
function sortEventsSql(string $alias = ''): string {
    $p = $alias !== '' ? $alias . '.' : '';
    return "(COALESCE({$p}starts_at, {$p}ends_at) IS NULL), COALESCE({$p}starts_at, {$p}ends_at) ASC, {$p}created_at ASC";
}
function eventAttachments(array $e): array {
    $raw = $e['attachments'] ?? null;
    if (!$raw) return [];
    $arr = json_decode($raw, true);
    if (!is_array($arr)) return [];
    return array_values(array_filter(array_map(function($f) {
        if (!is_array($f) || empty($f['url']) || empty($f['name'])) return null;
        return [
            'id' => (string)($f['id'] ?? ''),
            'name' => (string)$f['name'],
            'url' => (string)$f['url'],
            'mime' => (string)($f['mime'] ?? ''),
            'size' => (int)($f['size'] ?? 0),
            'uploaded_at' => $f['uploaded_at'] ?? null,
        ];
    }, $arr)));
}
function eventFeeItems(array $e): array {
    $raw = $e['fee_items'] ?? null;
    $arr = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($arr)) return [];
    return array_values(array_filter(array_map(function($item) {
        if (!is_array($item)) return null;
        $label = mb_substr(trim((string)($item['label'] ?? '')), 0, 80);
        $amount = mb_substr(trim((string)($item['amount'] ?? '')), 0, 80);
        return ($label !== '' && $amount !== '') ? ['label' => $label, 'amount' => $amount] : null;
    }, array_slice($arr, 0, 20))));
}
function cleanUploadFileName(string $name, string $fallback): string {
    $name = trim(basename($name));
    $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name);
    $name = trim((string)$name, " .\t\n\r\0\x0B");
    if ($name === '') $name = $fallback;
    return mb_substr($name, 0, 120);
}
function deletePathInside(string $path, string $base): void {
    $baseReal = realpath($base);
    if (!$baseReal) return;
    $targetReal = file_exists($path) ? realpath($path) : false;
    if (!$targetReal || strpos($targetReal, $baseReal) !== 0) return;
    if (is_file($targetReal)) @unlink($targetReal);
}
function deleteDirInside(string $dir, string $base): void {
    $baseReal = realpath($base);
    $dirReal = is_dir($dir) ? realpath($dir) : false;
    if (!$baseReal || !$dirReal || strpos($dirReal, $baseReal) !== 0) return;
    $items = scandir($dirReal);
    if (!$items) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dirReal . '/' . $item;
        if (is_dir($path)) deleteDirInside($path, $baseReal);
        elseif (is_file($path)) @unlink($path);
    }
    @rmdir($dirReal);
}
function deleteEventUploadFiles(array $event, int $accountId): void {
    $base = __DIR__ . '/uploads/verified/' . $accountId;
    if (!empty($event['cover_url'])) {
        $rel = strtok((string)$event['cover_url'], '?');
        if ($rel && !preg_match('/^https?:\/\//', $rel)) deletePathInside(__DIR__ . '/' . ltrim($rel, '/'), $base);
    }
    foreach (eventAttachments($event) as $file) {
        $rel = strtok((string)($file['url'] ?? ''), '?');
        if ($rel && !preg_match('/^https?:\/\//', $rel)) deletePathInside(__DIR__ . '/' . ltrim($rel, '/'), $base);
    }
    deleteDirInside($base . '/event_files/' . (int)$event['id'], $base);
}

function eventEditableFields(array $b): array {
    $title = trim((string)($b['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 160) err('タイトルは1〜160文字で入力してください。');
    $description = trim((string)($b['description'] ?? ''));
    if (mb_strlen($description) > 4000) err('説明は4000文字以内で入力してください。');
    $venue = trim((string)($b['venue'] ?? ''));
    if (mb_strlen($venue) > 200) err('会場は200文字以内で入力してください。');
    $startsAt = trim((string)($b['starts_at'] ?? ''));
    $endsAt   = trim((string)($b['ends_at'] ?? ''));
    foreach (['startsAt' => &$startsAt, 'endsAt' => &$endsAt] as $k => &$v) {
        if ($v === '') { $v = null; continue; }
        $ts = strtotime($v);
        if ($ts === false) err('日時の形式が正しくありません。');
        $v = date('Y-m-d H:i:s', $ts);
    }
    unset($v);
    $externalUrl = cleanUrl($b['external_url'] ?? null);
    $externalLabel = trim((string)($b['external_label'] ?? ''));
    if (mb_strlen($externalLabel) > 80) err('リンク文言は80文字以内で入力してください。');
    if ($externalLabel === '') $externalLabel = null;
    $capacity = isset($b['capacity']) && $b['capacity'] !== '' ? (int)$b['capacity'] : null;
    if ($capacity !== null && ($capacity < 1 || $capacity > 100000)) err('満員数は1〜100000で入力してください。');
    $visibility = (string)($b['visibility'] ?? 'public');
    if (!in_array($visibility, ['public','unlisted'], true)) $visibility = 'public';
    $participantEnabled = truthyFlag($b['participant_enabled'] ?? 1);
    $checkinEnabled = $participantEnabled && truthyFlag($b['checkin_enabled'] ?? 0);
    $feeItems = eventFeeItems(['fee_items' => $b['fee_items'] ?? []]);
    return [
        'title'          => $title,
        'description'    => $description !== '' ? $description : null,
        'venue'          => $venue !== '' ? $venue : null,
        'starts_at'      => $startsAt,
        'ends_at'        => $endsAt,
        'capacity'       => $participantEnabled ? $capacity : null,
        'external_url'   => $externalUrl,
        'external_label' => $externalLabel,
        'external_button_highlight' => truthyFlag($b['external_button_highlight'] ?? 0),
        'participant_enabled' => $participantEnabled,
        'checkin_enabled' => $checkinEnabled,
        'fee_items'      => $feeItems,
        'visibility'     => $visibility,
    ];
}
function buildEventData(array $e, ?int $viewerId = null, bool $includeAttachments = false): array {
    $eventId = (int)$e['id'];
    $countSt = db()->prepare("SELECT kind, COUNT(*) c FROM buddies_event_participants WHERE event_id=? GROUP BY kind");
    $countSt->execute([$eventId]);
    $counts = ['join' => 0, 'like' => 0];
    foreach ($countSt->fetchAll() as $row) { $counts[$row['kind']] = (int)$row['c']; }
    $checkinSt = db()->prepare("SELECT COUNT(*) FROM buddies_event_participants WHERE event_id=? AND kind='join' AND checked_in_at IS NOT NULL");
    $checkinSt->execute([$eventId]);
    $checkinCount = (int)$checkinSt->fetchColumn();
    $visibility = $e['visibility'] ?? 'public';
    if ($visibility !== 'public') $counts['like'] = 0;
    $myKinds = [];
    $isCheckedIn = false;
    if ($viewerId) {
        $mySt = db()->prepare("SELECT kind, checked_in_at FROM buddies_event_participants WHERE event_id=? AND user_id=?");
        $mySt->execute([$eventId, $viewerId]);
        foreach ($mySt->fetchAll() as $row) {
            if ($visibility !== 'public' && $row['kind'] === 'like') continue;
            $myKinds[] = $row['kind'];
            if ($row['kind'] === 'join' && !empty($row['checked_in_at'])) $isCheckedIn = true;
        }
    }
    if (!$includeAttachments && $viewerId && in_array('join', $myKinds, true)) $includeAttachments = true;
    return [
        'id'             => $eventId,
        'account_id'     => (int)$e['account_id'],
        'title'          => $e['title'],
        'description'    => $e['description'],
        'venue'          => $e['venue'],
        'starts_at'      => $e['starts_at'],
        'ends_at'        => $e['ends_at'],
        'cover_url'      => $e['cover_url'],
        'attachments'    => $includeAttachments ? eventAttachments($e) : [],
        'capacity'       => $e['capacity'] !== null ? (int)$e['capacity'] : null,
        'external_url'   => $e['external_url'],
        'external_label' => $e['external_label'],
        'external_button_highlight' => !empty($e['external_button_highlight']),
        'participant_enabled' => !isset($e['participant_enabled']) || !empty($e['participant_enabled']),
        'checkin_enabled' => !empty($e['checkin_enabled']),
        'fee_items'      => eventFeeItems($e),
        'visibility'     => $visibility,
        'status'         => $e['status'],
        'created_at'     => $e['created_at'],
        'join_count'     => $counts['join'],
        'checkin_count'  => $checkinCount,
        'like_count'     => $counts['like'],
        'my_kinds'       => $myKinds,
        'is_joined'      => in_array('join', $myKinds, true),
        'is_liked'       => in_array('like', $myKinds, true),
        'is_checked_in'  => $isCheckedIn,
    ];
}
function actionEventListByAccount(): void { ensureEventTables();
    $accountId = (int)($_GET['account_id'] ?? 0);
    if ($accountId <= 0) err('account_id は必須です。');
    $verified = currentVerifiedAccount();
    $isOwner = $verified && (int)$verified['id'] === $accountId;
    $visibilityCondition = $isOwner ? '' : " AND visibility='public'";
    $st = db()->prepare("SELECT * FROM buddies_events WHERE account_id=? AND status='active'" . $visibilityCondition . "
                         AND " . eventVisibleDateCondition() . "
                         ORDER BY " . sortEventsSql());
    $st->execute([$accountId]);
    $viewer = currentUser();
    $viewerId = $viewer ? (int)$viewer['id'] : null;
    ok(array_map(fn($e) => buildEventData($e, $viewerId), $st->fetchAll()));
}
function actionEventGet(): void { ensureEventTables();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) err('id は必須です。');
    $st = db()->prepare("SELECT * FROM buddies_events WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e || $e['status'] === 'disabled') err('イベントが見つかりません。', 404);
    $viewer = currentUser();
    $viewerId = $viewer ? (int)$viewer['id'] : null;
    $verified = currentVerifiedAccount();
    $includeAttachments = $verified && (int)$verified['id'] === (int)$e['account_id'];
    ok(buildEventData($e, $viewerId, $includeAttachments));
}
function actionEventParticipants(): void { ensureEventTables();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) err('id は必須です。');
    // join のみ公開
    $st = db()->prepare(
        "SELECT u.id, u.display_name, u.username, u.user_icon, u.oshi_member, p.location, p.bio
           FROM buddies_event_participants ep
           JOIN sakulabo_users u ON u.id = ep.user_id
           LEFT JOIN buddies_profiles p ON p.user_id = u.id
          WHERE ep.event_id=? AND ep.kind='join'
          ORDER BY ep.created_at DESC"
    );
    $st->execute([$id]);
    $rows = $st->fetchAll();
    $data = array_map(fn($r) => [
        'id'           => (int)$r['id'],
        'display_name' => $r['display_name'] ?: $r['username'],
        'user_icon'    => $r['user_icon'] ?? null,
        'oshi_member'  => $r['oshi_member'] ?? null,
        'location'     => $r['location'] ?? null,
        'bio'          => $r['bio'] ?? null,
    ], $rows);
    ok($data);
}
function actionEventParticipantsAdmin(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $eventId = (int)($_GET['event_id'] ?? 0);
    $where = 'e.account_id=? AND e.status!="disabled"';
    $params = [(int)$a['id']];
    if ($eventId > 0) { $where .= ' AND e.id=?'; $params[] = $eventId; }
    $sql = "SELECT ep.event_id, ep.created_at AS joined_at, ep.checked_in_at, ep.registered_on_site,
                   e.title AS event_title, e.starts_at AS event_starts_at,
                   u.id, u.username, u.display_name, u.user_icon, u.oshi_member, u.oshi_member_2, u.oshi_member_3,
                   p.location, p.bio, p.tags, p.favorite_songs, p.sns_links
              FROM buddies_event_participants ep
              JOIN buddies_events e ON e.id = ep.event_id
              JOIN sakulabo_users u ON u.id = ep.user_id
              LEFT JOIN buddies_profiles p ON p.user_id = u.id
             WHERE {$where} AND ep.kind='join'
             ORDER BY e.starts_at DESC, (ep.checked_in_at IS NOT NULL) ASC, ep.created_at DESC";
    $st = db()->prepare($sql);
    $st->execute($params);
    $subSt = db()->prepare(
        "SELECT sp.user_id, s.id, s.event_id, s.title, sp.created_at
           FROM buddies_subevent_participants sp
           JOIN buddies_subevents s ON s.id = sp.subevent_id
           JOIN buddies_events e ON e.id = s.event_id
          WHERE e.account_id=? AND s.status='active'"
    );
    $subSt->execute([(int)$a['id']]);
    $subs = [];
    foreach ($subSt->fetchAll() as $r) {
        $subs[(int)$r['event_id'] . ':' . (int)$r['user_id']][] = [
            'id' => (int)$r['id'],
            'title' => (string)$r['title'],
            'joined_at' => $r['created_at'],
        ];
    }
    ok(array_map(function($r) use ($subs) {
        $key = (int)$r['event_id'] . ':' . (int)$r['id'];
        return [
            'event_id' => (int)$r['event_id'],
            'event_title' => (string)$r['event_title'],
            'event_starts_at' => $r['event_starts_at'],
            'joined_at' => $r['joined_at'],
            'checked_in_at' => $r['checked_in_at'],
            'registered_on_site' => !empty($r['registered_on_site']),
            'user' => [
                'id' => (int)$r['id'],
                'username' => (string)$r['username'],
                'display_name' => $r['display_name'] ?: $r['username'],
                'user_icon' => $r['user_icon'] ?? null,
                'oshi_members' => array_values(array_filter([$r['oshi_member'] ?? null, $r['oshi_member_2'] ?? null, $r['oshi_member_3'] ?? null])),
                'location' => $r['location'] ?? null,
                'bio' => $r['bio'] ?? null,
                'tags' => !empty($r['tags']) ? (json_decode($r['tags'], true) ?: []) : [],
                'favorite_songs' => !empty($r['favorite_songs']) ? (json_decode($r['favorite_songs'], true) ?: []) : [],
                'sns_links' => !empty($r['sns_links']) ? (json_decode($r['sns_links'], true) ?: []) : [],
            ],
            'subevents' => $subs[$key] ?? [],
        ];
    }, $st->fetchAll()));
}
function actionEventMineList(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $st = db()->prepare("SELECT * FROM buddies_events WHERE account_id=? AND status!='disabled'
                         ORDER BY created_at DESC");
    $st->execute([$a['id']]);
    ok(array_map(fn($e) => buildEventData($e, null, true), $st->fetchAll()));
}
function actionEventCreate(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $f = eventEditableFields(body());
    db()->prepare("INSERT INTO buddies_events
        (account_id, title, description, venue, starts_at, ends_at, capacity, external_url, external_label, external_button_highlight, participant_enabled, checkin_enabled, fee_items, visibility)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([(int)$a['id'], $f['title'], $f['description'], $f['venue'],
                  $f['starts_at'], $f['ends_at'], $f['capacity'], $f['external_url'], $f['external_label'],
                  $f['external_button_highlight'], $f['participant_enabled'], $f['checkin_enabled'],
                  json_encode($f['fee_items'], JSON_UNESCAPED_UNICODE), $f['visibility']]);
    $id = (int)db()->lastInsertId();
    $st = db()->prepare("SELECT * FROM buddies_events WHERE id=? LIMIT 1");
    $st->execute([$id]);
    ok(buildEventData($st->fetch()));
}
function actionEventUpdate(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    if ($id <= 0) err('id は必須です。');
    $st = db()->prepare("SELECT * FROM buddies_events WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e || (int)$e['account_id'] !== (int)$a['id']) err('編集権限がありません。', 403);
    $f = eventEditableFields($b);
    db()->prepare("UPDATE buddies_events SET
        title=?, description=?, venue=?, starts_at=?, ends_at=?, capacity=?,
        external_url=?, external_label=?, external_button_highlight=?, participant_enabled=?, checkin_enabled=?, fee_items=?, visibility=? WHERE id=?")
       ->execute([$f['title'], $f['description'], $f['venue'], $f['starts_at'], $f['ends_at'],
                  $f['capacity'], $f['external_url'], $f['external_label'], $f['external_button_highlight'],
                  $f['participant_enabled'], $f['checkin_enabled'], json_encode($f['fee_items'], JSON_UNESCAPED_UNICODE), $f['visibility'], $id]);
    if ($f['visibility'] !== 'public') {
        db()->prepare("DELETE FROM buddies_event_participants WHERE event_id=? AND kind='like'")
           ->execute([$id]);
    }
    if (!$f['participant_enabled']) {
        db()->prepare("DELETE FROM buddies_event_participants WHERE event_id=? AND kind='join'")
           ->execute([$id]);
    }
    $st->execute([$id]);
    ok(buildEventData($st->fetch()));
}
function actionEventDelete(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $b = body();
    $id = (int)($b['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) err('id は必須です。');
    $st = db()->prepare("SELECT * FROM buddies_events WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e || (int)$e['account_id'] !== (int)$a['id']) err('削除権限がありません。', 403);
    deleteEventUploadFiles($e, (int)$a['id']);
    $subSt = db()->prepare("SELECT id, cover_url FROM buddies_subevents WHERE event_id=?");
    $subSt->execute([$id]);
    $base = __DIR__ . '/uploads/verified/' . (int)$a['id'];
    $subs = $subSt->fetchAll();
    foreach ($subs as $sub) {
        if (!empty($sub['cover_url'])) {
            $rel = strtok((string)$sub['cover_url'], '?');
            if ($rel && !preg_match('/^https?:\/\//', $rel)) deletePathInside(__DIR__ . '/' . ltrim($rel, '/'), $base);
        }
    }
    $subIds = array_map('intval', array_column($subs, 'id'));
    if ($subIds) {
        $in = implode(',', array_fill(0, count($subIds), '?'));
        db()->prepare("DELETE FROM buddies_subevent_participants WHERE subevent_id IN ($in)")->execute($subIds);
    }
    db()->prepare("DELETE FROM buddies_event_participants WHERE event_id=?")->execute([$id]);
    db()->prepare("UPDATE buddies_events SET status='disabled', cover_url=NULL, attachments=NULL WHERE id=?")->execute([$id]);
    db()->prepare("UPDATE buddies_subevents SET status='disabled', cover_url=NULL WHERE event_id=?")->execute([$id]);
    ok();
}
function actionEventUploadCover(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    if ($id <= 0) err('id は必須です。');
    $st = db()->prepare("SELECT * FROM buddies_events WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e || (int)$e['account_id'] !== (int)$a['id']) err('権限がありません。', 403);
    $dataUrl = (string)($b['image'] ?? '');
    if (!preg_match('/^data:image\/(png|jpeg|webp);base64,/', $dataUrl, $m)) err('PNG/JPEG/WebP画像を指定してください。');
    $raw = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
    if ($raw === false || !@getimagesizefromstring($raw)) err('有効な画像ファイルではありません。');
    if (strlen($raw) > 5 * 1024 * 1024) err('画像サイズが大きすぎます。');
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $accountId = (int)$a['id'];
    $dir = __DIR__ . '/uploads/verified/' . $accountId . '/events';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) err('アップロード先を作成できません。', 500);
    foreach (['png','jpg','webp'] as $oldExt) {
        $old = $dir . '/' . $id . '.' . $oldExt;
        if ($oldExt !== $ext && is_file($old)) @unlink($old);
    }
    $path = $dir . '/' . $id . '.' . $ext;
    if (file_put_contents($path, $raw) === false) err('画像を保存できませんでした。', 500);
    $url = 'uploads/verified/' . $accountId . '/events/' . $id . '.' . $ext . '?v=' . time();
    db()->prepare("UPDATE buddies_events SET cover_url=? WHERE id=?")->execute([$url, $id]);
    ok(['cover_url' => $url]);
}
function actionEventUploadAttachment(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    if ($id <= 0) err('id は必須です。');
    $st = db()->prepare("SELECT * FROM buddies_events WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e || (int)$e['account_id'] !== (int)$a['id']) err('権限がありません。', 403);
    $attachments = eventAttachments($e);
    if (count($attachments) >= 3) err('添付ファイルは3件までです。');

    $dataUrl = (string)($b['file'] ?? '');
    $name = cleanUploadFileName((string)($b['name'] ?? ''), 'event-file');
    if (!preg_match('/^data:(image\/png|image\/jpeg|image\/webp|application\/pdf);base64,/', $dataUrl, $m)) {
        err('PNG/JPEG/WebP画像またはPDFファイルを指定してください。');
    }
    $mime = $m[1];
    $raw = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
    if ($raw === false) err('ファイルを読み込めませんでした。');
    $limit = 5 * 1024 * 1024;
    if (strlen($raw) > $limit) err('ファイルサイズは5MB以内にしてください。');
    if (strpos($mime, 'image/') === 0 && !@getimagesizefromstring($raw)) err('有効な画像ファイルではありません。');
    if ($mime === 'application/pdf' && substr($raw, 0, 4) !== '%PDF') err('有効なPDFファイルではありません。');

    $ext = match ($mime) {
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        default => 'pdf',
    };
    $fileId = bin2hex(random_bytes(16));
    $accountId = (int)$a['id'];
    $dir = __DIR__ . '/uploads/verified/' . $accountId . '/event_files/' . $id;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) err('アップロード先を作成できません。', 500);
    while (is_file($dir . '/' . $fileId . '.' . $ext)) {
        $fileId = bin2hex(random_bytes(16));
    }
    $path = $dir . '/' . $fileId . '.' . $ext;
    if (file_put_contents($path, $raw) === false) err('ファイルを保存できませんでした。', 500);
    $url = 'uploads/verified/' . $accountId . '/event_files/' . $id . '/' . $fileId . '.' . $ext;
    $attachments[] = [
        'id' => $fileId,
        'name' => $name,
        'url' => $url,
        'mime' => $mime,
        'size' => strlen($raw),
        'uploaded_at' => date('Y-m-d H:i:s'),
    ];
    db()->prepare("UPDATE buddies_events SET attachments=? WHERE id=?")
       ->execute([json_encode($attachments, JSON_UNESCAPED_UNICODE), $id]);
    ok(['attachments' => $attachments]);
}
function actionEventDeleteAttachment(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $b = body();
    $eventId = (int)($b['id'] ?? 0);
    $fileId = trim((string)($b['file_id'] ?? ''));
    if ($eventId <= 0 || $fileId === '') err('id と file_id は必須です。');
    $st = db()->prepare("SELECT * FROM buddies_events WHERE id=? LIMIT 1");
    $st->execute([$eventId]);
    $e = $st->fetch();
    if (!$e || (int)$e['account_id'] !== (int)$a['id']) err('権限がありません。', 403);
    $attachments = eventAttachments($e);
    $removed = null;
    $next = [];
    foreach ($attachments as $file) {
        if (($file['id'] ?? '') === $fileId) $removed = $file;
        else $next[] = $file;
    }
    if (!$removed) err('添付ファイルが見つかりません。', 404);
    $rel = (string)$removed['url'];
    if ($rel !== '' && !preg_match('/^https?:\/\//', $rel)) {
        $path = __DIR__ . '/' . ltrim(strtok($rel, '?'), '/');
        $base = realpath(__DIR__ . '/uploads/verified/' . (int)$a['id'] . '/event_files/' . $eventId);
        $real = is_file($path) ? realpath($path) : false;
        if ($base && $real && strpos($real, $base) === 0) @unlink($real);
    }
    db()->prepare("UPDATE buddies_events SET attachments=? WHERE id=?")
       ->execute([json_encode($next, JSON_UNESCAPED_UNICODE), $eventId]);
    ok(['attachments' => $next]);
}
function actionEventJoin(): void { ensureEventTables();
    $u = requireAuth();
    $b = body();
    $eventId = (int)($b['event_id'] ?? 0);
    $kind = (string)($b['kind'] ?? '');
    $state = (string)($b['state'] ?? 'on');  // 'on' | 'off'
    if ($eventId <= 0) err('event_id は必須です。');
    if (!in_array($kind, ['join','like'], true)) err('kind が不正です。');
    if (!in_array($state, ['on','off'], true)) err('state が不正です。');
    $st = db()->prepare("SELECT * FROM buddies_events WHERE id=? AND status='active' LIMIT 1");
    $st->execute([$eventId]);
    $e = $st->fetch();
    if (!$e) err('イベントが見つかりません。', 404);
    if (!empty($e['ends_at']) && strtotime($e['ends_at']) < time()) err('イベントが終了しています。', 410);
    $uid = (int)$u['id'];

    if ($kind === 'like' && ($e['visibility'] ?? 'public') !== 'public') {
        if ($state === 'off') {
            db()->prepare("DELETE FROM buddies_event_participants WHERE event_id=? AND user_id=? AND kind='like'")
               ->execute([$eventId, $uid]);
            ok(['my_kinds' => [], 'is_joined' => false, 'is_liked' => false]);
        }
        err('非公開イベントではお気に入り機能を利用できません。', 403);
    }
    if ($kind === 'join' && isset($e['participant_enabled']) && empty($e['participant_enabled'])) {
        err('このイベントは参加登録を受け付けていません。', 403);
    }

    if ($state === 'off') {
        if ($kind === 'join') {
            $checkedSt = db()->prepare("SELECT checked_in_at FROM buddies_event_participants WHERE event_id=? AND user_id=? AND kind='join' LIMIT 1");
            $checkedSt->execute([$eventId, $uid]);
            $checked = $checkedSt->fetch();
            if ($checked && !empty($checked['checked_in_at'])) err('受付済みの参加記録は取り消せません。', 409);
        }
        db()->prepare("DELETE FROM buddies_event_participants WHERE event_id=? AND user_id=? AND kind=?")
           ->execute([$eventId, $uid, $kind]);
        if ($kind === 'join') {
            db()->prepare("DELETE sp FROM buddies_subevent_participants sp
                           JOIN buddies_subevents s ON s.id = sp.subevent_id
                           WHERE s.event_id=? AND sp.user_id=?")
               ->execute([$eventId, $uid]);
        }
    } else {
        if ($kind === 'join' && $e['capacity'] !== null) {
            $cntSt = db()->prepare("SELECT COUNT(*) c FROM buddies_event_participants WHERE event_id=? AND kind='join'");
            $cntSt->execute([$eventId]);
            $cur = (int)$cntSt->fetch()['c'];
            $alreadySt = db()->prepare("SELECT id FROM buddies_event_participants WHERE event_id=? AND user_id=? AND kind='join' LIMIT 1");
            $alreadySt->execute([$eventId, $uid]);
            if ($cur >= (int)$e['capacity'] && !$alreadySt->fetch()) err('参加枠が満員です。', 409);
        }
        db()->prepare("INSERT IGNORE INTO buddies_event_participants (event_id, user_id, kind) VALUES (?,?,?)")
           ->execute([$eventId, $uid, $kind]);
    }
    // 最新状態を返す
    $mySt = db()->prepare("SELECT kind FROM buddies_event_participants WHERE event_id=? AND user_id=?");
    $mySt->execute([$eventId, $uid]);
    $kinds = array_column($mySt->fetchAll(), 'kind');
    ok(['my_kinds' => $kinds, 'is_joined' => in_array('join', $kinds, true), 'is_liked' => in_array('like', $kinds, true)]);
}

function actionQrToken(): void {
    $u = requireAuth();
    $uid = (int)$u['id'];
    $expires = time() + 45;
    $signature = hash_hmac('sha256', $uid . ':' . $expires, qrSigningKey());
    ok(['token' => 'v2:' . $uid . ':' . $expires . ':' . $signature, 'expires_at' => $expires]);
}
function actionQrVerify(): void {
    requireAuth();
    $uid = signedQrUserId(trim((string)(body()['token'] ?? '')));
    if ($uid <= 0) err('QRコードが期限切れです。', 410);
    ok(['user_id' => $uid]);
}
function checkinUserData(int $userId): array {
    $st = db()->prepare(
        "SELECT u.id, u.username, u.display_name, u.user_icon, u.oshi_member, u.oshi_member_2, u.oshi_member_3, p.location
           FROM sakulabo_users u
           LEFT JOIN buddies_profiles p ON p.user_id=u.id
          WHERE u.id=? LIMIT 1"
    );
    $st->execute([$userId]);
    $r = $st->fetch();
    if (!$r) err('ユーザーが見つかりません。', 404);
    return [
        'id' => (int)$r['id'],
        'username' => (string)$r['username'],
        'display_name' => $r['display_name'] ?: $r['username'],
        'user_icon' => $r['user_icon'] ?? null,
        'oshi_members' => array_values(array_filter([$r['oshi_member'] ?? null, $r['oshi_member_2'] ?? null, $r['oshi_member_3'] ?? null])),
        'location' => $r['location'] ?? null,
    ];
}
function actionEventCheckinScan(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $b = body();
    $eventId = (int)($b['event_id'] ?? 0);
    $token = trim((string)($b['token'] ?? ''));
    $allowRegister = truthyFlag($b['allow_register'] ?? 0);
    if ($eventId <= 0 || $token === '') err('イベントとQRコードが必要です。');
    $st = db()->prepare("SELECT * FROM buddies_events WHERE id=? AND account_id=? AND status='active' LIMIT 1");
    $st->execute([$eventId, (int)$a['id']]);
    $event = $st->fetch();
    if (!$event) err('イベントが見つからないか、受付権限がありません。', 404);
    if (empty($event['participant_enabled']) || empty($event['checkin_enabled'])) err('このイベントでは受付機能が有効になっていません。', 403);
    $userId = signedQrUserId($token);
    if ($userId <= 0) err('QRコードが期限切れです。参加者にもう一度表示してもらってください。', 410);
    $user = checkinUserData($userId);
    $joinSt = db()->prepare("SELECT id, checked_in_at, registered_on_site FROM buddies_event_participants WHERE event_id=? AND user_id=? AND kind='join' LIMIT 1");
    $joinSt->execute([$eventId, $userId]);
    $join = $joinSt->fetch();
    if (!$join && !$allowRegister) {
        ok([
            'requires_registration' => true,
            'message' => '参加登録していないユーザーです。',
            'user' => $user,
        ]);
    }
    $wasRegistered = !!$join;
    if (!$join) {
        db()->prepare("INSERT INTO buddies_event_participants (event_id, user_id, kind, checked_in_at, checked_in_by_account_id, registered_on_site) VALUES (?,?,'join',NOW(),?,1)")
           ->execute([$eventId, $userId, (int)$a['id']]);
    } else {
        db()->prepare("UPDATE buddies_event_participants SET checked_in_at=COALESCE(checked_in_at,NOW()), checked_in_by_account_id=? WHERE id=?")
           ->execute([(int)$a['id'], (int)$join['id']]);
    }
    $fresh = db()->prepare("SELECT checked_in_at, registered_on_site FROM buddies_event_participants WHERE event_id=? AND user_id=? AND kind='join' LIMIT 1");
    $fresh->execute([$eventId, $userId]);
    $status = $fresh->fetch();
    ok([
        'checked_in' => true,
        'already_checked_in' => $wasRegistered && !empty($join['checked_in_at']),
        'registered_on_site' => !empty($status['registered_on_site']),
        'checked_in_at' => $status['checked_in_at'],
        'user' => $user,
    ]);
}
function actionEventCheckinManage(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $b = body();
    $eventId = (int)($b['event_id'] ?? 0);
    $userId = (int)($b['user_id'] ?? 0);
    $operation = trim((string)($b['operation'] ?? ''));
    if ($eventId <= 0 || $userId <= 0) err('イベントと参加者を指定してください。');
    if (!in_array($operation, ['checkin', 'undo_checkin', 'remove_join'], true)) err('操作が不正です。');
    $eventSt = db()->prepare("SELECT id FROM buddies_events WHERE id=? AND account_id=? AND status='active' LIMIT 1");
    $eventSt->execute([$eventId, (int)$a['id']]);
    if (!$eventSt->fetch()) err('イベントが見つからないか、受付権限がありません。', 404);
    $joinSt = db()->prepare("SELECT id, checked_in_at FROM buddies_event_participants WHERE event_id=? AND user_id=? AND kind='join' LIMIT 1");
    $joinSt->execute([$eventId, $userId]);
    $join = $joinSt->fetch();
    if (!$join) err('参加登録が見つかりません。', 404);
    if ($operation === 'checkin') {
        db()->prepare("UPDATE buddies_event_participants SET checked_in_at=COALESCE(checked_in_at,NOW()), checked_in_by_account_id=? WHERE id=?")
           ->execute([(int)$a['id'], (int)$join['id']]);
        ok(['operation' => $operation, 'user' => checkinUserData($userId)]);
    }
    if ($operation === 'undo_checkin') {
        db()->prepare("UPDATE buddies_event_participants SET checked_in_at=NULL, checked_in_by_account_id=NULL WHERE id=?")
           ->execute([(int)$join['id']]);
        ok(['operation' => $operation, 'user' => checkinUserData($userId)]);
    }
    db()->prepare("DELETE FROM buddies_event_participants WHERE event_id=? AND user_id=? AND kind='join'")
       ->execute([$eventId, $userId]);
    db()->prepare("DELETE sp FROM buddies_subevent_participants sp
                   JOIN buddies_subevents s ON s.id=sp.subevent_id
                   WHERE s.event_id=? AND sp.user_id=?")
       ->execute([$eventId, $userId]);
    ok(['operation' => $operation, 'user' => checkinUserData($userId)]);
}
function ownSubeventForCheckin(int $subeventId, array $account): array {
    $st = db()->prepare(
        "SELECT s.*, e.account_id, e.title AS event_title, e.participant_enabled AS event_participant_enabled, e.checkin_enabled AS event_checkin_enabled
           FROM buddies_subevents s
           JOIN buddies_events e ON e.id = s.event_id
          WHERE s.id=? AND s.status='active' AND e.status='active'
          LIMIT 1"
    );
    $st->execute([$subeventId]);
    $s = $st->fetch();
    if (!$s || (int)$s['account_id'] !== (int)$account['id']) err('サブイベントが見つからないか、受付権限がありません。', 404);
    if (empty($s['participant_enabled'])) err('このサブイベントでは参加登録が有効になっていません。', 403);
    if (empty($s['event_checkin_enabled'])) err('このイベントでは受付機能が有効になっていません。', 403);
    return $s;
}
function subeventCheckinRow(int $subeventId, int $userId): ?array {
    $st = db()->prepare("SELECT id, checked_in_at, registered_on_site FROM buddies_subevent_participants WHERE subevent_id=? AND user_id=? LIMIT 1");
    $st->execute([$subeventId, $userId]);
    $row = $st->fetch();
    return $row ?: null;
}
function subeventMainStatus(int $eventId, int $userId): array {
    $st = db()->prepare("SELECT checked_in_at FROM buddies_event_participants WHERE event_id=? AND user_id=? AND kind='join' LIMIT 1");
    $st->execute([$eventId, $userId]);
    $row = $st->fetch();
    return [
        'main_joined' => (bool)$row,
        'main_checked_in' => $row && !empty($row['checked_in_at']),
    ];
}
function actionSubeventCheckinScan(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $b = body();
    $subeventId = (int)($b['subevent_id'] ?? $b['target_id'] ?? 0);
    $token = trim((string)($b['token'] ?? ''));
    $allowRegister = truthyFlag($b['allow_register'] ?? 0);
    if ($subeventId <= 0 || $token === '') err('サブイベントとQRコードが必要です。');
    $subevent = ownSubeventForCheckin($subeventId, $a);
    $userId = signedQrUserId($token);
    if ($userId <= 0) err('QRコードが期限切れです。参加者にもう一度表示してもらってください。', 410);
    $user = checkinUserData($userId);
    $main = subeventMainStatus((int)$subevent['event_id'], $userId);
    $join = subeventCheckinRow($subeventId, $userId);
    if (!$join && !$allowRegister) {
        ok([
            'requires_registration' => true,
            'message' => 'サブイベントに参加登録していないユーザーです。',
            'user' => $user,
            'main_joined' => $main['main_joined'],
            'main_checked_in' => $main['main_checked_in'],
        ]);
    }
    $wasRegistered = !!$join;
    if (!$join) {
        db()->prepare("INSERT INTO buddies_subevent_participants (subevent_id, user_id, checked_in_at, checked_in_by_account_id, registered_on_site) VALUES (?,?,NOW(),?,1)")
           ->execute([$subeventId, $userId, (int)$a['id']]);
    } else {
        db()->prepare("UPDATE buddies_subevent_participants SET checked_in_at=COALESCE(checked_in_at,NOW()), checked_in_by_account_id=? WHERE id=?")
           ->execute([(int)$a['id'], (int)$join['id']]);
    }
    $fresh = subeventCheckinRow($subeventId, $userId);
    ok([
        'checked_in' => true,
        'already_checked_in' => $wasRegistered && !empty($join['checked_in_at']),
        'registered_on_site' => !empty($fresh['registered_on_site']),
        'checked_in_at' => $fresh['checked_in_at'] ?? null,
        'user' => $user,
        'main_joined' => $main['main_joined'],
        'main_checked_in' => $main['main_checked_in'],
    ]);
}
function actionSubeventCheckinManage(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $b = body();
    $subeventId = (int)($b['subevent_id'] ?? $b['target_id'] ?? 0);
    $userId = (int)($b['user_id'] ?? 0);
    $operation = trim((string)($b['operation'] ?? ''));
    if ($subeventId <= 0 || $userId <= 0) err('サブイベントと参加者を指定してください。');
    if (!in_array($operation, ['checkin', 'undo_checkin', 'remove_join'], true)) err('操作が不正です。');
    $subevent = ownSubeventForCheckin($subeventId, $a);
    $join = subeventCheckinRow($subeventId, $userId);
    if (!$join) err('サブイベント参加登録が見つかりません。', 404);
    if ($operation === 'checkin') {
        db()->prepare("UPDATE buddies_subevent_participants SET checked_in_at=COALESCE(checked_in_at,NOW()), checked_in_by_account_id=? WHERE id=?")
           ->execute([(int)$a['id'], (int)$join['id']]);
        ok(['operation' => $operation, 'user' => checkinUserData($userId), ...subeventMainStatus((int)$subevent['event_id'], $userId)]);
    }
    if ($operation === 'undo_checkin') {
        db()->prepare("UPDATE buddies_subevent_participants SET checked_in_at=NULL, checked_in_by_account_id=NULL WHERE id=?")
           ->execute([(int)$join['id']]);
        ok(['operation' => $operation, 'user' => checkinUserData($userId), ...subeventMainStatus((int)$subevent['event_id'], $userId)]);
    }
    db()->prepare("DELETE FROM buddies_subevent_participants WHERE subevent_id=? AND user_id=?")
       ->execute([$subeventId, $userId]);
    ok(['operation' => $operation, 'user' => checkinUserData($userId), ...subeventMainStatus((int)$subevent['event_id'], $userId)]);
}

function subeventEditableFields(array $b): array {
    $title = trim((string)($b['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 160) err('サブイベント名は1〜160文字で入力してください。');
    $description = trim((string)($b['description'] ?? ''));
    if (mb_strlen($description) > 4000) err('説明は4000文字以内で入力してください。');
    $venue = trim((string)($b['venue'] ?? ''));
    if (mb_strlen($venue) > 200) err('会場は200文字以内で入力してください。');
    $startsAt = trim((string)($b['starts_at'] ?? ''));
    $endsAt   = trim((string)($b['ends_at'] ?? ''));
    foreach (['startsAt' => &$startsAt, 'endsAt' => &$endsAt] as $k => &$v) {
        if ($v === '') { $v = null; continue; }
        $ts = strtotime($v);
        if ($ts === false) err('日時の形式が正しくありません。');
        $v = date('Y-m-d H:i:s', $ts);
    }
    unset($v);
    $capacity = isset($b['capacity']) && $b['capacity'] !== '' ? (int)$b['capacity'] : null;
    if ($capacity !== null && ($capacity < 1 || $capacity > 100000)) err('定員は1〜100000で入力してください。');
    $externalUrl = cleanUrl($b['external_url'] ?? null);
    $externalLabel = trim((string)($b['external_label'] ?? ''));
    if (mb_strlen($externalLabel) > 80) err('リンク文言は80文字以内で入力してください。');
    if ($externalLabel === '') $externalLabel = null;
    $participantEnabled = truthyFlag($b['participant_enabled'] ?? 1);
    return [
        'title'          => $title,
        'description'    => $description !== '' ? $description : null,
        'venue'          => $venue !== '' ? $venue : null,
        'starts_at'      => $startsAt,
        'ends_at'        => $endsAt,
        'capacity'       => $participantEnabled ? $capacity : null,
        'external_url'   => $externalUrl,
        'external_label' => $externalLabel,
        'external_button_highlight' => truthyFlag($b['external_button_highlight'] ?? 0),
        'participant_enabled' => $participantEnabled,
        'sort_order'     => 0,
    ];
}
function buildSubeventData(array $s, ?int $viewerId = null): array {
    $id = (int)$s['id'];
    $eventId = (int)$s['event_id'];
    $participantEnabled = !isset($s['participant_enabled']) || !empty($s['participant_enabled']);
    $parentParticipantEnabled = true;
    $evSt = db()->prepare("SELECT participant_enabled FROM buddies_events WHERE id=? LIMIT 1");
    $evSt->execute([$eventId]);
    $evRow = $evSt->fetch();
    if ($evRow && isset($evRow['participant_enabled'])) $parentParticipantEnabled = !empty($evRow['participant_enabled']);
    $cntSt = db()->prepare("SELECT COUNT(*) c FROM buddies_subevent_participants WHERE subevent_id=?");
    $cntSt->execute([$id]);
    $joinCount = (int)$cntSt->fetch()['c'];
    $checkinSt = db()->prepare("SELECT COUNT(*) c FROM buddies_subevent_participants WHERE subevent_id=? AND checked_in_at IS NOT NULL");
    $checkinSt->execute([$id]);
    $checkinCount = (int)$checkinSt->fetch()['c'];
    $isJoined = false;
    $isCheckedIn = false;
    $mainJoined = false;
    $mainCheckedIn = false;
    $canJoin = false;
    if ($viewerId) {
        $mySt = db()->prepare("SELECT id, checked_in_at FROM buddies_subevent_participants WHERE subevent_id=? AND user_id=? LIMIT 1");
        $mySt->execute([$id, $viewerId]);
        $my = $mySt->fetch();
        $isJoined = (bool)$my;
        $isCheckedIn = $my && !empty($my['checked_in_at']);
        $main = subeventMainStatus($eventId, $viewerId);
        $mainJoined = $main['main_joined'];
        $mainCheckedIn = $main['main_checked_in'];

        if (!$participantEnabled) {
            $canJoin = false;
        } elseif (!$parentParticipantEnabled) {
            $canJoin = true;
        } else {
            $mainSt = db()->prepare("SELECT id FROM buddies_event_participants WHERE event_id=? AND user_id=? AND kind='join' LIMIT 1");
            $mainSt->execute([$eventId, $viewerId]);
            $canJoin = (bool)$mainSt->fetch();
        }
    }
    return [
        'id'             => $id,
        'event_id'       => $eventId,
        'title'          => $s['title'],
        'description'    => $s['description'],
        'venue'          => $s['venue'],
        'starts_at'      => $s['starts_at'],
        'ends_at'        => $s['ends_at'],
        'cover_url'      => $s['cover_url'] ?? null,
        'capacity'       => $s['capacity'] !== null ? (int)$s['capacity'] : null,
        'external_url'   => $s['external_url'],
        'external_label' => $s['external_label'],
        'external_button_highlight' => !empty($s['external_button_highlight']),
        'participant_enabled' => $participantEnabled,
        'parent_participant_enabled' => $parentParticipantEnabled,
        'sort_order'     => isset($s['sort_order']) ? (int)$s['sort_order'] : 0,
        'status'         => $s['status'],
        'join_count'     => $joinCount,
        'checkin_count'  => $checkinCount,
        'is_joined'      => $isJoined,
        'is_checked_in'  => $isCheckedIn,
        'main_joined'    => $mainJoined,
        'main_checked_in'=> $mainCheckedIn,
        'can_join'       => $canJoin,
    ];
}
function requireOwnEvent(int $eventId, array $account): array {
    $st = db()->prepare("SELECT * FROM buddies_events WHERE id=? AND status!='disabled' LIMIT 1");
    $st->execute([$eventId]);
    $e = $st->fetch();
    if (!$e || (int)$e['account_id'] !== (int)$account['id']) err('イベントの編集権限がありません。', 403);
    return $e;
}
function requireOwnSubevent(int $subeventId, array $account): array {
    $st = db()->prepare(
        "SELECT s.* FROM buddies_subevents s
         JOIN buddies_events e ON e.id = s.event_id
         WHERE s.id=? AND s.status!='disabled' AND e.status!='disabled'
         LIMIT 1"
    );
    $st->execute([$subeventId]);
    $s = $st->fetch();
    if (!$s) err('サブイベントが見つかりません。', 404);
    requireOwnEvent((int)$s['event_id'], $account);
    return $s;
}
function actionSubeventList(): void { ensureEventTables();
    $eventId = (int)($_GET['event_id'] ?? $_GET['id'] ?? 0);
    if ($eventId <= 0) err('event_id は必須です。');
    $evSt = db()->prepare("SELECT id FROM buddies_events WHERE id=? AND status='active' LIMIT 1");
    $evSt->execute([$eventId]);
    if (!$evSt->fetch()) err('イベントが見つかりません。', 404);
    $st = db()->prepare("SELECT * FROM buddies_subevents WHERE event_id=? AND status='active'
                         AND " . eventVisibleDateCondition() . "
                         ORDER BY (COALESCE(starts_at, ends_at) IS NULL), COALESCE(starts_at, ends_at) ASC, created_at ASC");
    $st->execute([$eventId]);
    $viewer = currentUser();
    $viewerId = $viewer ? (int)$viewer['id'] : null;
    ok(array_map(fn($s) => buildSubeventData($s, $viewerId), $st->fetchAll()));
}

function actionSubeventGet(): void { ensureEventTables();
    $id = (int)($_GET['id'] ?? $_GET['subevent_id'] ?? 0);
    if ($id <= 0) err('id は必須です。');
    $st = db()->prepare(
        "SELECT s.*, e.account_id, e.title AS event_title, e.visibility AS event_visibility, e.status AS event_status,
                e.starts_at AS event_starts_at, e.ends_at AS event_ends_at
           FROM buddies_subevents s
           JOIN buddies_events e ON e.id = s.event_id
          WHERE s.id=? AND s.status='active' AND e.status='active'
          LIMIT 1"
    );
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) err('サブイベントが見つかりません。', 404);
    if (!empty($s['ends_at']) && strtotime($s['ends_at']) < time()) err('サブイベントが見つかりません。', 404);
    if (!empty($s['event_ends_at']) && strtotime($s['event_ends_at']) < time()) err('イベントが見つかりません。', 404);

    $viewer = currentUser();
    $viewerId = $viewer ? (int)$viewer['id'] : null;
    $data = buildSubeventData($s, $viewerId);
    $data['account_id'] = (int)$s['account_id'];
    $data['event_title'] = $s['event_title'];
    $data['event_visibility'] = $s['event_visibility'] ?? 'public';
    $data['event_starts_at'] = $s['event_starts_at'];
    $data['event_ends_at'] = $s['event_ends_at'];
    ok($data);
}
function actionSubeventMineList(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $eventId = (int)($_GET['event_id'] ?? 0);
    if ($eventId <= 0) err('event_id は必須です。');
    requireOwnEvent($eventId, $a);
    $st = db()->prepare("SELECT * FROM buddies_subevents WHERE event_id=? AND status!='disabled'
                         ORDER BY (COALESCE(starts_at, ends_at) IS NULL), COALESCE(starts_at, ends_at) ASC, created_at ASC");
    $st->execute([$eventId]);
    ok(array_map(fn($s) => buildSubeventData($s), $st->fetchAll()));
}
function actionSubeventCreate(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $b = body();
    $eventId = (int)($b['event_id'] ?? 0);
    if ($eventId <= 0) err('event_id は必須です。');
    requireOwnEvent($eventId, $a);
    $f = subeventEditableFields($b);
    db()->prepare("INSERT INTO buddies_subevents
        (event_id, title, description, venue, starts_at, ends_at, capacity, external_url, external_label, external_button_highlight, participant_enabled, sort_order)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$eventId, $f['title'], $f['description'], $f['venue'], $f['starts_at'], $f['ends_at'],
                  $f['capacity'], $f['external_url'], $f['external_label'], $f['external_button_highlight'],
                  $f['participant_enabled'], $f['sort_order']]);
    $id = (int)db()->lastInsertId();
    $st = db()->prepare("SELECT * FROM buddies_subevents WHERE id=? LIMIT 1");
    $st->execute([$id]);
    ok(buildSubeventData($st->fetch()));
}
function actionSubeventUpdate(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    if ($id <= 0) err('id は必須です。');
    $s = requireOwnSubevent($id, $a);
    $f = subeventEditableFields($b + ['event_id' => (int)$s['event_id']]);
    db()->prepare("UPDATE buddies_subevents SET
        title=?, description=?, venue=?, starts_at=?, ends_at=?, capacity=?,
        external_url=?, external_label=?, external_button_highlight=?, participant_enabled=?, sort_order=? WHERE id=?")
       ->execute([$f['title'], $f['description'], $f['venue'], $f['starts_at'], $f['ends_at'],
                  $f['capacity'], $f['external_url'], $f['external_label'], $f['external_button_highlight'],
                  $f['participant_enabled'], $f['sort_order'], $id]);
    if (!$f['participant_enabled']) {
        db()->prepare("DELETE FROM buddies_subevent_participants WHERE subevent_id=?")->execute([$id]);
    }
    $st = db()->prepare("SELECT * FROM buddies_subevents WHERE id=? LIMIT 1");
    $st->execute([$id]);
    ok(buildSubeventData($st->fetch()));
}
function actionSubeventDelete(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $b = body();
    $id = (int)($b['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) err('id は必須です。');
    requireOwnSubevent($id, $a);
    db()->prepare("DELETE FROM buddies_subevent_participants WHERE subevent_id=?")->execute([$id]);
    db()->prepare("UPDATE buddies_subevents SET status='disabled' WHERE id=?")->execute([$id]);
    ok();
}

function actionSubeventUploadCover(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    if ($id <= 0) err('id は必須です。');
    $s = requireOwnSubevent($id, $a);
    $dataUrl = (string)($b['image'] ?? '');
    if (!preg_match('/^data:image\/(png|jpeg|webp);base64,/', $dataUrl, $m)) err('PNG/JPEG/WebP画像を指定してください。');
    $raw = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
    if ($raw === false || !@getimagesizefromstring($raw)) err('有効な画像ファイルではありません。');
    if (strlen($raw) > 5 * 1024 * 1024) err('画像サイズが大きすぎます。');
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];

    $eventId = (int)$s['event_id'];
    $eventSt = db()->prepare("SELECT account_id FROM buddies_events WHERE id=? LIMIT 1");
    $eventSt->execute([$eventId]);
    $event = $eventSt->fetch();
    $accountId = $event ? (int)$event['account_id'] : (int)$a['id'];

    $dir = __DIR__ . '/uploads/verified/' . $accountId . '/subevents';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) err('アップロード先を作成できません。', 500);
    foreach (['png','jpg','webp'] as $oldExt) {
        $old = $dir . '/' . $id . '.' . $oldExt;
        if (is_file($old)) @unlink($old);
    }
    $file = $dir . '/' . $id . '.' . $ext;
    if (file_put_contents($file, $raw) === false) err('画像を保存できませんでした。', 500);
    $rel = 'uploads/verified/' . $accountId . '/subevents/' . $id . '.' . $ext;
    db()->prepare("UPDATE buddies_subevents SET cover_url=? WHERE id=?")->execute([$rel, $id]);

    $st = db()->prepare("SELECT * FROM buddies_subevents WHERE id=? LIMIT 1");
    $st->execute([$id]);
    ok(buildSubeventData($st->fetch()));
}
function actionSubeventJoin(): void { ensureEventTables();
    $u = requireAuth();
    $b = body();
    $id = (int)($b['subevent_id'] ?? $b['id'] ?? 0);
    $state = (string)($b['state'] ?? 'on');
    if ($id <= 0) err('subevent_id は必須です。');
    if (!in_array($state, ['on','off'], true)) err('state が不正です。');
    $st = db()->prepare(
        "SELECT s.*, e.status AS event_status, e.participant_enabled AS event_participant_enabled FROM buddies_subevents s
         JOIN buddies_events e ON e.id = s.event_id
         WHERE s.id=? AND s.status='active' AND e.status='active'
         LIMIT 1"
    );
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) err('サブイベントが見つかりません。', 404);
    if (!empty($s['ends_at']) && strtotime($s['ends_at']) < time()) err('サブイベントが終了しています。', 410);
    if (isset($s['participant_enabled']) && empty($s['participant_enabled'])) err('このサブイベントは参加登録を受け付けていません。', 403);
    $uid = (int)$u['id'];

    if ($state === 'off') {
        db()->prepare("DELETE FROM buddies_subevent_participants WHERE subevent_id=? AND user_id=?")
           ->execute([$id, $uid]);
    } else {
        if (!isset($s['event_participant_enabled']) || !empty($s['event_participant_enabled'])) {
            $mainJoinSt = db()->prepare("SELECT id FROM buddies_event_participants WHERE event_id=? AND user_id=? AND kind='join' LIMIT 1");
            $mainJoinSt->execute([(int)$s['event_id'], $uid]);
            if (!$mainJoinSt->fetch()) err('メインイベントに参加してからサブイベントに参加できます。', 403);
        }

        if ($s['capacity'] !== null) {
            $cntSt = db()->prepare("SELECT COUNT(*) c FROM buddies_subevent_participants WHERE subevent_id=?");
            $cntSt->execute([$id]);
            $cur = (int)$cntSt->fetch()['c'];
            $alreadySt = db()->prepare("SELECT id FROM buddies_subevent_participants WHERE subevent_id=? AND user_id=? LIMIT 1");
            $alreadySt->execute([$id, $uid]);
            if ($cur >= (int)$s['capacity'] && !$alreadySt->fetch()) err('参加枠が満員です。', 409);
        }
        db()->prepare("INSERT IGNORE INTO buddies_subevent_participants (subevent_id, user_id) VALUES (?,?)")
           ->execute([$id, $uid]);
    }
    $fresh = db()->prepare("SELECT * FROM buddies_subevents WHERE id=? LIMIT 1");
    $fresh->execute([$id]);
    ok(buildSubeventData($fresh->fetch(), $uid));
}
function actionSubeventParticipants(): void { ensureEventTables();
    $id = (int)($_GET['subevent_id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) err('subevent_id は必須です。');
    $st = db()->prepare(
        "SELECT u.id, u.display_name, u.username, u.user_icon, u.oshi_member, p.location, p.bio
           FROM buddies_subevent_participants sp
           JOIN sakulabo_users u ON u.id = sp.user_id
           LEFT JOIN buddies_profiles p ON p.user_id = u.id
          WHERE sp.subevent_id=?
          ORDER BY sp.created_at DESC"
    );
    $st->execute([$id]);
    $rows = $st->fetchAll();
    $data = array_map(fn($r) => [
        'id'           => (int)$r['id'],
        'display_name' => $r['display_name'] ?: $r['username'],
        'user_icon'    => $r['user_icon'] ?? null,
        'oshi_member'  => $r['oshi_member'] ?? null,
        'location'     => $r['location'] ?? null,
        'bio'          => $r['bio'] ?? null,
    ], $rows);
    ok($data);
}
function actionSubeventParticipantsAdmin(): void { ensureEventTables();
    $a = requireVerifiedAccount();
    $id = (int)($_GET['subevent_id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) err('subevent_id は必須です。');
    $own = db()->prepare(
        "SELECT s.id, s.title, s.event_id, e.account_id
           FROM buddies_subevents s
           JOIN buddies_events e ON e.id = s.event_id
          WHERE s.id=? AND s.status='active' AND e.status!='disabled'
          LIMIT 1"
    );
    $own->execute([$id]);
    $s = $own->fetch();
    if (!$s || (int)$s['account_id'] !== (int)$a['id']) err('閲覧権限がありません。', 403);
    $st = db()->prepare(
        "SELECT sp.created_at AS joined_at, sp.checked_in_at, sp.registered_on_site,
                ep.id AS main_join_id, ep.checked_in_at AS main_checked_in_at,
                u.id, u.username, u.display_name, u.user_icon, u.oshi_member, u.oshi_member_2, u.oshi_member_3,
                p.location, p.bio, p.tags, p.favorite_songs, p.sns_links
           FROM buddies_subevent_participants sp
           JOIN sakulabo_users u ON u.id = sp.user_id
           LEFT JOIN buddies_event_participants ep ON ep.event_id = ? AND ep.user_id = u.id AND ep.kind = 'join'
           LEFT JOIN buddies_profiles p ON p.user_id = u.id
          WHERE sp.subevent_id=?
          ORDER BY (sp.checked_in_at IS NOT NULL) ASC, sp.created_at DESC"
    );
    $st->execute([(int)$s['event_id'], $id]);
    ok([
        'subevent' => ['id'=>(int)$s['id'], 'event_id'=>(int)$s['event_id'], 'title'=>(string)$s['title']],
        'participants' => array_map(fn($r) => [
            'joined_at' => $r['joined_at'],
            'checked_in_at' => $r['checked_in_at'],
            'registered_on_site' => !empty($r['registered_on_site']),
            'main_joined' => !empty($r['main_join_id']),
            'main_checked_in' => !empty($r['main_checked_in_at']),
            'user' => [
                'id' => (int)$r['id'],
                'username' => (string)$r['username'],
                'display_name' => $r['display_name'] ?: $r['username'],
                'user_icon' => $r['user_icon'] ?? null,
                'oshi_members' => array_values(array_filter([$r['oshi_member'] ?? null, $r['oshi_member_2'] ?? null, $r['oshi_member_3'] ?? null])),
                'location' => $r['location'] ?? null,
                'bio' => $r['bio'] ?? null,
                'tags' => !empty($r['tags']) ? (json_decode($r['tags'], true) ?: []) : [],
                'favorite_songs' => !empty($r['favorite_songs']) ? (json_decode($r['favorite_songs'], true) ?: []) : [],
                'sns_links' => !empty($r['sns_links']) ? (json_decode($r['sns_links'], true) ?: []) : [],
            ],
        ], $st->fetchAll()),
    ]);
}

function actionEventMyStatus(): void { ensureEventTables();
    $u = currentUser();
    if (!$u) ok(['my_kinds' => [], 'is_joined' => false, 'is_liked' => false, 'is_checked_in' => false]);
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) err('id は必須です。');
    $st = db()->prepare("SELECT kind, checked_in_at FROM buddies_event_participants WHERE event_id=? AND user_id=?");
    $st->execute([$id, (int)$u['id']]);
    $rows = $st->fetchAll();
    $kinds = array_column($rows, 'kind');
    $checkedIn = count(array_filter($rows, fn($r) => $r['kind'] === 'join' && !empty($r['checked_in_at']))) > 0;
    ok(['my_kinds' => $kinds, 'is_joined' => in_array('join', $kinds, true), 'is_liked' => in_array('like', $kinds, true), 'is_checked_in' => $checkedIn]);
}

// ユーザー自身の参加中/いいね中のイベント一覧
function actionEventMyParticipations(): void { ensureEventTables();
    $u = requireAuth();
    $kind = (string)($_GET['kind'] ?? 'join');
    if (!in_array($kind, ['join','like'], true)) err('kind が不正です。');
    $st = db()->prepare(
        "SELECT e.* FROM buddies_event_participants ep
         JOIN buddies_events e ON e.id = ep.event_id
         WHERE ep.user_id=? AND ep.kind=? AND e.status='active'
         ORDER BY (e.starts_at IS NULL), e.starts_at ASC, e.created_at DESC"
    );
    $st->execute([(int)$u['id'], $kind]);
    ok(array_map(fn($e) => buildEventData($e, (int)$u['id']), $st->fetchAll()));
}

function ensureFormTables(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_forms (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(160) NOT NULL,
        description TEXT NULL DEFAULT NULL,
        questions TEXT NOT NULL,
        form_mode VARCHAR(16) NOT NULL DEFAULT 'form',
        allow_anonymous_vote TINYINT(1) NOT NULL DEFAULT 0,
        show_results TINYINT(1) NOT NULL DEFAULT 0,
        visibility VARCHAR(16) NOT NULL DEFAULT 'public',
        collect_profile TINYINT(1) NOT NULL DEFAULT 0,
        one_response TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_account (account_id, status),
        KEY idx_visibility (visibility, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS buddies_form_responses (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        form_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NULL DEFAULT NULL,
        answers TEXT NOT NULL,
        respondent_name VARCHAR(120) NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_form (form_id),
        KEY idx_form_user (form_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $cols = array_column($pdo->query('DESCRIBE buddies_forms')->fetchAll(), 'Field');
        if (!in_array('form_mode', $cols, true)) {
            $pdo->exec("ALTER TABLE buddies_forms ADD COLUMN form_mode VARCHAR(16) NOT NULL DEFAULT 'form' AFTER questions");
        }
        if (!in_array('allow_anonymous_vote', $cols, true)) {
            $pdo->exec("ALTER TABLE buddies_forms ADD COLUMN allow_anonymous_vote TINYINT(1) NOT NULL DEFAULT 0 AFTER form_mode");
        }
        if (!in_array('show_results', $cols, true)) {
            $pdo->exec("ALTER TABLE buddies_forms ADD COLUMN show_results TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_anonymous_vote");
        }
    } catch (\Throwable $e) {}
}

function normalizeFormQuestions($questions): array {
    if (!is_array($questions)) err('質問の形式が正しくありません。');
    $out = [];
    $answerable = 0;
    foreach (array_slice($questions, 0, 30) as $q) {
        if (!is_array($q)) continue;
        $label = trim((string)($q['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 160) continue;
        $type = (string)($q['type'] ?? 'text');
        if (!in_array($type, ['text','textarea','number','date','url','select','radio','checkbox','section'], true)) $type = 'text';
        $options = isset($q['options']) && is_array($q['options']) ? array_values(array_filter(array_map(fn($v) => mb_substr(trim((string)$v), 0, 80), $q['options']), fn($v) => $v !== '')) : [];
        if (in_array($type, ['select','radio','checkbox'], true) && !$options) $type = 'text';
        $help = mb_substr(trim((string)($q['help'] ?? '')), 0, 240);
        $placeholder = mb_substr(trim((string)($q['placeholder'] ?? '')), 0, 120);
        $isSection = $type === 'section';
        if (!$isSection) $answerable++;
        $out[] = [
            'id' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($q['id'] ?? '')) ?: bin2hex(random_bytes(4)),
            'label' => $label,
            'type' => $type,
            'required' => $isSection ? false : truthyFlag($q['required'] ?? 0),
            'options' => $isSection ? [] : array_slice($options, 0, 20),
            'help' => $help,
            'placeholder' => $isSection ? '' : $placeholder,
        ];
    }
    if (!$answerable) err('回答項目を1件以上入力してください。');
    return $out;
}
function formEditableFields(array $b): array {
    $title = trim((string)($b['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 160) err('タイトルは1〜160文字で入力してください。');
    $description = trim((string)($b['description'] ?? ''));
    if (mb_strlen($description) > 4000) err('説明は4000文字以内で入力してください。');
    $visibility = (string)($b['visibility'] ?? 'public');
    if (!in_array($visibility, ['public','unlisted'], true)) $visibility = 'public';
    $mode = (string)($b['form_mode'] ?? $b['mode'] ?? 'form');
    if (!in_array($mode, ['form','poll'], true)) $mode = 'form';
    $questions = normalizeFormQuestions($b['questions'] ?? []);
    if ($mode === 'poll') {
        $hasChoice = false;
        foreach ($questions as $q) {
            if (in_array($q['type'] ?? '', ['radio','select','checkbox'], true) && !empty($q['options'])) {
                $hasChoice = true;
                break;
            }
        }
        if (!$hasChoice) err('投票モードでは選択式の質問を1件以上設定してください。');
    }
    return [
        'title'=>$title,
        'description'=>$description !== '' ? $description : null,
        'questions'=>$questions,
        'form_mode'=>$mode,
        'allow_anonymous_vote'=>$mode === 'poll' ? truthyFlag($b['allow_anonymous_vote'] ?? 0) : false,
        'show_results'=>$mode === 'poll' ? truthyFlag($b['show_results'] ?? 0) : false,
        'visibility'=>$visibility,
        'collect_profile'=>$mode === 'poll' && truthyFlag($b['allow_anonymous_vote'] ?? 0) ? false : truthyFlag($b['collect_profile'] ?? 0),
        'one_response'=>truthyFlag($b['one_response'] ?? 1),
    ];
}
function buildFormData(array $f, bool $includePrivate = false): array {
    $data = ['id'=>(int)$f['id'], 'account_id'=>(int)$f['account_id'], 'title'=>(string)$f['title'], 'description'=>$f['description'], 'questions'=>json_decode((string)$f['questions'], true) ?: [], 'form_mode'=>(string)($f['form_mode'] ?? 'form'), 'allow_anonymous_vote'=>!empty($f['allow_anonymous_vote']), 'show_results'=>!empty($f['show_results']), 'visibility'=>(string)$f['visibility'], 'collect_profile'=>!empty($f['collect_profile']), 'one_response'=>!empty($f['one_response']), 'status'=>(string)$f['status'], 'created_at'=>$f['created_at']];
    if ($includePrivate) {
        $st = db()->prepare('SELECT COUNT(*) FROM buddies_form_responses WHERE form_id=?');
        $st->execute([(int)$f['id']]);
        $data['response_count'] = (int)$st->fetchColumn();
    }
    return $data;
}
function actionFormMineList(): void { ensureFormTables();
    $a = requireVerifiedAccount();
    $st = db()->prepare("SELECT * FROM buddies_forms WHERE account_id=? AND status!='disabled' ORDER BY created_at DESC");
    $st->execute([(int)$a['id']]);
    ok(array_map(fn($f) => buildFormData($f, true), $st->fetchAll()));
}
function actionFormListByAccount(): void { ensureFormTables();
    $accountId = (int)($_GET['account_id'] ?? 0);
    if ($accountId <= 0) err('account_id は必須です。');
    $viewer = currentVerifiedAccount();
    $isOwner = $viewer && (int)$viewer['id'] === $accountId;
    $where = $isOwner
        ? "account_id=? AND status='active'"
        : "account_id=? AND status='active' AND visibility='public'";
    $st = db()->prepare("SELECT * FROM buddies_forms WHERE {$where} ORDER BY created_at DESC");
    $st->execute([$accountId]);
    ok(array_map(fn($f) => buildFormData($f, $isOwner), $st->fetchAll()));
}
function actionFormCreate(): void { ensureFormTables();
    $a = requireVerifiedAccount(); $f = formEditableFields(body());
    db()->prepare("INSERT INTO buddies_forms (account_id,title,description,questions,form_mode,allow_anonymous_vote,show_results,visibility,collect_profile,one_response) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([(int)$a['id'], $f['title'], $f['description'], json_encode($f['questions'], JSON_UNESCAPED_UNICODE), $f['form_mode'], $f['allow_anonymous_vote'], $f['show_results'], $f['visibility'], $f['collect_profile'], $f['one_response']]);
    $id = (int)db()->lastInsertId(); $st = db()->prepare('SELECT * FROM buddies_forms WHERE id=?'); $st->execute([$id]); ok(buildFormData($st->fetch(), true));
}
function actionFormUpdate(): void { ensureFormTables();
    $a = requireVerifiedAccount(); $b = body(); $id = (int)($b['id'] ?? 0); if ($id <= 0) err('id は必須です。');
    $st = db()->prepare('SELECT * FROM buddies_forms WHERE id=? LIMIT 1'); $st->execute([$id]); $form = $st->fetch();
    if (!$form || (int)$form['account_id'] !== (int)$a['id']) err('編集権限がありません。', 403);
    $f = formEditableFields($b);
    db()->prepare("UPDATE buddies_forms SET title=?, description=?, questions=?, form_mode=?, allow_anonymous_vote=?, show_results=?, visibility=?, collect_profile=?, one_response=? WHERE id=?")->execute([$f['title'], $f['description'], json_encode($f['questions'], JSON_UNESCAPED_UNICODE), $f['form_mode'], $f['allow_anonymous_vote'], $f['show_results'], $f['visibility'], $f['collect_profile'], $f['one_response'], $id]);
    $st->execute([$id]); ok(buildFormData($st->fetch(), true));
}
function actionFormDelete(): void { ensureFormTables();
    $a = requireVerifiedAccount(); $id = (int)(body()['id'] ?? 0); if ($id <= 0) err('id は必須です。');
    $st = db()->prepare('SELECT * FROM buddies_forms WHERE id=? LIMIT 1'); $st->execute([$id]); $form = $st->fetch();
    if (!$form || (int)$form['account_id'] !== (int)$a['id']) err('削除権限がありません。', 403);
    db()->prepare("DELETE FROM buddies_form_responses WHERE form_id=?")->execute([$id]);
    db()->prepare("UPDATE buddies_forms SET status='disabled' WHERE id=?")->execute([$id]); ok();
}
function actionFormPublicGet(): void { ensureFormTables();
    $id = (int)($_GET['id'] ?? 0); if ($id <= 0) err('id は必須です。');
    $st = db()->prepare("SELECT * FROM buddies_forms WHERE id=? AND status='active' LIMIT 1"); $st->execute([$id]); $f = $st->fetch();
    if (!$f) err('フォームが見つかりません。', 404);
    $viewer = currentUser(); $data = buildFormData($f, false); $data['viewer'] = $viewer ? ['id'=>(int)$viewer['id'],'display_name'=>$viewer['display_name'] ?: $viewer['username'],'user_icon'=>$viewer['user_icon'] ?? null] : null; ok($data);
}
function actionFormSubmit(): void { ensureFormTables();
    $b = body(); $id = (int)($b['form_id'] ?? 0); if ($id <= 0) err('form_id は必須です。');
    $st = db()->prepare("SELECT * FROM buddies_forms WHERE id=? AND status='active' LIMIT 1"); $st->execute([$id]); $f = $st->fetch();
    if (!$f) err('フォームが見つかりません。', 404);
    $u = currentUser(); $uid = $u ? (int)$u['id'] : null;
    $isPoll = (string)($f['form_mode'] ?? 'form') === 'poll';
    $anonymousVote = $isPoll && !empty($f['allow_anonymous_vote']);
    if (!$u && !$anonymousVote) err('Buddies profileへのログインが必要です。', 401);
    if (!empty($f['one_response']) && $uid) { $dup = db()->prepare('SELECT id FROM buddies_form_responses WHERE form_id=? AND user_id=? LIMIT 1'); $dup->execute([$id, $uid]); if ($dup->fetch()) err('回答は1回までです。', 409); }
    $questions = json_decode((string)$f['questions'], true) ?: []; $answersIn = is_array($b['answers'] ?? null) ? $b['answers'] : []; $answers = [];
    foreach ($questions as $q) {
        if (($q['type'] ?? '') === 'section') continue;
        $qid = (string)$q['id']; $val = $answersIn[$qid] ?? null;
        if (!empty($q['required']) && ($val === null || $val === '' || $val === [])) err('必須項目が未入力です。');
        $type = (string)($q['type'] ?? 'text');
        $options = is_array($q['options'] ?? null) ? array_map('strval', $q['options']) : [];
        if ($type === 'checkbox') {
            $normalized = is_array($val) ? array_slice(array_map(fn($v) => mb_substr(trim((string)$v), 0, 500), $val), 0, 20) : [];
            if (array_diff($normalized, $options)) err('選択肢に不正な値が含まれています。');
            if (!empty($q['required']) && !$normalized) err('必須項目が未入力です。');
            $answers[$qid] = $normalized;
            continue;
        }
        $normalized = mb_substr(trim((string)($val ?? '')), 0, 2000);
        if (in_array($type, ['radio', 'select'], true) && $normalized !== '' && !in_array($normalized, $options, true)) err('選択肢に不正な値が含まれています。');
        if ($normalized !== '' && $type === 'url' && !cleanUrl($normalized)) err('URLの形式が正しくありません。');
        if ($normalized !== '' && $type === 'number' && !is_numeric($normalized)) err('数値項目の形式が正しくありません。');
        if ($normalized !== '' && $type === 'date') {
            $date = DateTime::createFromFormat('Y-m-d', $normalized);
            if (!$date || $date->format('Y-m-d') !== $normalized) err('日付の形式が正しくありません。');
        }
        $answers[$qid] = $normalized;
    }
    db()->prepare("INSERT INTO buddies_form_responses (form_id,user_id,answers,respondent_name) VALUES (?,?,?,?)")->execute([$id, $uid, json_encode($answers, JSON_UNESCAPED_UNICODE), $u && !empty($f['collect_profile']) ? ($u['display_name'] ?: $u['username']) : null]);
    ok(['submitted'=>true]);
}
function buildFormResultData(array $f): array {
    $questions = json_decode((string)$f['questions'], true) ?: [];
    $choiceQuestions = array_values(array_filter($questions, fn($q) => in_array($q['type'] ?? '', ['radio','select','checkbox'], true) && !empty($q['options'])));
    $r = db()->prepare("SELECT answers FROM buddies_form_responses WHERE form_id=?");
    $r->execute([(int)$f['id']]);
    $results = [];
    foreach ($choiceQuestions as $q) {
        $counts = [];
        foreach ($q['options'] as $option) $counts[(string)$option] = 0;
        $results[(string)$q['id']] = ['id'=>(string)$q['id'], 'label'=>(string)$q['label'], 'type'=>(string)$q['type'], 'total'=>0, 'options'=>array_map(fn($option) => ['label'=>(string)$option, 'count'=>0], $q['options'])];
    }
    foreach ($r->fetchAll() as $row) {
        $answers = json_decode((string)$row['answers'], true) ?: [];
        foreach ($choiceQuestions as $q) {
            $qid = (string)$q['id'];
            if (!array_key_exists($qid, $answers)) continue;
            $values = is_array($answers[$qid]) ? $answers[$qid] : [$answers[$qid]];
            $seen = [];
            foreach ($values as $value) {
                $value = (string)$value;
                if ($value === '' || isset($seen[$value])) continue;
                $seen[$value] = true;
                foreach ($results[$qid]['options'] as &$option) {
                    if ($option['label'] === $value) {
                        $option['count']++;
                        $results[$qid]['total']++;
                        break;
                    }
                }
                unset($option);
            }
        }
    }
    return ['form'=>buildFormData($f, false), 'results'=>array_values($results)];
}
function actionFormResults(): void { ensureFormTables();
    $id = (int)($_GET['id'] ?? 0); if ($id <= 0) err('id は必須です。');
    $st = db()->prepare("SELECT * FROM buddies_forms WHERE id=? AND status='active' LIMIT 1"); $st->execute([$id]); $f = $st->fetch();
    if (!$f) err('フォームが見つかりません。', 404);
    $viewer = currentVerifiedAccount();
    $isOwner = $viewer && (int)$viewer['id'] === (int)$f['account_id'];
    if ((string)($f['form_mode'] ?? 'form') !== 'poll') err('投票結果は投票モードのフォームでのみ利用できます。', 400);
    if (!$isOwner && empty($f['show_results'])) err('この投票結果は公開されていません。', 403);
    ok(buildFormResultData($f));
}
function actionFormResponses(): void { ensureFormTables();
    $a = requireVerifiedAccount(); $id = (int)($_GET['id'] ?? 0); if ($id <= 0) err('id は必須です。');
    $st = db()->prepare('SELECT * FROM buddies_forms WHERE id=? LIMIT 1'); $st->execute([$id]); $f = $st->fetch();
    if (!$f || (int)$f['account_id'] !== (int)$a['id']) err('閲覧権限がありません。', 403);
    $r = db()->prepare("SELECT fr.*, u.username, u.display_name, u.user_icon, u.oshi_member, p.location FROM buddies_form_responses fr LEFT JOIN sakulabo_users u ON u.id = fr.user_id LEFT JOIN buddies_profiles p ON p.user_id = fr.user_id WHERE fr.form_id=? ORDER BY fr.created_at DESC");
    $r->execute([$id]);
    $collectProfile = !empty($f['collect_profile']);
    ok(['form'=>buildFormData($f, true), 'responses'=>array_map(fn($row) => ['id'=>(int)$row['id'], 'user_id'=>$collectProfile && $row['user_id'] !== null ? (int)$row['user_id'] : null, 'respondent_name'=>$collectProfile ? ($row['respondent_name'] ?: ($row['display_name'] ?: $row['username'])) : '匿名', 'user_icon'=>$collectProfile ? ($row['user_icon'] ?? null) : null, 'oshi_member'=>$collectProfile ? ($row['oshi_member'] ?? null) : null, 'location'=>$collectProfile ? ($row['location'] ?? null) : null, 'answers'=>json_decode((string)$row['answers'], true) ?: [], 'created_at'=>$row['created_at']], $r->fetchAll())]);
}
