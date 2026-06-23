# Changelog

本プロジェクトの注目すべき変更点をまとめます。フォーマットは [Keep a Changelog](https://keepachangelog.com/ja/1.1.0/) に準拠し、バージョニングは [SemVer](https://semver.org/lang/ja/) に従います。

## [1.8.0] - 2026-06-23

### Added
- **`core/lib/file_db.php` / `core/app/subscriber_add.php`: 外部システムの購読解除を同期する「CSV一括購読解除」を追加。**
  - **`FileDB::unsubscribeEmailsBulk()`**: CSV で渡したアドレス群を一括で「購読解除（status 9）」にする。NowGetter 等で本人が購読解除した読者を MailMag 側へ同期する用途。バウンス由来の `suppressEmailsBulk()`（status 0）とは別に、**自発的オプトアウトを意味的に正しい status 9 として記録**する（特定電子メール法のオプトアウト管理）。マッチ行のうち有効(1)・エラー停止(0)を 9 へ移行し、既に 9 はスキップ。`suppressEmailsBulk` と同じ `modifyCsvAtomic`（1回の `LOCK_EX` + `.bak` 退避）基盤に乗る O(N+M) 実装で可逆。
  - UI は「購読者追加」→「一括メンテナンス」カードに第3操作「解除アドレスを一括購読解除」として追加（`mode=unsubscribe_csv`）。CSV は停止用と同形式（1列目=メールアドレス／1行目ヘッダ／BOM除去）。同カードのレイアウトを 3 項目対応（`auto-fit`）にしてレスポンシブ化。

### Fixed
- **`core/app/unsubscribe.php`: 単体購読解除で、CSV スキーマ(7列)に存在しない `unsubscribed_at` 列を `updateSubscriber()` に渡していた死にコードを除去。** 当該キーは `modifyCsvAtomic` の書き戻し（header 基準の固定列書き込み）で黙殺されており、機能・データへの影響はなかったが紛らわしいため削除。解除時刻は従来どおり `updated_at` に自動記録される。

### Operational impact
- 本リリースの変更は**すべて `core/` 配下**（自動更新対象）。ルートシェルや手動アップロードは不要で、既存クライアントは毎日の自動更新で機能が反映される。

## [1.7.0] - 2026-06-23

### Added
- **`core/lib/mail.php` / `core/app/settings.php`: バウンスの戻り先（Return-Path / Envelope-From）を専用アドレスに分離できる「バウンス受信用アドレス」設定を追加。**
  - これまで Envelope-From は送信元（`from_email`）固定で、配送失敗通知（バウンス）が店頭運用の受信箱に大量流入していた。システム設定の「バウンス受信用アドレス（Return-Path）」に `bounce@example.net` 等を指定すると、`mail()` 経路（`-f`）と SMTP 経路（`MAIL FROM`）の両方でそのアドレスを Envelope-From に使い、バウンスだけを専用メールボックスに隔離できる。
  - **見える差出人（`From:`）と返信先（`Reply-To:`）は従来どおり `from_email` のまま**変わらない。バウンス回収先（`bounce@`）と同一ドメインであれば SPF alignment（DMARC pass）も維持される。
  - 空欄時は従来どおり `from_email` にフォールバック（後方互換）。`IMAP受信設定`をこのアドレスのメールボックスに向けて運用する。
- **`core/lib/file_db.php` / `core/app/subscriber_add.php`: 購読者の一括メンテナンス（不達一括停止・Yahoo一括削除）を追加。**
  - **不達アドレスの一括エラー停止（`FileDB::suppressEmailsBulk()`）**: NowGetter 等で既に不達と判明しているアドレス一覧（CSV）を取り込み、該当する有効購読者を一括で「エラー停止（status 0）」にする。次の大量配信前に既知の不達へ再送するのを防ぎ、送信レピュテーション悪化を回避する。1 回の `LOCK_EX` 内で処理する O(N+M) 実装。
  - **Yahoo系ドメインの一括物理削除（`FileDB::deleteByDomains()`）**: `EXCLUDE_DOMAINS`（`yahoo.co.jp` / `yahoo.ne.jp` / `ybb.ne.jp`）に一致する購読者を配信リストから物理削除する。削除前に `.bak` 退避。
  - UI は「購読者追加」ページに「一括メンテナンス」カードとして追加（新規ページを作らず既存ルートに同梱したため、自動更新だけで配信される）。

### Operational impact
- 本リリースの変更は**すべて `core/` 配下**（自動更新対象）。ルートシェルや手動アップロードは不要で、既存クライアントは毎日の自動更新で機能が反映される。
- バウンス隔離を有効化するには、クライアント側で (1)「バウンス受信用アドレス」を設定、(2) `IMAP受信設定`を同アドレスのメールボックスに向ける、(3) `cron_bounce_imap.php` を cron 登録、の運用作業が必要。

## [1.6.0] - 2026-06-23

### Added
- **`core/app/send.php` / `core/app/send_exec.php`: メルマガ作成ページに「Yahoo を除外」チェックボックスを追加（既定 ON）。**
  - Yahoo（`yahoo.co.jp` / `yahoo.ne.jp` / `ybb.ne.jp`）は SGS（送信者レピュテーション）による一括配信拒否が起こりやすいため、送信対象から除外し別経路（NowGetter 等）に回せるようにする。本番では `ybb.ne.jp` 登録者が 31 件存在することを確認済み。
  - 作成ページに該当件数（有効購読者のうち除外ドメインに一致する数）を表示。テスト送信・予約送信をまたいでもチェック状態を下書きとして復元する。
  - 除外は**キュー構築時**（`send_exec.php`、`pending_ids` 生成前）に適用するため、キュー／履歴の `total_count` が「実配信数」となり、`cron_queue.php`（バッチ処理）は一切変更不要。
  - **`core/bootstrap.php`: 除外ドメインの単一情報源 `EXCLUDE_DOMAINS` 定数とヘルパー（`mailmag_email_domain()` / `mailmag_excluded_domains()`）を新設。** ドメイン比較は厳密な完全一致（`in_array(..., true)`）で、`notyahoo.co.jp` 等の類似ドメインを誤除外しない。クライアントは `config.php` で `EXCLUDE_DOMAINS` を上書きして除外対象を調整できる。
- **`core/lib/file_db.php`: 購読者 CSV 一括インポートの高速化（`FileDB::addSubscribersBulk()` を新設）。**
  - 従来は CSV 1 行ごとに `addSubscriberIfNew()` を呼び、件数分だけ CSV 全体の read-modify-write が走って O(N²) となり、件数が多いと 504 タイムアウトしていた。1 回の `LOCK_EX` 内で既存＋バッチ内の重複（email・大小無視）を弾きつつ新規行をまとめて追記する方式に変更し、計算量を O(N+M) に抑えた。
  - **`core/app/subscriber_add.php`: CSV インポートを全行パース → 一括追加に変更。** 先頭セルの BOM 除去（Excel 保存 CSV 対策）、1 件も追加できなかった場合の原因明示（文字コード／列順／ヘッダー行）を追加。
  - **`core/lib/file_db.php`: CSV 書き戻し前に現在の内容を `.bak` へ退避。** `ftruncate→書き直し`は書き込み途中でプロセスが落ちるとファイルが空のまま残る非クラッシュ安全な操作のため、書き込み直前に `.bak` を作成し直前状態から復旧できるようにした。
  - **`docs/subscribers_import_template.csv` を追加**（CSV インポート用テンプレート、UTF-8 BOM 付き）。

### Operational impact
- 本リリースの変更は**すべて `core/` 配下**（自動更新対象。`docs/` のテンプレートは参考資料）。ルートシェルや `assets/css/style.css` の手動アップロードは不要で、既存クライアントは毎日の自動更新で機能が反映される。

## [1.5.0] - 2026-06-21

### Added
- **`cron_bounce_imap.php`: IMAP ポーリングによるバウンス処理を追加。**
  - メールパイプ（`.forward` / `bounce.php`）が利用できないカゴヤ等の共用ホスティング向けの代替手段。bounce 専用メールボックスに IMAP で定期接続し、未読バウンス通知を取得・解析・削除する。php-imap 拡張不要（`SmtpClient` と同様に raw socket 実装）。
  - ハードバウンス（5.x.x）は購読者を自動「エラー停止」、ソフトバウンス（4.x.x）は `data/bounce.log` に記録のみ（v1.4.0 の `bounce.php` と完全に同等の解析ロジック）。
  - 管理画面「システム設定 → IMAP受信設定」で接続情報を入力・保存し接続テストまで完了。
  - **※ 既存クライアントは `cron_bounce_imap.php`（ルートシェル）の手動アップロードと cron 追加が必要。**

## [1.4.0] - 2026-06-19

### Added
- **`bounce.php`: バウンス自動処理を追加。** 配信不達の通知メールをメールパイプ（`bounce.php`）で受信し、ハードバウンス（恒久エラー 5.x.x／ユーザー不明等）した宛先の購読者を自動で「エラー停止」にして次回配信から除外。ソフトバウンス（一時エラー 4.x.x）は `data/bounce.log` に記録のみ。RFC3464 DSN を最優先し、素朴な本文バウンスもフォールバック解析。

### Changed
- **管理 UI の質感向上**: ログイン／setup の洗練、`stat-card` のアクセントライン、ボタンの押下フィードバック、`:focus-visible` によるキーボード操作のフォーカス可視化、アラートのアイコン、そして**レスポンシブ対応**（狭幅でサイドバーを上部ナビに、グリッドを 1 カラムに）。
- **ドキュメント整合**: `docs/UPGRADE.md` の手動更新コマンド（`unzip -d core/`）修正と、ルート `*.php` が自動更新対象外である旨の明記。

### Operational impact
- **※ バウンスは `bounce.php`、UI は `assets/css/style.css` の初回手動アップロードが必要**（いずれも自動更新対象外）。

## [1.3.4] - 2026-06-18

### Fixed
- 堅牢化（バグ監査バッチ B・残 LOW 5 件）。(1) Reply-To アドレスが空のとき空ヘッダを送出していたのを修正（送信元にフォールバックし、空なら出力しない）。(2) 空メール登録の From 表示名デコードを `mb_decode_mimeheader` に委譲し、Q エンコード破損と PHP 8 での未捕捉例外を解消。(3) 空メール登録の保留追加を原子的 `addPendingIfNew` に統一（重複行・確認メール多重送信の競合を解消）。(4) 画像アップロードの合計容量チェックをロックで直列化し TOCTOU を回避。(5) 未認証時の画像 API 応答を HTML リダイレクトでなく `401 + JSON` に変更（セッション切れ時の誤メッセージを解消）。

## [1.3.3] - 2026-06-17

### Fixed
- 一斉配信の信頼性向上（バグ監査バッチ A）。(1) バッチ送信がタイムアウト／クラッシュで中断するとキューが `sending` のまま固まり残りの受信者が永久に送られない問題を修正。`updated_at` を導入し、一定時間（既定 15 分）放置された `sending` キューを次回 cron が自動回収して再開する。(2) 送信ループ内で進捗（offset/カウンタ）を一定通数ごと（既定 25 通）に逐次保存し、中断時の再送範囲を最小化・途中再開を可能に。(3) システム設定／初期設定でメールアドレスをサーバ側でも形式検証。(4) 破損した送信キュー／履歴 JSON を黙ってスキップせず `data/error.log` に記録。

## [1.3.2] - 2026-06-16

### Changed
- HTML メール送信時、テキスト「本文」欄を任意項目に変更（従来は必須で、ビジュアルエディタだけ書いて送信しようとするとブラウザに入力を促されていた）。テキスト本文を空にした場合は HTML 本文からテキスト版を自動生成し、`multipart/alternative` のテキストパートに格納する。

### Fixed
- `FileDB::writeJson()` を堅牢化し、`json_encode` 失敗時に 0 バイトの壊れた JSON を書き込まないよう修正。

## [1.3.1] - 2026-06-16

### Added
- 画像ライブラリの改善。(1) 左メニューに「画像ライブラリ」を追加し、メール作成と独立して画像をアップロード・削除・URL コピーできる管理画面を新設（`media.php` を API/UI ハイブリッド化したのでルートシェルの追加は不要）。

### Fixed
- ビジュアルエディタの「画像の挿入/編集」ダイアログから画像ライブラリを開いたとき、ライブラリがダイアログの背面に隠れて操作できなかった z-index の不具合を修正。

## [1.3.0] - 2026-06-15

### Added
- HTML 本文のビジュアルエディタ（TinyMCE 自己ホスト版・GPL/API キー不要）と画像ライブラリを追加。事前アップロードした画像をサムネイル一覧から選んで挿入できる。画像は公開ディレクトリ `uploads/` に保存し、`uploads/.htaccess`（エンドポイントが初回自動生成）でスクリプト実行を多層に禁止。アップロードは認証 + CSRF + 実 MIME 検証（JPEG/PNG/GIF/WebP）+ ファイル名ランダム化で保護。1 ファイル 5MB・合計 100MB 上限。新規エンドポイント `media.php`（ルートシェル）追加。

### Changed
- 送信完了メッセージから内部実装（CRON）の表記を除去。

### Operational impact
- **※ 既存クライアントは `media.php` の初回手動アップロードが必要。**

## [1.2.1] - 2026-06-15

### Fixed
- 自動更新の致命バグを修正。GitHub Releases アセットのダウンロード URL は CDN へのリダイレクト（302）を経由するが、`file_get_contents + follow_location` 利用時の HTTP ステータスチェックで先頭の 302 を見て「ダウンロード失敗」と誤判定していた。最後のステータス行を参照するよう修正。

### Changed
- ルートの `*.php` は自動更新対象外である旨を README の表にも明記。

## [1.2.0] - 2026-06-14

### Added
- 開封トラッキング対応。HTML メールに 1×1 透明ピクセルを埋め込み、開封数（ユニーク）・開封率を送信履歴詳細に表示。送信フォームの「開封を計測する」チェックボックスでキャンペーンごとに ON/OFF（既定 ON）。開封ログは `data/opens/<id>.log` に追記保存。新規エンドポイント `open.php`（ルートシェル）追加。受信側の画像ブロック等により実数とずれる目安値である旨を UI に明記。

### Operational impact
- **※ 既存クライアントは `open.php` の初回手動アップロードが必要。**

## [1.1.5] - 2026-06-14

### Fixed
- テスト送信後にフォームの入力（件名・本文・HTML 本文・HTML モード・テスト送信先・送信タイミング・予約日時）が消えてしまう UI バグを修正。PRG リダイレクトをまたいでセッションに一時保存し、`send.php` 再描画時に復元（ワンタイム消費）。入力エラー（件名・本文未入力／予約日時不正／配信対象なし）からの差し戻し時も入力を保持。

## [1.1.4] - 2026-06-14

### Added
- SMTP Auth 送信に対応（`core/lib/smtp.php` 新設）。レンタルサーバーの DKIM 署名（SMTP 認証経由のみ署名する仕様）を利用可能に。管理画面に SMTP 設定 UI と接続テストを追加。

### Fixed
- テキストのみ送信時の `Content-Transfer-Encoding: base64` 欠落による文字化けを修正。

### Changed
- `smtp_enabled` 未設定の既存クライアントは従来どおり `mail()` で送信（後方互換）。

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
