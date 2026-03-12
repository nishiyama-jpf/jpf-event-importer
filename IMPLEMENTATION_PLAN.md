# 走路利用カテゴリ投稿の更新・締切機能 実装アプローチ

## 背景
現行実装は、CSVの各行を `wp_insert_post` で**新規登録**するのみで、既存投稿の上書き（更新）や、時刻トリガーでの一括クローズ処理はありません。

## 要件整理
1. CSVで該当投稿を上書き更新したい。
   - 「埋まった」「残枠数が変わった」タイミングでまとめて反映。
   - カテゴリ登録も含めて同時更新。
2. 時間トリガーで、該当日付の「走路利用カテゴリ」投稿をすべて削除し、受付終了投稿を入れる。

---

## 提案アプローチ

## 1) CSVインポートを「新規作成 + 更新（Upsert）」に変更

### 1-1. 同一性キー（ユニークキー）を決める
更新対象を特定するため、CSVに一意キーを持たせます。

- **推奨**: `event_key` 列を追加し、`update_post_meta( $post_id, 'event_key', ... )` で保存。
- 代替: `対象日 + カテゴリ + タイトル` を複合キーにする（ただしタイトル変更に弱い）。

### 1-2. 事前探索して `wp_insert_post` / `wp_update_post` を切り替える
- `event_key` が既存投稿に存在したら `wp_update_post`。
- なければ `wp_insert_post`。

### 1-3. カテゴリは毎回「置き換え」で反映
`wp_set_object_terms( $post_id, $term_id, 'category', false )`（第4引数 false）で差し替え更新。

### 1-4. ACF/meta・アイキャッチは更新前提で毎回上書き
現行の `update_post_meta` と `set_post_thumbnail` はそのまま活かせます。

### 1-5. 削除・締切用に「日付」と「走路利用対象フラグ」をmetaに保持
後段のCron対象抽出を安定させるため、CSV取り込み時に例えば以下を保存。
- `event_date`（`Y-m-d`）
- `is_track_usage`（1/0）

---

## 2) 時刻トリガーで「対象日投稿のクローズ処理」を自動実行

### 2-1. WP-Cronイベントをプラグイン有効化時に登録
- `register_activation_hook` で日次/時次イベントを登録。
- `register_deactivation_hook` で解除。

### 2-2. Cronコールバックで対象投稿を抽出
条件例:
- `post_type = post`
- `category = 走路利用カテゴリ`
- `meta_key event_date = 今日`
- `meta_key is_track_usage = 1`

### 2-3. 抽出した投稿を削除（運用方針に応じて）
- 完全削除: `wp_delete_post( $post_id, true )`
- 安全運用: まずは `trash`（復元可能）

### 2-4. 「受付終了」投稿を1件作成
- タイトル例: `YYYY-MM-DD 走路利用 受付終了`
- 本文テンプレート化（管理画面設定でも可）
- 同じカテゴリを付与
- 当日の重複作成防止に `closed_notice_key` のようなmetaを付与し、既存チェック

---

## 3) 管理画面/CSV仕様の最小拡張

### 3-1. CSV列追加（推奨）
既存11列に加えて以下を推奨。
- `event_key`（必須推奨）
- `event_date`（検索安定化）
- `is_track_usage`（0/1）
- `action`（`upsert` / `close_notice` など将来拡張）

### 3-2. 実行結果を「新規/更新/失敗」で集計表示
現行 `成功/失敗` だけでなく、`新規x件 / 更新y件 / 失敗z件` にすると運用しやすいです。

---

## 4) 実装順（安全に段階導入）
1. `event_key` ベースのUpsert実装。
2. meta整理（`event_date`, `is_track_usage` 追加）。
3. Cron登録 + クローズ処理本体。
4. 重複防止ロジック（受付終了投稿）。
5. 管理画面の結果表示改善。

---

## 5) 注意点
- WP-Cronはアクセスがないと実行遅延するため、厳密時刻運用ならサーバーcron + `wp cron event run` も検討。
- 既存データに `event_key` がない場合、初回移行CSVで採番して埋める。
- 削除前にバックアップ推奨（初期はtrash運用が安全）。

---

## 6) 参考: 現行コードとの差分方針
- `wp_insert_post` 固定を、存在チェック付きのUpsertに変更。
- Cron系フック（activation/deactivation + action hook）を追加。
- CSV列パースと管理画面の説明文を拡張。
