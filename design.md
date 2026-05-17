# Buddies — デザインガイド

Buddies（櫻坂46ファン向けコミュニティマッチングプラットフォーム）のビジュアル／UI 設計ドキュメント。実装は単一の [index.html](index.html) に内包された CSS / JS を中心に、[account/index.html](account/index.html) と [form/index.html](form/index.html) でも同じ設計言語を踏襲する。

---

## 1. デザインの基本方針

| 観点 | 方針 |
| --- | --- |
| トーン | クリーン・モノクロベース・余白広め。櫻坂のサクラピンクは差し色として最小限に。 |
| 形状 | 丸み（pill / 円 / 大きな border-radius）を強く打ち出した、ソフトで親しみやすい印象。 |
| 階層 | フラットなカード積み重ね。影は極めて弱く（`box-shadow:0 2px 8px rgba(0,0,0,0.03)`）、線で領域を区切る。 |
| 入力 | "pill input" を全体の基調に。タブ・ボタン・タグ・チップも丸ピル系で統一感を出す。 |
| 動き | 短い時定数（`.15s〜.4s`）+ cubic-bezier の弾性で「タップした手応え」を作る。 |
| 想定環境 | モバイルファースト（iOS Safari / Android Chrome）。デスクトップは中央寄せ最大幅で対応。 |

---

## 2. カラーパレット

### 2.1 CSSカスタムプロパティ

