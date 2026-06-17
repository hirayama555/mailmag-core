# mailmag-core

acmailer 代替の小規模メールマガジン配信システム。クライアントごとに独立インストールしつつ、コア PHP は GitHub Releases から自動更新される配布アーキテクチャ。

## 主な機能

- **テキストメール / HTMLメール送信** — `multipart/alternative` で両方同時配信（HTMLメールは v1.1.0+）
- **テンプレート保存** — テキスト本文・HTML本文ともに保存・再利用可
- **送信履歴と再送信** — 履歴詳細でテキスト本文／HTML本文（ソース）を確認可能
- **配信前バリデーション** — `post_max_size` 超過時はゲートウェイレベルで即時エラー表示
- **自動更新** — Ed25519 署名つき GitHub Releases から既存 cron に相乗りして毎日チェック
- **無依存運用** — レンタルサーバの PHP + cron + `mail()` のみで動作（Supabase / AWS SES 等の外部依存なし）

## 構成

```
mailmag-core/
├── core/                    # CDN管理。core.zip としてリリース
│   ├── VERSION
│   ├── bootstrap.php        # 1点ロード + autoload
│   ├── lib/                 # クラス群（FileDB, Auth, Token, Mailer, Lock 等）
│   ├── includes/            # 共通テンプレート（header / footer）
│   └── app/                 # 各画面・cron の実体ロジック
│
├── client-template/         # クライアントが解凍する初期セット
│   ├── *.php                # 薄いシェル（各3-7行）
│   ├── config.php.example
│   ├── .htaccess
│   ├── assets/css/
│   └── data/                # クライアント固有データ
│
└── .github/workflows/       # GitHub Actions（Phase 3 でリリース自動化）
```

## クライアントが触るもの・触らないもの

| 場所 | 編集 | 自動更新 |
|---|---|---|
| `config.php` | クライアント | ✗ |
| `data/` | システム + クライアント | ✗ |
| `core/` | **絶対に触らない** | ✓（毎日 cron） |
| ルートの `*.php`（薄いシェル） | クライアント編集禁止 | ✓ |

## バージョン

最新リリース: [**v1.1.4**](https://github.com/hirayama555/mailmag-core/releases/latest)（SMTP Auth 送信対応 = DKIM 署名対応）

各クライアントの稼働バージョンは `core/VERSION` で確認できます。

直近の主な変更点:

- **v1.1.4** — SMTP Auth 送信に対応（`core/lib/smtp.php` 新設）。レンタルサーバーの DKIM 署名（SMTP 認証経由のみ署名する仕様）を利用可能に。管理画面に SMTP 設定 UI と接続テストを追加。`smtp_enabled` 未設定の既存クライアントは従来どおり `mail()` で送信（後方互換）。テキストのみ送信時の `Content-Transfer-Encoding: base64` 欠落による文字化けも修正。
- **v1.1.2** — `core/app/send_exec.php` の `match` 式を `switch` に置換し PHP 7.4 で動作するように修正。`core/bootstrap.php` に PHP バージョン下限チェックを追加。
- **v1.1.1** — `Mailer::send()` で Envelope-From (`-f`) を明示し、SPF alignment / DMARC pass を可能化。Gmail の 550-5.7.26 拒否を回避。
- **v1.1.0** — HTML メール送信機能（`multipart/alternative`）、HTML テンプレ保存、履歴詳細での HTML ソース表示。

## ドキュメント

- [INSTALL.md](docs/INSTALL.md) — クライアントへの導入手順
- [UPGRADE.md](docs/UPGRADE.md) — バージョン更新の挙動（自動更新 + 手動ロールバック）

## 実装ステータス

### 完了済み

- [x] **Phase 1**: コア構造の確立 + 既存バグ修正 + シェル化
- [x] **Phase 2**: 自動更新 (`updater.php` / `cron_update.php`) + FileDB 競合修正
- [x] **Phase 3**: GitHub Actions による Release ビルド + Ed25519 署名
- [x] **Phase 4**: E2E メルマガ配信テスト
- [x] **HTMLメール送信機能**（v1.1.0、`multipart/alternative` / テンプレ保存 / 履歴詳細表示）
- [x] **配信元 Envelope-From 強化**（v1.1.1、SPF alignment / DMARC pass を可能に）
- [x] **PHP 7.4 互換性回復**（v1.1.2、共有レンタルサーバでの動作担保）
- [x] **リポジトリ public 化**（クライアント側 PAT 運用が不要に）

- [x] **SMTP Auth 送信対応**（v1.1.4、レンタルサーバーの DKIM 署名を利用可能に。`mail()` 経路は後方互換で維持）

### 検討中

- [ ] 既存環境（acmailer 等）からの移行手順整備
