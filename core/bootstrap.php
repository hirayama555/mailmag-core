<?php
declare(strict_types=1);

// ============================================================
// core/bootstrap.php
// クライアントの薄いシェルから最初に1度だけ require される。
// 役割:
//   1. PHP バージョン下限チェック（7.4 未満は明示的に 500 を返す）
//   2. CORE_DIR / CORE_*_DIR の定義
//   3. ランタイム既定値（タイムゾーン・エンコーディング）
//   4. クライアント config.php が省略した場合の既定値
//   5. クラス群の autoload 登録
// ============================================================

// PHP バージョン下限チェック（mailmag-core は PHP 7.4 以上が必須）
// 将来 PHP 7.3 以下に設置された場合、parse error で白画面化する前に
// 明示的なメッセージで失敗させる。
if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('mailmag-core requires PHP 7.4 or later. Current: ' . PHP_VERSION);
}

// クライアント側 config.php が先に require されている前提
if (!defined('BASE_DIR') || !defined('DATA_DIR')) {
    http_response_code(500);
    exit('Configuration error: config.php must be loaded before bootstrap.php');
}

// ---- コアパス定数 ------------------------------------------
define('CORE_DIR',          __DIR__);
define('CORE_LIB_DIR',      CORE_DIR . '/lib');
define('CORE_INCLUDES_DIR', CORE_DIR . '/includes');
define('CORE_APP_DIR',      CORE_DIR . '/app');

$versionFile = CORE_DIR . '/VERSION';
define('MAILMAG_CORE_VERSION', is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : 'unknown');
// 旧コードの互換用エイリアス（headerなどで使用）
define('MAILMAG_VERSION', MAILMAG_CORE_VERSION);

// ---- 既定値（クライアント config.php で上書き可）-----------
defined('BATCH_SIZE')      or define('BATCH_SIZE',      100);
defined('SEND_INTERVAL')   or define('SEND_INTERVAL',   0.1);
defined('MAX_EXEC_TIME')   or define('MAX_EXEC_TIME',   240);
// バッチ送信が途中でクラッシュ/タイムアウトすると status='sending' のまま残るため、
// この秒数以上 updated_at が更新されていない 'sending' キューは「停止」とみなして回収する。
// 正常な1回の実行(MAX_EXEC_TIME=240秒)を十分上回る値にすること（誤回収防止）。
defined('QUEUE_STALL_SECONDS') or define('QUEUE_STALL_SECONDS', 900);
// 送信ループ内で進捗(offset/カウンタ)を永続化する間隔（通）。
// 小さいほどクラッシュ時の再送/取りこぼし範囲が縮むが、保存I/Oが増える。
defined('QUEUE_CHECKPOINT_EVERY') or define('QUEUE_CHECKPOINT_EVERY', 25);
defined('SESSION_NAME')    or define('SESSION_NAME',    'mailmag_sess');
defined('SESSION_LIFETIME')or define('SESSION_LIFETIME', 3600);

// 派生パス（クライアント config.php で個別定義されていない場合のみ）
defined('HISTORY_DIR') or define('HISTORY_DIR', DATA_DIR . '/history');
defined('QUEUE_DIR')   or define('QUEUE_DIR',   DATA_DIR . '/send_queue');
defined('PENDING_DIR') or define('PENDING_DIR', DATA_DIR . '/pending');
defined('OPENS_DIR')   or define('OPENS_DIR',   DATA_DIR . '/opens');
defined('LOCK_DIR')    or define('LOCK_DIR',    DATA_DIR . '/locks');
defined('RATELIMIT_DIR') or define('RATELIMIT_DIR', DATA_DIR . '/rate_limit');

// 画像アップロード（HTMLメール用）。uploads/ は data/ と異なり**公開**ディレクトリ。
// メール受信側のメーラーが画像を直接取得できるよう DocumentRoot 配下に置く。
// PHP 実行は uploads/.htaccess で無効化する（エンドポイントが初回自動生成）。
defined('UPLOADS_DIR') or define('UPLOADS_DIR', BASE_DIR . '/uploads');
defined('UPLOADS_URL') or define('UPLOADS_URL', SITE_URL . 'uploads/');
defined('UPLOAD_MAX_BYTES')       or define('UPLOAD_MAX_BYTES',       5 * 1024 * 1024);   // 1ファイル上限 5MB
defined('UPLOAD_TOTAL_MAX_BYTES') or define('UPLOAD_TOTAL_MAX_BYTES', 100 * 1024 * 1024); // 合計上限 100MB

// ---- ランタイム既定 ----------------------------------------
date_default_timezone_set('Asia/Tokyo');
mb_internal_encoding('UTF-8');
mb_language('Japanese');

// ---- autoload ----------------------------------------------
spl_autoload_register(function (string $class): void {
    static $map = [
        'FileDB'       => 'file_db.php',
        'Auth'         => 'auth.php',
        'Token'        => 'token.php',
        'Mailer'       => 'mail.php',
        'SmtpClient'   => 'smtp.php',
        'SmtpException'=> 'smtp.php',
        'ImapClient'   => 'imap_client.php',
        'ImapException'=> 'imap_client.php',
        'Lock'         => 'lock.php',
        'RateLimit'    => 'rate_limit.php',
        'Uuid'         => 'uuid.php',
        'Updater'      => 'updater.php',
    ];
    if (!isset($map[$class])) return;
    $file = CORE_LIB_DIR . '/' . $map[$class];
    if (is_file($file)) require_once $file;
});

// ---- ディスパッチャ ----------------------------------------
// クライアント側シェルから MailMag::run('login') のように呼び出す
final class MailMag
{
    /**
     * core/app/<page>.php を実行する。
     * 不正なファイル名は404。
     */
    public static function run(string $page): void
    {
        $safe = preg_replace('/[^a-z0-9_]/i', '', $page);
        if ($safe === '' || $safe !== $page) {
            http_response_code(404);
            exit('Not Found');
        }
        $file = CORE_APP_DIR . '/' . $safe . '.php';
        if (!is_file($file)) {
            http_response_code(404);
            exit('Not Found');
        }
        require $file;
    }
}

// ---- 致命エラーのファイルログ ---------------------------------
// Sentry はスキップしたので、未捕捉例外・致命エラーを data/error.log に追記。
// クライアント側で「何かおかしい」と感じたときに最初に見る場所。
// 1MB 超で .1 へローテート（履歴1世代）。
(function (): void {
    $logger = function (string $msg): void {
        $path = DATA_DIR . '/error.log';
        if (is_file($path) && filesize($path) > 1048576) {
            @rename($path, $path . '.1'); // 旧 .1 を上書きする運用（1世代）
        }
        @file_put_contents(
            $path,
            '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    };

    set_exception_handler(function (Throwable $e) use ($logger): void {
        $logger(sprintf(
            'UNCAUGHT %s: %s @ %s:%d',
            get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
        ));
    });

    register_shutdown_function(function () use ($logger): void {
        $err = error_get_last();
        if (!$err) return;
        // 致命系のみ（warning/notice は通常運用ノイズなので拾わない）
        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if (($err['type'] & $fatal) === 0) return;
        $logger(sprintf(
            'FATAL %d: %s @ %s:%d',
            $err['type'], $err['message'], $err['file'], $err['line']
        ));
    });
})();
