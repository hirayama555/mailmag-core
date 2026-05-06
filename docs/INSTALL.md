# インストール手順（クライアント向け）

acmailer 代替メルマガシステム MailMag のインストール手順です。レンタルサーバーへの設置を前提にしています。

## 必要環境

| 項目 | 要件 |
|---|---|
| PHP | 7.4 以上（8.x 推奨） |
| 拡張モジュール | `mbstring`, `json`, `fileinfo`, `sodium`（自動更新で必要・通常は標準） |
| `mail()` 関数 | 利用可能であること（カゴヤ・ロリポップ・さくら 等で動作） |
| Cron | 5分ごとと毎分の cron 設定権限があること |
| DocumentRoot 配下 | `/mailmag/` のような独立ディレクトリに設置 |

## 1. 配布ファイルの取得

GitHub Releases から `client-template.zip` をダウンロードして展開:

```
https://github.com/hirayama555/mailmag-core/releases/latest
```

展開すると以下の構成になります:

```
mailmag/
├── *.php                    # 薄いシェル群（編集不要）
├── config.php.example
├── .htaccess
├── assets/css/style.css
└── data/                    # クライアント固有データ
    ├── .htaccess
    └── admin.json.example
```

## 2. レンタルサーバーへのアップロード

`mailmag/` ディレクトリ全体を DocumentRoot 配下にアップロードします。
アップロード後、`data/` ディレクトリの書き込み権限を 755 に設定してください。

## 3. config.php の作成

`config.php.example` を `config.php` にコピーし、`SITE_URL` を編集します:

```php
define('SITE_URL', 'https://your-domain.com/mailmag/');
```

## 4. core/ ディレクトリのインストール

Phase 2 で自動更新スクリプトが提供される予定ですが、初回は手動で `core.zip` を展開してください:

```bash
# サーバー上で
cd /path/to/mailmag
wget https://github.com/hirayama555/mailmag-core/releases/latest/download/core.zip \
  -H "Authorization: Bearer <YOUR_GITHUB_PAT>"
unzip core.zip -d ./
rm core.zip
```

> Phase 2 完了後は `cron_update.php` が自動的にこの作業を行うようになります。

## 5. 初期セットアップ

ブラウザで `https://your-domain.com/mailmag/` にアクセス。

`setup.php` 画面が自動で表示されるので、以下を入力:

- サイト名（メルマガ名）
- 管理者メールアドレス
- 送信元メールアドレス
- 管理パスワード（8文字以上）

完了するとセットアップ画面は自動的に無効化されます（`admin.json` の `setup_done=true` で `setup.php` は 403 を返すようになります）。

## 6. Cron 設定

レンタルサーバーの管理画面または crontab で以下を設定:

```cron
# 毎分: 予約送信のチェック（reserved → pending）
* * * * * /usr/local/bin/php /path/to/mailmag/cron_send.php

# 5分ごと: pending キューのバッチ送信
*/5 * * * * /usr/local/bin/php /path/to/mailmag/cron_queue.php
```

> Phase 2 で自動更新用の `cron_update.php` も追加予定:
> ```cron
> 0 0 * * * /usr/local/bin/php /path/to/mailmag/cron_update.php
> ```

## 7. 空メール登録の設定（任意）

レンタルサーバーのメール管理画面で「メール転送（メールパイプ）」を設定:

```
転送先: |/usr/local/bin/php /path/to/mailmag/register_mail.php
```

カゴヤの場合: メール → メールアドレス → 転送先 → コマンド転送

## 8. 動作確認

- `https://your-domain.com/mailmag/` でログイン
- ダッシュボードが表示されればOK
- テスト送信から1通配信して受信確認

## トラブル時

`data/` ディレクトリの権限・パスを確認してください。エラー詳細は Phase 2 で導入する Sentry に集約されます。
