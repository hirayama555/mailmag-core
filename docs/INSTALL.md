# インストール手順（クライアント向け）

acmailer 代替メルマガシステム MailMag のインストール手順です。レンタルサーバーへの設置を前提にしています。

## 必要環境

| 項目 | 要件 |
|---|---|
| PHP | **7.4 以上必須**（8.0 以上推奨）<br>PHP 7.3 以下では `core/bootstrap.php` が 500 エラーを明示的に返す |
| 拡張モジュール | `mbstring`, `json`, `fileinfo`, `sodium`（自動更新で必要・通常は標準） |
| `mail()` 関数 | 利用可能であること（カゴヤ・ロリポップ・さくら 等で動作） |
| Cron | 5分ごとと毎分の cron 設定権限があること |
| DocumentRoot 配下 | `/mailmag/` のような独立ディレクトリに設置 |
| 配信元ドメインの SPF レコード | **本番配信前に必須**（Gmail 等の主要受信側で SPF=pass / DMARC=pass を成立させるため）<br>詳細は後述の「0. 配信前提条件の確認」を参照 |

> ⚠️ **PHP バージョンの注意**: 共有レンタルサーバで PHP のバージョン切り替えができない場合、必ず設置先の PHP バージョンを事前に確認してください。`<?php echo PHP_VERSION; ?>` を 1 ファイル設置するだけで判定できます。PHP 7.4 未満の場合、mailmag-core は起動拒否されます。

## 0. 配信前提条件の確認（本番配信前に必読）

mailmag は PHP の `mail()` 関数経由で送信するため、**配信元ドメインの SPF / DMARC 整備**が
されていないと Gmail / iCloud / 大手 ISP に届かない（または迷惑メール扱いになる）可能性が
高くなります。本番稼働の前に必ず以下を確認してください。

### SPF レコードの確認

config.php の `FROM_EMAIL` で指定するドメイン（送信元アドレスの `@` 以降）の DNS に SPF レコードが必要です。

```sh
# 例: magazine@example.com で送る場合
dig +short example.com TXT | grep spf
```

期待される出力例:

```
"v=spf1 +ip4:xxx.xxx.xxx.xxx include:_spf.example-host.com ~all"
```

このレコードに **実際に mail() を送出するサーバの IP / include** が含まれていないと、
受信側で `spf=fail` または `spf=softfail` となり、Gmail で `550-5.7.26` 拒否される可能性があります。

### DMARC レコード（推奨）

```sh
dig +short _dmarc.example.com TXT
```

DMARC ポリシーが存在しなくても配信自体は可能ですが、`p=none` だけでも置いておくと
受信側でのレポーティングが受けられます。

### DKIM 署名（v1.1.4 で SMTP 送信に対応）

DKIM 署名は Gmail / Yahoo! 等の迷惑メール判定を大きく左右します。SPF/DMARC が pass でも
DKIM が無いと迷惑メールフォルダに入りやすくなります。

多くのレンタルサーバー（**カゴヤ・シン・レンタルサーバー等**）は、
**SMTP 認証を経由した送信のみ DKIM 署名を付与**します。PHP 標準の `mail()` は
ローカル MTA に直接渡るため、この DKIM 署名の対象外です。

**v1.1.4 から MailMag は SMTP Auth 送信に対応**しました。以下の手順で DKIM 署名を有効化できます:

1. **サーバー側で DKIM のドメインキーを登録**
   - サーバー管理画面の「DKIM 設定」でセレクター（例: `mailmag`）を新規登録
   - カゴヤ管理ドメイン等では公開鍵が DNS に自動反映される（最大1時間）
   - 他社 DNS 運用の場合は表示された公開鍵を `{セレクター}._domainkey.{ドメイン}` の TXT レコードに登録
2. **MailMag の SMTP 設定**（管理画面 → システム設定 →「SMTP送信設定（DKIM署名対応）」）
   - SMTP送信を有効にする
   - SMTPホスト / ポート / 暗号化方式 / ユーザー名（通常は送信元アドレス）/ パスワードを入力
   - 保存すると自動で接続テストが走る
3. **サーバー側で DKIM の「署名付与」を ON**（公開鍵の DNS 反映完了後）
4. テスト送信し、受信メールのソースで `dkim=pass` を確認

> 💡 SMTP を有効にしない場合は従来どおり `mail()` で送信します（DKIM なし・後方互換）。
> SPF alignment 単独でも DMARC=pass は成立しますが、本番の到達率を上げるには SMTP+DKIM を推奨します。

#### サーバー別の SMTP 設定例

| サーバー | SMTPホスト例 | ポート | 暗号化 | ユーザー名 |
|---|---|---|---|---|
| カゴヤ KIR | `<割当サーバー>.kagoya.net` | 587 | STARTTLS | 送信元メールアドレス |
| シン・レンタルサーバー | `svXXXX.xserver.jp` 等 | 465 / 587 | SSL / STARTTLS | 送信元メールアドレス |

> 正確なホスト名・ポートは各サーバーの「メールソフト設定」「SMTP接続情報」画面で確認してください。

> 💡 **過去事例**: 配信元ドメインの SPF レコードが不備なまま本番配信を行ったところ、
> Gmail で 100% `550-5.7.26` 拒否される事例がありました（v1.1.0 時点）。
> v1.1.1 で Envelope-From を `-f` で明示する修正を入れた後でも、**DNS 側の SPF 整備は前提条件**
> として残ります。

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

