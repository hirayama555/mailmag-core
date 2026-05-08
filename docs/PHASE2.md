# Phase 2 運用手順（開発者向け）

このドキュメントは **配布物には含まれない** リポジトリ管理者・リリース担当向けの手順書です。クライアントに見せる必要はありません。

## アーキテクチャ要約

| 要素 | 役割 |
|---|---|
| `core/lib/updater.php` | クライアント側で動く更新ロジック。公開鍵 (PUBKEY_HEX) を埋め込み、GitHub Releases から `core.zip` と `core.zip.sig` を取得 → Ed25519 検証 → 原子的差し替え |
| `tools/sign-release.php` | 開発者ローカルで使う鍵管理＋署名 CLI。秘密鍵は `~/.mailmag/release-priv.bin` に保管 |
| `.github/workflows/release.yml` | tag push で `core.zip` をビルドしドラフトリリースを作成（**署名はしない**） |
| `core/app/cron_update.php` + `cron_queue.php` | クライアント側で1日1回更新チェック（既存 cron に相乗り） |

## 初回セットアップ（1度だけ）

### 1. 鍵ペア生成

```sh
php tools/sign-release.php keygen
```

- 秘密鍵: `~/.mailmag/release-priv.bin` に保存される（mode 0600）
- 公開鍵 (hex) が標準出力に表示される
- **秘密鍵は絶対に git にコミットしない**。バックアップは別の安全な場所（パスワードマネージャ等）に取る

### 2. 公開鍵を updater.php に埋め込む

`core/lib/updater.php` の冒頭：

```php
private const PUBKEY_HEX = '<keygen の出力をここに貼る>';
private const REPO       = 'YourOrgName/mailmag-core';
```

### 3. コミット・タグ・push

```sh
git add core/lib/updater.php
git commit -m "Updater 公開鍵とリポジトリ識別子を設定"

# 初回バージョンを切る
echo "1.0.0" > core/VERSION
git add core/VERSION
git commit -m "VERSION 1.0.0"
git tag v1.0.0
git push origin main --tags
```

### 4. 初回リリースに署名

タグ push でワークフローがドラフトリリース `v1.0.0` を作り、`core.zip` を添付する。続けて手元で：

```sh
gh release download v1.0.0 -p core.zip
php tools/sign-release.php sign core.zip   # core.zip.sig が生成される
gh release upload v1.0.0 core.zip.sig
gh release edit v1.0.0 --draft=false       # 公開
```

### 5. 初回配布物の作成

最初のクライアントには **手渡し配布** する zip を作る（updater 自体がまだ動かないため）：

```sh
# client-template と core を1つにまとめた配布用 zip
mkdir -p dist
cp -r client-template dist/mailmag-1.0.0
cp -r core dist/mailmag-1.0.0/core
cp client-template/config.php.example dist/mailmag-1.0.0/config.php.example
cp client-template/data/admin.json.example dist/mailmag-1.0.0/data/admin.json.example
cd dist && zip -r mailmag-1.0.0.zip mailmag-1.0.0
```

この zip にはすでに公開鍵が埋め込まれた `core/` が含まれているので、以降の更新は updater が自動で処理する。

## 通常リリースの流れ

### 1. バージョン更新とタグ作成

```sh
# core/ に変更を加え、テスト後
echo "1.1.0" > core/VERSION
git add -A
git commit -m "1.1.0: 機能 X を追加"
git tag v1.1.0
git push origin main --tags
```

### 2. ワークフロー完了を待つ

GitHub Actions が `core.zip` 入りドラフトリリース `v1.1.0` を作成する。

### 3. 手元で署名 → アップロード → 公開

```sh
gh release download v1.1.0 -p core.zip
php tools/sign-release.php sign core.zip
php tools/sign-release.php verify core.zip   # 念のため
gh release upload v1.1.0 core.zip.sig
gh release edit v1.1.0 --draft=false
```

