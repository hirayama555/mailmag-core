<?php
declare(strict_types=1);

// ============================================================
// core/app/cron_bounce_imap.php - IMAP ポーリングによるバウンス処理
//
// 目的:
//   メールパイプ（.forward）が使えない共用ホスティング向けの代替手段。
//   bounce 専用メールボックスに IMAP で接続し、未読メッセージを取得・
//   解析してハードバウンス購読者を自動停止する。
//
// 動作条件:
//   - PHP_SAPI === 'cli'
//   - 管理設定で imap_enabled = true かつ接続情報が設定済み
//
// parseBounce / cleanAddr / isHardBounce / bounceLog は bounce.php と
// 意図的に複製している（bounce.php を変更せず、関数名衝突を防ぐため）。
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// ---- 二重起動防止 -----------------------------------------
if (!Lock::acquire('cron_bounce_imap')) {
    exit(0);
}

// ---- 過剰呼び出し防止（10回/60秒） -------------------------
if (!RateLimit::allow('imap_bounce', 10, 60)) {
    imapBounceLog('WARN: Rate limit exceeded');
    Lock::release('cron_bounce_imap');
    exit(0);
}

// ---- 管理設定ロード → IMAP 有効チェック --------------------
$admin = FileDB::getAdmin();
if (empty($admin['imap_enabled'])) {
    Lock::release('cron_bounce_imap');
    exit(0);
}

$imapHost   = (string)($admin['imap_host']   ?? '');
$imapPort   = (int)($admin['imap_port']      ?? 993);
$imapSecure = (string)($admin['imap_secure'] ?? 'ssl');
$imapUser   = (string)($admin['imap_user']   ?? '');
$imapPass   = (string)($admin['imap_pass']   ?? '');

if ($imapHost === '' || $imapUser === '' || $imapPass === '') {
    imapBounceLog('ERROR: IMAP 設定が不完全です (host/user/pass を確認してください)');
    Lock::release('cron_bounce_imap');
    exit(1);
}

// ---- IMAP 接続 ---------------------------------------------
$client = new ImapClient($imapHost, $imapPort, $imapUser, $imapPass, $imapSecure);
try {
    $client->connect();
} catch (ImapException $e) {
    imapBounceLog('ERROR: 接続失敗: ' . $e->getMessage());
    Lock::release('cron_bounce_imap');
    exit(1);
}

// ---- 未読メッセージ取得 ------------------------------------
try {
    $messages = $client->fetchUnseen(50);
} catch (ImapException $e) {
    imapBounceLog('ERROR: FETCH 失敗: ' . $e->getMessage());
    $client->close();
    Lock::release('cron_bounce_imap');
    exit(1);
}

// ---- バウンス解析・処理 ------------------------------------
$seqNums = [];
foreach ($messages as $msg) {
    $seqNums[] = $msg['seq'];

    $result = imapParseBounce($msg['raw']);
    $email  = $result[0];
    $isHard = $result[1];
    $code   = $result[2];

    if ($email === '') {
        imapBounceLog('INFO: 宛先抽出不能 (seq=' . $msg['seq'] . ', skipped)');
        continue;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        imapBounceLog('INFO: 無効アドレス無視: ' . substr($email, 0, 100));
        continue;
    }
    $email = strtolower($email);

    if ($isHard) {
        $id = FileDB::markBounced($email);
        if ($id !== null) {
            imapBounceLog("HARD\t{$email}\t{$code}\tstopped(id={$id})");
        } else {
            imapBounceLog("HARD\t{$email}\t{$code}\tno-active-subscriber");
        }
    } else {
        imapBounceLog("SOFT\t{$email}\t{$code}\trecorded");
    }
}

// ---- 処理済みメッセージを削除 ------------------------------
if (!empty($seqNums)) {
    try {
        $client->deleteAndExpunge($seqNums);
    } catch (ImapException $e) {
        imapBounceLog('WARN: EXPUNGE 失敗: ' . $e->getMessage());
    }
}

$client->close();
Lock::release('cron_bounce_imap');
exit(0);

// ============================================================
// 以下は bounce.php から複製したローカル関数
// （bounce.php は変更せず、同名関数の衝突を避けるため独立定義）
// ============================================================

function imapBounceLog(string $msg): void
{
    $logPath = DATA_DIR . '/bounce.log';
    if (!is_dir(dirname($logPath))) @mkdir(dirname($logPath), 0755, true);
    @file_put_contents($logPath, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * @return array{0:string,1:bool,2:string}
 */
function imapParseBounce(string $raw): array
{
    $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $raw);

    $email = '';
    $code  = '';

    if (preg_match('/^(?:Final|Original)-Recipient:\s*[^;]*;\s*([^\s]+@[^\s]+)/mi', $unfolded, $m)) {
        $email = imapCleanAddr($m[1]);
    }

    if ($email === '' && preg_match('/^X-Failed-Recipients:\s*([^\s,]+@[^\s,]+)/mi', $unfolded, $m)) {
        $email = imapCleanAddr($m[1]);
    }

    if (preg_match('/^Status:\s*([0-9]\.[0-9]+\.[0-9]+)/mi', $unfolded, $m)) {
        $code = $m[1];
    }

    if ($email === '' || $code === '') {
        if ($email === '' && preg_match('/<([^>\s]+@[^>\s]+)>/', $raw, $m)) {
            $email = imapCleanAddr($m[1]);
        }
        if ($code === '' && preg_match('/\b([45]\.[0-9]+\.[0-9]+)\b/', $raw, $m)) {
            $code = $m[1];
        }
        if ($code === '' && preg_match('/\b(5[0-9][0-9])\b[\s-]/', $raw, $m)) {
            $code = $m[1];
        }
    }

    $isHard = imapIsHardBounce($code, $raw);
    return [$email, $isHard, $code !== '' ? $code : '?'];
}

function imapCleanAddr(string $s): string
{
    return trim($s, " \t\r\n<>\"'.,;");
}

function imapIsHardBounce(string $code, string $raw): bool
{
    if (preg_match('/^5\./', $code)) return true;
    if (preg_match('/^4\./', $code)) return false;
    if (preg_match('/^5[0-9][0-9]$/', $code)) return true;
    if (preg_match('/^4[0-9][0-9]$/', $code)) return false;

    $hardPhrases = [
        'user unknown', 'no such user', 'recipient address rejected',
        'does not exist', 'unknown user', 'mailbox unavailable',
        'address not found', 'no such address', 'account has been disabled',
        'account is disabled', 'user does not exist',
    ];
    $low = strtolower($raw);
    foreach ($hardPhrases as $p) {
        if (strpos($low, $p) !== false) return true;
    }
    return false;
}
