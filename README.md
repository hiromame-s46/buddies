# Buddies profile

櫻坂46ファン同士がプロフィール、NEXT LIVE、参加履歴、コミュニティイベントを通じてつながるためのWebアプリです。

This is a lightweight community profile and event-management app for Sakurazaka46 fans.

> 非公式ファンプロジェクトです。櫻坂46公式、Seed & Flower、Sony Music、各会場とは関係ありません。

## Features / 主な機能

- 一般ユーザー向けプロフィール作成、推しメン、好きな曲、SNSリンク、QR交換
- NEXT LIVE と過去参加ライブの表示
- Buddies search、共通点ベースの検索・フィルタ
- コミュニティアカウント、共同運営者招待、イベント管理
- イベント/サブイベント参加、QR受付、受付済みリスト同期
- フォーム、投票モード、回答一覧、公開結果表示
- コミュニティ掲示板、ピン留め投稿、添付ファイル対応
- Xシェアカード、公開プロフィール、verifiedプロフィール

## Repository Layout

```text
api.php                 Main JSON API and DB migrations
index.html              User app shell
view.html               Public profile page
live.html               NEXT LIVE page
history.html            Public history page
verified/               Community account, events, forms, check-in
status/                 Public status and release pages
help/                   Help center pages
icon/                   Favicon and PWA icons
uploads/                User generated files, ignored by Git
```

## Requirements

- PHP 8.1+ recommended
- MySQL/MariaDB with PDO MySQL
- HTTPS in production
- A private config file outside this repository:

```php
<?php
return [
    'host' => 'localhost',
    'dbname' => 'database_name',
    'username' => 'database_user',
    'password' => 'database_password',
];
```

`api.php` currently loads this file from `../../../api/config.php`. Keep credentials outside the public web root and outside Git.

## Local Development

```bash
php -S 127.0.0.1:8000 -t ..
```

Then open:

```text
http://127.0.0.1:8000/buddies/
```

Database migrations are created automatically by `api.php` when the API is first accessed.

## Environment Variables

Use environment variables for operational secrets and admin-only tools:

```bash
BUDDIES_ALLOWED_ORIGINS="https://buddies46.stars.ne.jp"
BUDDIES_STATUS_ADMIN_PASSWORD="change-me"
BUDDIES_HELP_ADMIN_PASSWORD="change-me"
```

Do not commit production values. Use server-level environment configuration or a private deployment secret store.

## Security Notes

- Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` on HTTPS.
- API CORS allows same-origin and configured origins only; wildcard credentialed CORS is intentionally avoided.
- Admin utility passwords are read from environment variables. If they are not set, login is disabled.
- Uploaded user files are stored under `uploads/`, which is excluded from Git.
- Keep `config.php`, `.env`, private keys, DB dumps, logs, and backups out of the repository.
- Report security issues privately. See [SECURITY.md](SECURITY.md).

## GitHub / Open Source Hygiene

- `.gitignore` excludes uploads, local schema markers, secrets, logs, caches, and dependency directories.
- The security workflow checks PHP syntax and scans changed source for common committed-secret patterns.
- Do not open public issues containing credentials, tokens, private URLs, or user personal data.
- Before publishing a release, check:
  - `php -l api.php`
  - HTML inline script syntax
  - `git diff --check`
  - No generated uploads or local secrets are staged

## Release Process

1. Update [CHANGELOG.md](CHANGELOG.md).
2. Commit the release changes.
3. Create an annotated tag, for example:

```bash
git tag -a v2.1.0 -m "Release v2.1.0"
git push origin main --tags
```

## License / Usage

This repository contains application code for a fan project. Before reusing it publicly, review third-party assets, icons, venue/member data, and service-specific terms.

---

# 日本語メモ

本番運用時は、DB設定ファイル・環境変数・アップロードファイルを必ずGit管理外に置いてください。

コミュニティ向け機能は、イベント受付やフォーム回答などユーザー情報を扱うため、最小限の取得・明示的な公開設定・管理者権限の分離を前提に設計します。