公開された瞬間、各クライアントの次回 cron 実行で自動更新が走る（最大24時間以内）。

## private リポジトリ期間中の運用

クライアント側 `data/.update_token` に GitHub PAT（read 権限のみ）を置く必要がある。

### PAT 発行

1. GitHub Settings → Developer settings → Personal access tokens → Fine-grained tokens
2. Repository access: 当該リポジトリのみ
3. Permissions: **Contents: Read-only** のみ
4. Expiration: 短め（90日等）でローテーション運用

### PAT 配布

各クライアントの `data/.update_token` に書き込む。`data/` は web から見えないようすでに `.htaccess` で deny 済み。

### Public 化への移行

1. リポジトリ設定で public に切り替え
2. クライアントには「`data/.update_token` を削除して問題ありません」と通知
3. updater.php は `.update_token` の有無を自動判別する設計なので、削除しなくても動作する

## 文字数・本文サイズの実運用ガイド

メール本文は基本的に `post_max_size`（PHP 既定 8M）の範囲内であれば送信可能ですが、
**実運用では受信側 MTA（多くは 10〜25MB）が先に効きます**。HTML メールマガジンの
推奨上限は概ね **100KB 以内**（画像はインライン埋め込みではなく URL 参照を推奨）。

### サイズ別の挙動（XAMPP / PHP 8.2 / Apache 検証）

- ～ 1 MB: 即時送信、品質最良
- 1 〜 5 MB: 送信は通るが MTA 側で弾かれる可能性あり
- 5 〜 30 MB: PHP `mail()` の単発 SMTP 処理が数十秒〜数分。タイムアウトリスクあり
- 30 MB 超 (post_max_size 超過): `send.php?err=post_too_large` で送信前に拒否

### `post_max_size` を上げる場合

- `php.ini`: `post_max_size = 32M` （`memory_limit` は post_max_size の 2 倍以上推奨）
- 共用ホストの場合: `.user.ini` または `.htaccess` (`php_value post_max_size 32M`)

### `send_exec.php` の保護機構

post_max_size 超過時は `?err=post_too_large` リダイレクトが**ゲートウェイレベルで作動**
（`Expect: 100-continue` ハンドシェイクで本体送信前に拒否）。送信ボタン押下後、フォーム
画面に戻り「送信内容が大きすぎます。本文を短くしてください」のエラー表示。

## 障害時の対処

### 秘密鍵を紛失した場合

1. `tools/sign-release.php keygen` で新しい鍵ペアを作る
2. 新公開鍵を `core/lib/updater.php` に埋め込み
3. 新公開鍵入りの `core.zip` を新リリース（旧鍵で署名）として配布する **必要がある**
   - つまり最後の旧鍵署名リリースが効く間に鍵入れ替えを完了させる
4. **完全に紛失（旧鍵も無い）した場合は、各クライアントに手動で新 core を配布し直すしかない**

このリスク回避のため、秘密鍵は複数箇所にバックアップを取ること。

### クライアント側で更新が失敗した場合

クライアントの `data/update.log` を見れば原因がわかる。代表的な失敗：

| ログ | 原因 | 対処 |
|---|---|---|
| `GitHub API 取得失敗` | ネット不通 / PAT 期限切れ | PAT 更新 |
| `署名検証失敗` | リリースに sig が無い / 公開鍵不一致 | リリースに sig アップロードを忘れていないか確認 |
| `tmp ディレクトリ作成失敗` | `data/` のパーミッション問題 | data/ を 755 に |

### 緊急の手動更新

クライアント側で手動実行：

```sh
php /path/to/client/cron_update.php
```

## 廃止予定 / 将来課題

- public 化後は `tools/sign-release.php` の PAT 関連コードは不要になる（updater.php 側は既に対応済み）
- 古いリリースの自動削除は GitHub の仕様上完全には防げないため、新リリースで上書き運用
