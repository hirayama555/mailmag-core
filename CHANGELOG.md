# Changelog

本プロジェクトの注目すべき変更点をまとめます。フォーマットは [Keep a Changelog](https://keepachangelog.com/ja/1.1.0/) に準拠し、バージョニングは [SemVer](https://semver.org/lang/ja/) に従います。

## [1.1.3] - 2026-06-14

### Security
- **`core/lib/file_db.php`: `writeJson()` を tmp ファイル + `rename` の原子的書き込みに変更。**
  - 従来は `fopen($path, 'w')` が `flock` 取得前にファイルを 0 バイト切り詰めるため、書き込み中のクラッシュ／プロセス kill で `admin.json` 等が空になりログイン不能に陥るリスクがあった。同一 FS 上の `rename` は原子的なので、読み手は常に旧／新どちらか完全な状態のみを見る。
- **`core/lib/updater.php`: zip 展開前に全エントリ名を検査する Zip Slip 多層防御を追加。**
  - 署名検証済み zip のみ展開する設計だが、万一署名鍵が漏洩した場合に備え `..` / NUL / 絶対パス / ドライブ指定を含むエントリを `extractTo()` 前に拒否する。
- **`core/lib/auth.php`: セッション Cookie の `secure` 判定を堅牢化。**
  - `isset($_SERVER['HTTPS'])` のみだと (a) リバースプロキシ配下で落ちる (b) IIS の `HTTPS='off'` でも true になる弱点があった。`isHttps()` を新設し、TLS 終端／`X-Forwarded-Proto`／443 ポートのいずれにも対応。HTTP のみ環境では false のままなのでログイン不能化しない。

### Fixed
- **`core/lib/mail.php`: 件名・差出人名の MIME エンコードを `mb_encode_mimeheader()` に変更。**
  - 手書きの `=?UTF-8?B?...?=` は RFC 2047 の 75 byte/行制限を無視しており、長い件名で MTA が折返しを誤る恐れがあった。

### Changed
- **`core/lib/file_db.php`: 未使用の `addSubscriber()` に `@deprecated` 注記を追加**（後継は `addSubscriberIfNew()`）。
- **ドキュメント整合**: `HANDOVER.md` の Phase 表・残タスクを v1.1.3 稼働中の現況に更新。`docs/INSTALL.md` の「Phase 2 で予定」を自動更新の `cron_queue.php` 相乗り実装済みの記述に修正。`docs/UPGRADE.md` をスタブから実装フロー（署名検証→展開→原子的差替え→ロールバック）の確定記述へ全面書き換え。

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
