<?php
declare(strict_types=1);

// ============================================================
// core/app/cron_queue.php - キュー処理・バッチ送信（5分ごと実行）
//
// 旧版からの主要修正:
//   - flock ベースの非ブロッキング排他ロックを追加。
//     5分間隔の cron に対し MAX_EXEC_TIME=240秒 のため
//     稀に前プロセスが残るケースで重複送信が発生していた。
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

set_time_limit(MAX_EXEC_TIME);

// ---- 排他ロック取得（取得失敗 = 別プロセスが処理中）-----
if (!Lock::acquire('cron_queue')) {
    echo "[" . date('Y-m-d H:i:s') . "] Another cron_queue is running. Skipping." . PHP_EOL;
    exit(0);
}

$admin  = FileDB::getAdmin();
$mailer = new Mailer($admin);
$now    = date('Y-m-d H:i:s');

// ---- pending なキューを取得 -------------------------------
$queues = FileDB::getQueueList();
$queue  = null;
foreach ($queues as $q) {
    if ($q['status'] === 'pending') {
        $queue = $q;
        break;
    }
}

if (!$queue) {
    echo "[{$now}] No pending queue found." . PHP_EOL;
    exit(0);
}

echo "[{$now}] Processing queue: {$queue['id']} offset={$queue['offset']}" . PHP_EOL;

// ロック取得済みなのでstatus='sending'は補助的な可視化用
$queue['status'] = 'sending';
FileDB::saveQueue($queue);
FileDB::updateHistory($queue['id'], ['status' => 'sending']);

// ---- 全購読者データ ----------------------------------------
$allSubs  = FileDB::getSubscribers();
$subMap   = [];
foreach ($allSubs as $sub) {
    $subMap[$sub['id']] = $sub;
}

// ---- pending_ids から未送信分を取得 -----------------------
$pendingIds   = $queue['pending_ids'] ?? [];
$offset       = (int)($queue['offset'] ?? 0);
$batchSize    = (int)($admin['batch_size']    ?? BATCH_SIZE);
$sendInterval = (float)($admin['send_interval'] ?? SEND_INTERVAL);

$batchIds = array_slice($pendingIds, $offset, $batchSize);

if (empty($batchIds)) {
    FileDB::deleteQueue($queue['id']);
    FileDB::updateHistory($queue['id'], [
        'status'      => 'done',
        'finished_at' => $now,
    ]);
    echo "[{$now}] Queue {$queue['id']} already complete (no more IDs)." . PHP_EOL;
    exit(0);
}

$sentCount    = 0;
$successCount = 0;

foreach ($batchIds as $subId) {
    $sub = $subMap[$subId] ?? null;

    if (!$sub || $sub['status'] !== '1') {
        $sentCount++;
        continue;
    }

    $unsubUrl = Token::unsubscribeUrl($sub['token']);
    $body     = Mailer::replacePlaceholders($queue['body'], $sub);
    $result   = $mailer->send($sub['email'], $queue['subject'], $body, '', $unsubUrl);

    if ($result) {
        $successCount++;
    } else {
        $logPath = DATA_DIR . '/send_error.log';
        @file_put_contents(
            $logPath,
            date('[Y-m-d H:i:s] ') . "FAIL: {$sub['email']} (queue: {$queue['id']})" . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
    $sentCount++;

    if ($sendInterval > 0) {
        usleep((int)($sendInterval * 1000000));
    }
}

// ---- キュー進捗の更新 -------------------------------------
$newOffset    = $offset + $sentCount;
$totalSent    = (int)($queue['sent_count']    ?? 0) + $sentCount;
$totalSuccess = (int)($queue['success_count'] ?? 0) + $successCount;
$isComplete   = $newOffset >= count($pendingIds);

echo "[{$now}] Sent {$sentCount} (success: {$successCount}), offset {$offset}→{$newOffset}, complete=" . ($isComplete ? 'yes' : 'no') . PHP_EOL;

if ($isComplete) {
    FileDB::deleteQueue($queue['id']);
    FileDB::updateHistory($queue['id'], [
        'status'        => 'done',
        'success_count' => $totalSuccess,
        'finished_at'   => date('Y-m-d H:i:s'),
    ]);
    echo "[{$now}] Queue {$queue['id']} completed. Total success: {$totalSuccess}" . PHP_EOL;
} else {
    $queue['status']        = 'pending';
    $queue['offset']        = $newOffset;
    $queue['sent_count']    = $totalSent;
    $queue['success_count'] = $totalSuccess;
    FileDB::saveQueue($queue);
    FileDB::updateHistory($queue['id'], [
        'success_count' => $totalSuccess,
    ]);
}

exit(0);
