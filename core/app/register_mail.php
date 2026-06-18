<?php
declare(strict_types=1);

// ============================================================
// core/app/register_mail.php - 空メール受信処理（メールパイプ）
//
// 旧版からの主要修正:
//   - レート制限を導入（per-email 10分1回 / グローバル 1分30件）。
//     旧版は重複保留時に確認メール再送する仕様で、メールフラッド攻撃に脆弱だった。
// ============================================================

// CLIのみ実行許可
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// ログ記録用
function pipeLog(string $msg): void
{
    $logPath = DATA_DIR . '/mail_pipe.log';
    if (!is_dir(dirname($logPath))) @mkdir(dirname($logPath), 0755, true);
    $line = date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
    @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
}

// ---- グローバルレート制限（フラッド攻撃の入口で遮断）----
if (!RateLimit::allow('register_mail_global', 30, 60)) {
    pipeLog('WARN: Global rate limit exceeded (>30/min)');
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

if (empty(trim($rawMail))) {
    pipeLog('ERROR: Empty stdin');
    exit(1);
}

// ---- From: ヘッダーからメールアドレスを抽出 ----------------
$fromEmail = '';
$fromName  = '';

$headerPart = '';
if (preg_match('/^(.*?)\r?\n\r?\n/s', $rawMail, $hm)) {
    $headerPart = $hm[1];
} else {
    $headerPart = $rawMail;
}

// 折り畳みヘッダーを展開
$headerPart = preg_replace('/\r?\n[ \t]+/', ' ', $headerPart);

if (preg_match('/^From:\s*(.+)$/mi', $headerPart, $fm)) {
    $fromRaw = trim($fm[1]);

    if (preg_match('/<([^>@]+@[^>]+)>/', $fromRaw, $em)) {
        $fromEmail = trim($em[1]);

        $namePart = trim(preg_replace('/<[^>]+>/', '', $fromRaw));
        $namePart = trim($namePart, '"\'');
        // RFC2047 エンコードワード(=?charset?B|Q?...?=)の復号は標準の
        // mb_decode_mimeheader に委譲する。自前実装は (1) Q エンコードを
        // 常に base64_decode して破損 (2) 攻撃者が指定できる charset を
        // mb_convert_encoding に渡し PHP8 で未捕捉 ValueError を起こす、
        // という2つの問題があった。mb_decode_mimeheader は B/Q と未知の
        // charset を安全に扱い、内部エンコーディング(UTF-8)で返す。
        if (strpos($namePart, '=?') !== false) {
            $decoded  = @mb_decode_mimeheader($namePart);
            $fromName = ($decoded !== '') ? $decoded : $namePart;
        } else {
            $fromName = $namePart;
        }
    } elseif (filter_var($fromRaw, FILTER_VALIDATE_EMAIL)) {
        $fromEmail = $fromRaw;
    }
}

if (empty($fromEmail) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
    pipeLog('ERROR: Cannot extract valid email from: ' . substr($rawMail, 0, 200));
    exit(1);
}

$fromEmail = strtolower(trim($fromEmail));
$fromName  = mb_substr(trim($fromName), 0, 50);

// ---- per-email レート制限（同一アドレスへの確認メール濫用防止）
if (!RateLimit::allow('register_mail_' . $fromEmail, 1, 600)) {
    pipeLog("WARN: Per-email rate limit: {$fromEmail}");
    exit(0);
}

pipeLog("INFO: Received from {$fromEmail}");

$admin = FileDB::getAdmin();

// ---- 既存購読者チェック ------------------------------------
if (FileDB::findByEmail($fromEmail)) {
    pipeLog("INFO: Already registered: {$fromEmail}");
    exit(0);
}

// ---- 保留リストに原子的に追加（重複チェック＋追加を1操作で）-----
// 旧実装は getPending()→ループ判定→addPending() の二段で、同一アドレスが
// ほぼ同時に複数 MTA 経由で届くと重複行＋確認メール多重送信の余地があった。
// register.php と同じ addPendingIfNew で原子化する。重複時は再送しない
// （踏み台リスク回避。confirmUrl が届かない場合は per-email レート制限の
//  10分後に再送信を許可）。
$token = Token::generate();
$added = FileDB::addPendingIfNew([
    'email'      => $fromEmail,
    'name'       => $fromName,
    'token'      => $token,
    'source'     => 'mail',
    'created_at' => date('Y-m-d H:i:s'),
]);

if (!$added) {
    pipeLog("INFO: Already pending: {$fromEmail} (skipping resend)");
    exit(0);
}

$mailer = new Mailer($admin);
$result = $mailer->sendConfirmMail($fromEmail, Token::confirmUrl($token));

if ($result) {
    pipeLog("INFO: Confirmation sent to {$fromEmail}");
} else {
    pipeLog("ERROR: Failed to send confirmation to {$fromEmail}");
}

exit(0);
