<?php
declare(strict_types=1);

// ============================================================
// core/app/bounce.php - バウンス（配信不達）処理（メールパイプ）
//
// レンタルサーバのメール転送（パイプ）でバウンス通知メールを受け取り、
// ハードバウンス（恒久エラー）した宛先の購読者を status=0（エラー停止）に
// する。これにより次回以降の一斉配信から自動的に除外される
// （cron_queue.php は status==='1' のみ送信）。
//
// 設計:
//   - register_mail.php と同じメールパイプ方式。STDIN にメール全文が渡る。
//   - Envelope-From は Mailer が -f で from_email に設定済みのため、
//     不達通知は from_email（またはサーバが向けたエイリアス）へ戻る。
//   - ハードバウンス（5.x.x / 5xx SMTP）のみ停止。ソフト（4.x.x）は記録のみ
//     （一時的なメールボックス満杯等で購読者を失わないため）。
//   - 解析は RFC3464 DSN を最優先し、無ければ本文のパターンで補完する。
// ============================================================

// CLIのみ実行許可（HTTP直アクセス禁止）
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

function bounceLog(string $msg): void
{
    $logPath = DATA_DIR . '/bounce.log';
    if (!is_dir(dirname($logPath))) @mkdir(dirname($logPath), 0755, true);
    @file_put_contents($logPath, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// ---- フラッド対策（パイプ濫用の入口で遮断）-----------------
if (!RateLimit::allow('bounce_global', 120, 60)) {
    bounceLog('WARN: Global rate limit exceeded (>120/min)');
    exit(0);
}

// ---- STDIN からメール全文を読み込む ------------------------
$rawMail = '';
$stdin   = fopen('php://stdin', 'r');
if ($stdin) {
    while (!feof($stdin)) {
        $rawMail .= fread($stdin, 8192);
    }
    fclose($stdin);
}
if (trim($rawMail) === '') {
    bounceLog('ERROR: Empty stdin');
    exit(1);
}

// 自身の配信ループ防止: MailMag が送ったメールがそのまま戻ってきた場合は無視
// （X-Mailer ヘッダを持つ＝バウンス本体ではなく原文の可能性）。ただし DSN は
// 原文を添付として含むため、ヘッダ判定だけに頼らず解析側で宛先を厳密に絞る。

[$email, $isHard, $code] = parseBounce($rawMail);

if ($email === '') {
    bounceLog('INFO: No recipient extracted (ignored)');
    exit(0);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    bounceLog('INFO: Invalid recipient ignored: ' . substr($email, 0, 100));
    exit(0);
}
$email = strtolower($email);

if ($isHard) {
    $id = FileDB::markBounced($email);
    if ($id !== null) {
        bounceLog("HARD\t{$email}\t{$code}\tstopped(id={$id})");
    } else {
        // 既に停止済み / 未登録 / 購読解除済み
        bounceLog("HARD\t{$email}\t{$code}\tno-active-subscriber");
    }
} else {
    // ソフトバウンスは記録のみ（購読者は据え置き）
    bounceLog("SOFT\t{$email}\t{$code}\trecorded");
}

exit(0);

// ============================================================
// 解析ロジック
// ============================================================

/**
 * バウンスメールから [失敗宛先, ハードか, ステータスコード] を抽出する。
 * @return array{0:string,1:bool,2:string}
 */
function parseBounce(string $raw): array
{
    // ヘッダの折り畳みを展開（後続行頭の空白を連結）
    $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $raw);

    $email = '';
    $code  = '';

    // (1) RFC3464 DSN: Final-Recipient / Original-Recipient
    //     例: Final-Recipient: rfc822; user@example.com
    if (preg_match('/^(?:Final|Original)-Recipient:\s*[^;]*;\s*([^\s]+@[^\s]+)/mi', $unfolded, $m)) {
        $email = cleanAddr($m[1]);
    }

    // (2) qmail/sendmail 系: X-Failed-Recipients
    if ($email === '' && preg_match('/^X-Failed-Recipients:\s*([^\s,]+@[^\s,]+)/mi', $unfolded, $m)) {
        $email = cleanAddr($m[1]);
    }

    // ステータスコード抽出
    //   DSN: Status: 5.1.1
    if (preg_match('/^Status:\s*([0-9]\.[0-9]+\.[0-9]+)/mi', $unfolded, $m)) {
        $code = $m[1];
    }

    // (3) 本文ベースのフォールバック（DSN が無い素朴なバウンス）
    if ($email === '' || $code === '') {
        // 「... user@example.com ... 550 5.1.1 ...」のような行を広く探す
        if ($email === '' && preg_match('/<([^>\s]+@[^>\s]+)>/', $raw, $m)) {
            $email = cleanAddr($m[1]);
        }
        if ($code === '' && preg_match('/\b([45]\.[0-9]+\.[0-9]+)\b/', $raw, $m)) {
            $code = $m[1];
        }
        if ($code === '' && preg_match('/\b(5[0-9][0-9])\b[\s-]/', $raw, $m)) {
            // 3桁 SMTP コード（550 等）
            $code = $m[1];
        }
    }

    $isHard = isHardBounce($code, $raw);
    return [$email, $isHard, $code !== '' ? $code : '?'];
}

/** メールアドレス文字列の前後ノイズ（<>、引用符、句読点）を除去 */
function cleanAddr(string $s): string
{
    $s = trim($s, " \t\r\n<>\"'.,;");
    return $s;
}

/**
 * ハードバウンス判定。
 *  - 拡張ステータス 5.x.x はハード、4.x.x はソフト
 *  - 3桁 SMTP 5xx はハード
 *  - コード不明時は本文の典型文言（user unknown 等）でハード寄りに判定
 */
function isHardBounce(string $code, string $raw): bool
{
    if (preg_match('/^5\./', $code)) return true;   // 5.x.x
    if (preg_match('/^4\./', $code)) return false;  // 4.x.x
    if (preg_match('/^5[0-9][0-9]$/', $code)) return true; // 5xx
    if (preg_match('/^4[0-9][0-9]$/', $code)) return false; // 4xx

    // コード不明: 恒久エラーを示す典型文言があればハード
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
    // 判定不能はソフト扱い（誤って購読者を切らない安全側）
    return false;
}