[index.html:35](index.html#L35) と [account/index.html:9](account/index.html#L9) で定義されているデザイントークン。

```css
:root {
  --black:  #000;     /* テキスト・主要アクション・アクティブ状態 */
  --gray:   #666;     /* セカンダリテキスト */
  --light:  #f5f5f5;  /* 薄いサーフェス（バブル背景など） */
  --border: #ddd;     /* 標準ボーダー */
  --eee:    #eee;     /* 区切り線・カードボーダー */
  --danger: #e53e3e;  /* エラー・ログアウト・破壊的操作（account側） */
  --success:#38a169;  /* 成功メッセージ（account側） */
}
```

### 2.2 ベースカラー

| 役割 | カラー | 用途 |
| --- | --- | --- |
| 背景 | `#f7f7f7` | `body` 全体の背景。コンテナ（`#fff`）との明度差で奥行きを表現 |
| サーフェス | `#fff` | カード・モーダル・入力欄 |
| サーフェス2 | `#fafafa` `#f5f5f5` `#f2f2f2` | バイオ／曲名バブル、サジェスト hover、薄いボタン |
| プライマリ | `#000` | CTA、アクティブタブ、選択中のチップ、推しメンピル |
| テキスト | `#111` / `#333` / `#666` / `#999` / `#bbb` | 5 段階で情報の優先度を表現 |
| ボーダー | `#eee` / `#e5e7eb` / `#ddd` / `#d1d5db` | 線の濃さは要素の重要度に比例 |

### 2.3 アクセントカラー

ブランドカラーは黒・白を基調にしつつ、特定の意味だけを色で示す。

| カラー | コード | 使い所 |
| --- | --- | --- |
| サクラピンク | `#ffb7c5` | `.pill-button.pink` ／ アバターの推し1番カラー |
| バイオレット | `#8b5cf6` | 推しカラーパレット |
| マゼンタ | `#ec4899` | 推しカラーパレット |
| オレンジ | `#f97316` `#f59e0b` | 推しカラーパレット／管理者バッジ |
| グリーン | `#22c55e` | 交換済みバッジ・成功チェック |
| ブルー | `#3b82f6` | SNS 登録済みバッジ・推しカラーパレット |
| ライムイエロー | `#eab308` | 推しカラーパレット |

`oshiColors` 配列（[index.html:1107](index.html#L1107)）で 7 色を循環使用し、第一推しメンの名前ハッシュからアバター背景色が決まる。

### 2.4 ステータスカラー（拡張）

| ステータス | 配色 |
| --- | --- |
| エラー | 背景 `#fff5f5` / 文字 `#e53e3e` / 枠 `#fed7d7` |
| 成功 | 背景 `#f0fff4` / 文字 `#38a169` / 枠 `#c6f6d5` |
| 閲覧モードバナー | 背景 `#fff8e1` / 文字 `#5d4037` / 枠 `#ffecb3` |
| stance（無言フォローOK） | 背景 `#eef7ff` / 文字 `#0c63a8` / 枠 `#cce4f7` |
| stance（一言ほしい） | 背景 `#fff3e0` / 文字 `#a75a0c` / 枠 `#ffd9a8` |

---

## 3. タイポグラフィ

### 3.1 フォントファミリー

```
-apple-system, BlinkMacSystemFont, "Segoe UI",
"Hiragino Kaku Gothic ProN", "Yu Gothic", Meiryo, sans-serif
```

OS 標準の日本語＋ラテン系を優先し、フォント読み込みを行わない（パフォーマンス重視）。

### 3.2 サイズ／ウェイトスケール

| ロール | サイズ | ウェイト | 例 |
| --- | --- | --- | --- |
| ロゴ | 20–28px（レスポンシブ） | 800 | ヘッダー "Buddies" |
| H1（モーダルタイトル） | 22px | 800 | 認証モーダル `.auth-modal-title` |
| H2（プロフィール名） | 18–24px | 800 | `.profile-name` |
| セクション見出し | 15–18px | 700 | `h2.section-title` |
| 本文 | 14–15px | 400–500 | `.profile-bio` |
| キャプション | 12–13px | 500–600 | `.profile-meta` |
| ラベル | 11–12px | 700 + `letter-spacing:0.5px` + UPPERCASE | `.profile-section-title` |
| マイクロ | 10–11px | 600–800 | `.match-reason` `.filter-trigger-badge` |

* `line-height` は基本 `1.5–1.6`、見出しは `1.2` でタイトに。
* 見出しには `letter-spacing:-0.3px〜-0.5px` を入れて締まりを出す。
* iOS の自動ズーム回避のため、入力欄のモバイル時 `font-size` は `16px` を確保（[index.html:62](index.html#L62)）。

---

## 4. スペーシング

8px グリッドを目安に、よく使う値が決まっている。

| 用途 | 値 |
| --- | --- |
| 要素間の小さなギャップ | `4` `6` `8` |
| カード内パディング | `16` `18` `20` `24` |
| セクション縦間隔 | `14` `20` `28` `32` |
| コンテナ左右パディング | `12`（≤500px） / `16` / `20` / `40`（≥769px） |
| 下部ナビ用安全マージン | `padding-bottom: 110px`（フローティングタブバー分） |

---

## 5. コンポーネント定義

### 5.1 サーフェス

#### Card
```css
background:#fff;
border:1px solid var(--eee);
border-radius:24px;        /* デスクトップは 28px */
padding:20px;              /* モバイルは 16px */
box-shadow:0 2px 8px rgba(0,0,0,0.03);
```

#### Bubble（バイオ・楽曲名）
背景 `#f5f5f5` + `border-radius:30px / 9999px`、ボーダーなし。テキスト主体のソフトな塊。

#### Bottom Sheet（モーダル基本形）
- モバイル: 画面下部から `translateY(100%)→0` でスライドイン。
- ヘッダーに 40×4px のグレーハンドル（`.filters-modal-handle`）。
- デスクトップ（≥501px / ≥769px）: センター配置の `border-radius:24px / 28px` に切り替え。
- イージング: `cubic-bezier(0.4, 0, 0.2, 1)` または `cubic-bezier(0.32, 0.72, 0, 1)`。

### 5.2 入力

#### Pill Input
```css
padding:14px 20px;
border:1px solid var(--border);
border-radius:9999px;
font-size:14px;       /* モバイルは 16px */
```
- フォーカス時のみ枠が `#000` に変化。
- `textarea.pill-input` は `border-radius:20px` で角を残す。
- デスクトップは `border-radius:12px / 16px` の角丸長方形に切り替え（[index.html:106-107](index.html#L106-L107)）。

#### Search Input
左端 18px に虫メガネ SVG、右端に円形 `.search-clear-btn`（`#ccc`→`#999`）。`padding-left:44px; padding-right:44px;`。

### 5.3 ボタン

#### Pill Button（基本）
```css
padding:10px 16px;
border-radius:9999px;
font-size:13px; font-weight:500;
border:1px solid var(--border);
background:#fff;
min-height:40px;
```
タップ時 `transform:scale(0.98)`、フォーカスは `outline:2px solid #000;outline-offset:2px`。

| バリアント | 背景 | 用途 |
| --- | --- | --- |
| `.black` | `#000` 文字白 | プライマリ CTA |
| `.pink` | `#ffb7c5` 文字黒 | サクラ系の補助アクション |
| `.gray` | `#f2f2f2` | サブアクション |
| `:disabled` | `opacity:.5; cursor:not-allowed` | 無効状態 |

#### Account側ボタン（[account/index.html:48-54](account/index.html#L48-L54)）
`.btn` は `padding:13px / border-radius:12px / font-size:14px / font-weight:700` の角丸矩形バリエーション。`.btn-primary`（黒）／`.btn-outline`（白枠）／`.btn-danger`（赤系）。

#### Icon Button
36×36px / 円形 / 透明背景。hover で `#f2f2f2`、active で `#e8e8e8`。

### 5.4 タグ・チップ

| 種類 | スタイル | 用途 |
| --- | --- | --- |
| `.pill-tag` | `#f2f2f2` 背景 / 13px / 削除×アイコン付き | 興味タグ・選択中アイテム |
| `.pill-tag.black` | 黒地白文字 | 強調タグ |
| `.oshi-pill` | 黒地白文字 / 13px / weight 500 | プロフィール表示の推しメン |
| `.oshi-chip` | 白地 / 1.5px ボーダー `#d1d5db` | 編集モードでの選択 chip |
| `.member-chip` | 縦並びアバター＋名前カード | 検索フィルターのメンバー選択 |
| `.match-reason` | `#f0f0f0` / 10px / weight 600 | カード下の「○○推し同士」表示 |
| `.sns-chip` | 14px アイコン + 12px ラベル / ピル | プロフィールの SNS リンク |
| `.stance-pill` | 12px / 薄色背景 / 用途別配色 | フォロースタンス表示 |

### 5.5 タブ／ナビゲーション

#### Floating Tabbar（[index.html:440-444](index.html#L440-L444)）
- 画面下部 20px に浮かぶピル型バー（max-width 360px、`box-shadow:0 4px 12px rgba(0,0,0,0.08)`）。
- 非アクティブ `#bbb`、アクティブ `#000`（`stroke-width:2.3` でアイコンを若干太く）。
- アイコンは Lucide。

#### Filter Tab / QR Tab / Auth Tab
- 共通形: ピル状（`border-radius:9999px`）／非アクティブはグレー背景・グレー文字／アクティブは黒地白文字。
- QR タブのみ下線型（`border-bottom:2px solid #000`）。

### 5.6 アバター

| サイズ | 用途 |
| --- | --- |
| 32px | SNS チップアイコン枠 |
| 44px | プロフィールミニカード |
| 56px | アバター標準・推しカード |
| 64–72px | プロフィール詳細ヘッダー |
| 96px | プロフィール編集画面のアイコンプレビュー |

- 形は完全な円（`border-radius:50%`）。
- 画像は `object-fit:cover; object-position:top center;` で顔上部を優先表示。
- 画像が無いときは推しメン色 + 名前頭文字。
- アバター右下に重なるバッジ（16×16px、`border:2px solid #fff`）
  - 緑: 交換済み
  - 青: SNS 登録済み
  - オレンジ: 管理者

### 5.7 バッジ・インジケーター

- `.filter-trigger-badge`: 18×18px ピル、状態で配色が反転。
- `.tab-count`: タブ内のカウンタ、白/黒で反転。
- `.profile-mini-exchanged-at`: 11px ライトグレーの相対時刻（`formatExchangedAt`）。

---

## 6. レイアウト＆レスポンシブ

[index.html:56-132](index.html#L56-L132) に 3 段階のブレークポイントが定義されている。

| 範囲 | コンテナ幅 | グリッド | カード／入力の角丸 |
| --- | --- | --- | --- |
| `≤ 500px` | `padding:0 12px 110px` | 1 列リスト | カード `24px` |
| `501–768px` | `max-width:600px` / `0 20px` | リスト系を 2 列グリッド | プロフィールミニは中央寄せの縦組み |
| `≥ 769px` | `max-width:1000px` / `0 40px` | 3〜4 列グリッド | カード `28px`、入力はピルから角丸矩形へ |

- フローティングタブバーは常に画面下中央固定。
- モーダルは画面幅に応じて Bottom Sheet → センターカードに切替。
- スクロールバーは `::-webkit-scrollbar { display:none }` で常時非表示。

---

## 7. モーション

| アニメーション | 時間 | イージング | 用途 |
| --- | --- | --- | --- |
| ボタン押下 | `.15s` | デフォルト | `transform:scale(0.96–0.98)` |
| モーダル開閉 | `.3–.4s` | `cubic-bezier(0.4,0,0.2,1)` | Bottom Sheet スライド |
| フィルターモーダル | `.35s` | `cubic-bezier(0.32,0.72,0,1)` | iOS 風の重みのある加速 |
| ページ遷移（編集画面） | `.3s` | `ease-out` `slideInUp` | 編集ページのフルスクリーン遷移 |
| 成功 pop | `.5s` | `cubic-bezier(0.175,0.885,0.32,1.275)` | 交換完了アバター |
| チェック描画 | `.4s @0.3s` | ease | SVG `stroke-dashoffset` 0 まで |
| 紙吹雪 | `1.8s` | `ease-in` | `confettiFall`（交換オーバーレイ） |
| ヒント表示 | `.4s` | ease | `hintFadeIn`（プロフィール未入力バナー） |
| ローディング | `.7s` linear infinite | 線形 | スピナー `glSpin` |

`-webkit-tap-highlight-color:transparent` でタップ時のハイライトを排除し、`transform` ベースの押下フィードバックに統一。

---

## 8. アイコン

- アイコンライブラリは [Lucide](https://lucide.dev/)（CDN: `unpkg.com/lucide@latest`）。
- インライン SVG は `stroke:currentColor; fill:none; stroke-width:2;` を基本ルール。
- QR 関連は `qrcodejs`（生成）と `html5-qrcode`（読み取り）を併用し、UI 要素の余計な描画は CSS で非表示。

---

## 9. 画面別ガイドライン

### 9.1 メイン画面（[index.html](index.html)）

5 つの仮想ページを `hidden` 属性とフローティングタブで切り替える単一ページアプリ構成。

| ページ ID | 役割 |
| --- | --- |
| `#home-page` | マイホームカード + おすすめユーザー |
| `#search-page` | 検索入力 + フィルタートリガー + 結果（無限スクロール） |
| `#mypage-page` | 自分のプロフィールカード + お気に入り + 交換済み |
| `#edit-page` | プロフィール編集フルスクリーンモーダル |
| `#qr-modal` / `#profile-modal` / `#filters-modal` / `#input-modal` / `#auth-modal` 等 | 機能別モーダル |

### 9.2 アカウント設定（[account/index.html](account/index.html)）

- メイン画面より明示的なフォーム階層（`.section` + `.section-title` UPPERCASE + `.card`）。
- 角丸 12px の長方形入力（ピル UI ではなくフォーム UI）。
- 危険操作（ログアウト・削除）は赤系の `.btn-danger`。

### 9.3 お問い合わせフォーム（[form/index.html](form/index.html)）

- 余白を広く取った最小限のシングルカラム。
- 入力はピル系（`border-radius:9999px`）に戻る。
- ファイル添付・セレクトも同じピル形状で統一。

### 9.4 管理画面（[admin.html](admin.html)）

タグマージなど運用ツール用。デザインルールは踏襲するが、装飾は最小限。

---

## 10. アクセシビリティ

- `aria-label` を閉じるボタン・クリアボタンに必須で付与。
- `:focus` 時は `outline:2px solid #000; outline-offset:2px`（pill-button）。
- `accent-color:#000` でラジオ・スイッチの色を統一。
- フォーカス可能領域は最低 36×36px（タップターゲット）。
- `prefers-reduced-motion` 個別対応は現在無し。今後の改善ポイント。

---

## 11. デザイン・トーン上の禁止事項

- 黒・グレースケール以外を「広い面」に使わない（アクセントは小さい要素に限定）。
- グラデーションは原則使わない（モバイルモーダルの背景のみ例外。[index.html:64](index.html#L64)）。
- 影を強くしない（`rgba(0,0,0,0.03〜0.1)` の範囲）。
- フォントの追加読み込みはしない（パフォーマンス）。
- ボタンサイズを最低 40px 高未満にしない。

---

## 12. 主要トークン早見表

```text
COLORS
  bg                #f7f7f7
  surface           #fff
  surface-soft      #f5f5f5 / #fafafa
  text              #111 / #333 / #666 / #999 / #bbb
  primary           #000
  pink-accent       #ffb7c5
  success           #22c55e (badge) / #38a169 (text)
  danger            #e53e3e
  warn-bg           #fff8e1

RADII
  pill              9999px
  card mobile       24px
  card desktop      28px
  small             12px / 14px / 16px
  textarea          20px

SPACING (px)
  4 / 6 / 8 / 10 / 12 / 14 / 16 / 18 / 20 / 24 / 28 / 32 / 40

SHADOWS
  card              0 2px 8px rgba(0,0,0,0.03)
  floating tabbar   0 4px 12px rgba(0,0,0,0.08)
  modal             0 4px 24px rgba(0,0,0,0.18)
  avatar            0 4px 12px rgba(0,0,0,0.10)

EASING
  default           cubic-bezier(0.4, 0, 0.2, 1)
  ios-like          cubic-bezier(0.32, 0.72, 0, 1)
  bounce-pop        cubic-bezier(0.175, 0.885, 0.32, 1.275)
```

---

## 関連ドキュメント

- [README.md](README.md) — 機能仕様・ユーザー向け使い方ガイド。
- [api.php](api.php) — バックエンド API。本デザインドキュメントは UI 層のみが対象。
