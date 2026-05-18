# Changelog

本プロジェクトの注目すべき変更点をまとめます。フォーマットは [Keep a Changelog](https://keepachangelog.com/ja/1.1.0/) に準拠し、バージョニングは [SemVer](https://semver.org/lang/ja/) に従います。

## [1.1.2] - 2026-05-18

### Fixed
- **`core/app/send_exec.php`: `match` 式 (PHP 8.0+) を `switch` 文に置換し PHP 7.4 互換を回復。**
  - PHP 7.x 環境で `T_DOUBLE_ARROW` ParseError により送信処理が無音で失敗していた問題を修正。
  - 本番事例: sky7400.com (PHP 7.x) で `send_exec.php` が `HTTP 200 / Content-Length 0` の白画面となり、メール送信が機能しなかった。
  - `bootstrap.php` の `set_exception_handler` が ParseError を `data/error.log` に書き出していたため原因特定に至った（診断ログ設計は正しく機能した）。

### Added
- **`core/bootstrap.php`: PHP バージョン下限チェック (`PHP_VERSION_ID < 70400`) を冒頭に追加。**
  - 将来 PHP 7.3 以下の環境に設置された場合、parse error で黙って失敗するのではなく `mailmag-core requires PHP 7.4 or later.` を明示的に返す。

## [1.1.1] - 2026-05-18

### Fixed
- **`Mailer::send()` の Envelope-From を `-f` で明示**（`core/lib/mail.php`）。
  従来は `mail()` 第5引数が未指定で、Web サーバユーザー（例: `apache@host.example.com`）が Envelope-From になり、From ヘッダーとドメイン不一致になる場合があった。これにより **SPF alignment が落ちて DMARC fail**、Gmail で 550-5.7.26 拒否されるリスクがあった。
- 同時に `$fromEmail` の検証を強化（`FILTER_VALIDATE_EMAIL` を追加。`-f` 引数経路の防御として `escapeshellarg` を併用）。

### Operational impact
- 本リリースを適用したクライアントは、配信元ドメインの SPF レコードに送出 MTA の IP / include を含めれば Gmail 等で `spf=pass / dmarc=pass` が成立するようになる。
- DKIM 不在環境（共有レンタルサーバの `mail()` 経路）でも SPF alignment 単独で DMARC pass を狙える設計。

## [1.1.0] - 先行リリース

### Added
- HTML メール送信（`multipart/alternative`）、HTML 本文のテンプレ保存、履歴詳細での HTML ソース表示。

## [1.0.0] - 初回リリース

### Added
- Phase 1-4 完了: コア構造、自動更新（Ed25519 署名）、GitHub Actions リリース、E2E メルマガ配信。
