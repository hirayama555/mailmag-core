# Changelog

本プロジェクトの注目すべき変更点をまとめます。フォーマットは [Keep a Changelog](https://keepachangelog.com/ja/1.1.0/) に準拠し、バージョニングは [SemVer](https://semver.org/lang/ja/) に従います。

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
