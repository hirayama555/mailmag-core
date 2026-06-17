<?php
declare(strict_types=1);

Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . SITE_URL . 'send.php');
    exit;
}

// post_max_size 超過チェック（超過時は $_POST が空になるため CSRF より先に行う）
(function () {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength <= 0) return;
    $val  = trim((string)ini_get('post_max_size'));
    $last = strtolower($val[-1] ?? '');
    $num  = (int)$val;
    switch ($last) {
        case 'g': $max = $num * 1073741824; break;
        case 'm': $max = $num * 1048576;    break;
        case 'k': $max = $num * 1024;       break;
        default:  $max = $num;              break;
    }
    if (empty($_POST) && $contentLength > $max) {
        header('Location: ' . SITE_URL . 'send.php?err=post_too_large');
        exit;
    }
})();

if (!Token::verifyCsrf($_POST['csrf_token'] ?? '')) {
    die('不正なリクエストです。');
}

/**
 * テスト送信・入力エラー後に send.php でフォームを復元するため、
 * 入力内容をセッションに一時保存する（PRG パターンでの入力消失対策）。
 * send.php 側で復元後すぐに破棄される（ワンタイム）。
 */
function saveSendDraft(): void
{
    $_SESSION['send_draft'] = [
        'subject'       => trim($_POST['subject']    ?? ''),
        'body'          => trim($_POST['body']       ?? ''),
        'html_body'     => trim($_POST['html_body']  ?? ''),
        'html_mode'     => !empty($_POST['html_mode']),
        'test_email'    => trim($_POST['test_email'] ?? ''),
        'schedule_type' => $_POST['schedule_type'] ?? 'now',
        'scheduled_at'  => $_POST['scheduled_at']  ?? '',
    ];
}

$admin    = FileDB::getAdmin();
$mailer   = new Mailer($admin);
$sendMode = $_POST['send_mode'] ?? 'send';
$subject  = trim($_POST['subject']   ?? '');
$body     = trim($_POST['body']      ?? '');
$htmlBody = trim($_POST['html_body'] ?? '');

if (!$subject || !$body) {
    saveSendDraft();
    header('Location: ' . SITE_URL . 'send.php?err=empty');
    exit;
}

// ========== テスト送信 ==========
if ($sendMode === 'test') {
    $testEmail = trim($_POST['test_email'] ?? $admin['admin_email'] ?? '');
    if (filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        $testSub      = ['name' => 'テストユーザー', 'email' => $testEmail];
        $testBody     = Mailer::replacePlaceholders($body, $testSub);
        $testHtmlBody = $htmlBody !== '' ? Mailer::replacePlaceholders($htmlBody, $testSub) : '';
        $unsubUrl     = SITE_URL . 'unsubscribe.php?token=test_token';
        $result       = $mailer->send($testEmail, '【テスト】' . $subject, $testBody, $testHtmlBody, $unsubUrl);
        $msg = $result ? 'test_ok' : 'test_fail';
    } else {
        $msg = 'test_invalid';
    }
    saveSendDraft();
    header('Location: ' . SITE_URL . 'send.php?msg=' . $msg);
    exit;
}

// ========== 本送信 or 予約 ==========
$scheduleType = $_POST['schedule_type'] ?? 'now';
$scheduledAt  = $_POST['scheduled_at']  ?? '';

// 予約送信の日時バリデーション
// strtotime() は不正な文字列で false を返し、(int)false = 0 となり
// 1970-01-01 になってしまうためサーバーサイドでも検証する。
if ($scheduleType === 'reserve') {
    $ts = strtotime($scheduledAt);
    if ($ts === false || $ts <= time()) {
        saveSendDraft();
        header('Location: ' . SITE_URL . 'send.php?err=invalid_schedule');
        exit;
    }
}

// 有効な購読者を取得
$subs = array_values(array_filter(
    FileDB::getSubscribers(),
    fn($s) => $s['status'] === '1'
));

if (empty($subs)) {
    saveSendDraft();
    header('Location: ' . SITE_URL . 'send.php?err=no_subscribers');
    exit;
}

$queueId = date('Ymd_His') . '_' . sprintf('%04d', mt_rand(0, 9999));
$now     = date('Y-m-d H:i:s');

// 購読者リストをIDだけに変換してキューに保存
$pendingIds = array_column($subs, 'id');

$queue = [
    'id'           => $queueId,
    'subject'      => $subject,
    'body'         => $body,
    'html_body'    => $htmlBody,
    'total_count'  => count($subs),
    'sent_count'   => 0,
    'success_count'=> 0,
    'offset'       => 0,
    'status'       => $scheduleType === 'reserve' ? 'reserved' : 'pending',
    'scheduled_at' => $scheduleType === 'reserve'
                      ? date('Y-m-d H:i:s', (int)strtotime($scheduledAt))
                      : $now,
    'created_at'   => $now,
    'pending_ids'  => $pendingIds,
];

FileDB::saveQueue($queue);

FileDB::addHistory([
    'id'            => $queueId,
    'subject'       => $subject,
    'body'          => $body,
    'html_body'     => $htmlBody,
    'total_count'   => count($subs),
    'success_count' => 0,
    'status'        => $scheduleType === 'reserve' ? 'reserved' : 'sending',
    'scheduled_at'  => $queue['scheduled_at'],
    'sent_at'       => $now,
    'finished_at'   => '',
]);

// 送信キュー登録に成功したので、一時保存した下書きは破棄する。
unset($_SESSION['send_draft']);

if ($scheduleType === 'reserve') {
    header('Location: ' . SITE_URL . 'history.php?msg=reserved');
} else {
    header('Location: ' . SITE_URL . 'history.php?msg=queued&id=' . urlencode($queueId));
}
exit;