自動更新は導入後 `cron_queue.php` に相乗りして動作します（後述）。ただし初回のみ手動で `core.zip` を展開してください:

```bash
# サーバー上で
cd /path/to/mailmag
wget https://github.com/hirayama555/mailmag-core/releases/latest/download/core.zip \
  -H "Authorization: Bearer <YOUR_GITHUB_PAT>"
unzip core.zip -d ./
rm core.zip
```

> 2回目以降のバージョン更新は、下記 Cron 設定後に `cron_queue.php` が自動的にこの作業を行います（手動展開は不要）。

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

> 自動更新は上記 `cron_queue.php` に相乗りで組み込み済みです（24時間に1回チェック）。
> **更新専用の cron 行を追加する必要はありません。**
> 任意で更新だけを独立して回したい場合は `cron_update.php` も利用できますが、通常は不要です:
> ```cron
> # （任意）更新チェックを独立実行したい場合のみ
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

### 8-1. Authentication-Results の確認（本番配信前の必須チェック）

テスト送信を Gmail で受信した後、以下の手順で送信認証の成立を必ず確認してください。
これを怠ると、購読者リストへの一斉配信で大量の Bounce が発生する可能性があります。

1. Gmail で受信したテストメールを開く
2. 右上「︙」→「メッセージのソースを表示」
3. ヘッダ冒頭付近の `Authentication-Results:` 行を確認

期待される表示:

```
Authentication-Results: mx.google.com;
   spf=pass (google.com: domain of magazine@example.com designates xxx.xxx.xxx.xxx as permitted sender) smtp.mailfrom=magazine@example.com;
   dmarc=pass (p=NONE sp=NONE dis=NONE) header.from=example.com
Return-Path: <magazine@example.com>
```

判定:

| 結果 | 意味 | 対応 |
|---|---|---|
| `spf=pass` + `dmarc=pass` | OK。本番配信して問題なし | 一斉配信へ進める |
| `spf=softfail` または `spf=fail` | DNS の SPF レコード不備 | 「0. 配信前提条件の確認」に戻り SPF 修正 |
| `Return-Path` が `apache@xxx` 等の差出人と異なるドメイン | core/lib/mail.php の `-f` 経路が効いていない | v1.1.1 以上に更新（最新リリースを取得） |

### 8-2. 主要受信側の到達確認（推奨）

可能であれば以下 4 系統で受信テストを行い、メイン受信トレイに到達するか確認してください:

- Gmail（最も厳格な分類器）
- Yahoo!メール（日本国内利用者向け）
- 独自ドメインのメールアドレス（受信側 MTA の癖を確認）
- iCloud Mail / Outlook.com（DKIM 重視。最初は迷惑判定される可能性あり）

> 💡 **Sender Warm-up**: 新しい配信元ドメインは送信履歴が無いため、最初の数日は
> iCloud / Outlook 等で迷惑判定されることがあります。テスト配信を 1 週間ほど続けて
> 送信履歴を積むことで、本番一斉配信時のメイン到達率が向上します。

## 9. 開封トラッキング（v1.2.0+）

HTMLメールに 1×1 の透明ピクセル画像を埋め込み、受信者がメールを開いた（=画像を読み込んだ）
回数を計測する機能です。送信フォームの「開封を計測する」チェックボックスでキャンペーンごとに
ON/OFF できます（既定 ON）。開封数（ユニーク）と開封率は **送信履歴の詳細画面**に表示されます。

- ピクセルは `open.php`（ルートの薄いシェル）が配信します。**新規インストールでは
  `client-template` に同梱**されているため追加作業は不要です。
- **既存環境を v1.2.0 へ更新する場合のみ**、`open.php` を1回だけ手動で FTP アップロード
  してください（コア自動更新は `core/` のみを更新し、ルートの `*.php` は更新しないため）。
- 開封ログは `data/opens/<送信ID>.log` に追記保存されます（`data/` は `.htaccess` で保護済み）。

> ⚠️ **精度の注意**: 開封率は「目安値」です。受信側の画像ブロック（既定で画像を表示しない
> クライアント）では過小に、Apple Mail のプライバシー保護（画像先読み）では過大に出ます。
> テキストのみのメールでは原理的に計測できません。傾向の把握用途と割り切ってください。

## トラブル時

`data/` ディレクトリの権限・パスを確認してください。エラー詳細は `data/error.log` を確認してください。

### よくある症状と対処

| 症状 | 原因の可能性 | 対処 |
|---|---|---|
| `send_exec.php` が真っ白（HTTP 200 / Content-Length 0） | PHP バージョンが 7.4 未満で ParseError | サーバ管理画面で PHP 7.4 以上に変更（v1.1.2 では 500 エラーが明示的に出る）|
| 送信後に Gmail で全件 Bounce | 配信元ドメインの SPF 不備 | 「0. 配信前提条件の確認」を実施し、Authentication-Results を再確認 |
| 「送信中」のまま 5 分以上進まない | `cron_queue.php` が登録されていない / cron が動いていない | サーバ管理画面で cron 登録状況を確認、`data/error.log` も確認 |
| 管理画面でログインできない | `data/admin.json` の `setup_done=true` が立っているのにパスワードが不明 | サーバから `admin.json` を取得しバックアップ後、`setup_done=false` に書き換えれば再セットアップ可能 |
