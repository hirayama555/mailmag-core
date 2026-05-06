# mailmag-core

acmailer 代替の小規模メールマガジン配信システム。クライアントごとに独立インストールしつつ、コア PHP は GitHub Releases から自動更新される配布アーキテクチャ。

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

現在: `core/VERSION` 参照

## ドキュメント

- [INSTALL.md](docs/INSTALL.md) — クライアントへの導入手順
- [UPGRADE.md](docs/UPGRADE.md) — バージョン更新の挙動（Phase 2 で整備）

## Phase ロードマップ

- [x] **Phase 1**: コア構造の確立 + 既存バグ修正 + シェル化
- [ ] **Phase 2**: 自動更新 (`updater.php` / `cron_update.php`) + Sentry 集約
- [ ] **Phase 3**: GitHub Actions による Release ビルド + Ed25519 署名
- [ ] **Phase 4**: 既存環境からの移行手順
