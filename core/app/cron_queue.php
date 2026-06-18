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

// ---- 自動更新チェック相乗り（1日1回）------------------------
// クライアント側に cron 設定を追加させないため、既存 cron_queue の
// 開頭で更新チェックを走らせる。data/.update_check の mtime が 24時間以上
// 前ならチェックを実行し、終了後に touch する（キューが空のクライアントでも
// 確実に走るよう、早期 exit より前で実施）。
$checkFlag = DATA_DIR . '/.update_check';
$lastCheck = is_file($checkFlag) ? (int)filemtime($checkFlag) : 0;
if (time() - $lastCheck >= 86400) {
    if (class_exists('Updater') && Lock::acquire('cron_update')) {
        $r = Updater::checkAndApply();
        echo "[{$now}] update: {$r['status']}: {$r['message']}" . PHP_EOL;
    }
    @touch($checkFlag); // 失敗時も次回まで間隔を空ける（連続呼び出し抑止）
}

// ---- 処理対象キューを選ぶ ---------------------------------
// 1) pending を優先。
// 2) pending が無ければ、'sending' のまま QUEUE_STALL_SECONDS 以上放置された
//    キュー（＝前回バッチがクラッシュ/タイムアウトで中断した残骸）を回収する。
//    cron_queue は単一ロックで直列化されているため、ここで拾う 'sending' を
//    別プロセスが処理中ということはない（停止済みと判断して安全に再開できる）。
$queues      = FileDB::getQueueList();
$queue       = null;
$stallCutoff = time() - QUEUE_STALL_SECONDS;
foreach ($queues as $q) {
    if (($q['status'] ?? '') === 'pending') {
        $queue = $q;
        break;
    }
}
if (!$queue) {
    foreach ($queues as $q) {
        if (($q['status'] ?? '') !== 'sending') continue;
        $stamp = strtotime((string)($q['updated_at'] ?? $q['created_at'] ?? '')) ?: 0;
        if ($stamp <= $stallCutoff) {
            $queue = $q;
            echo "[{$now}] Recovering stalled queue: {$queue['id']} (offset={$queue['offset']})" . PHP_EOL;
            break;
        }
    }
}

if (!$queue) {
    echo "[{$now}] No pending queue found." . PHP_EOL;
    exit(0);
}

echo "[{$now}] Processing queue: {$queue['id']} offset={$queue['offset']}" . PHP_EOL;

// バッチ処理中であることを記録。updated_at はスタール検知（再選択可否）の判定キーを兼ねる。
$queue['status']     = 'sending';
$queue['updated_at'] = $now;
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
// クラッシュ/タイムアウト時の再送・取りこぼしを最小化するため、ループ内で
// 進捗を逐次永続化する。ここで base を控え、checkpoint/最終書き戻しで base+今回分を保存。
$baseSent    = (int)($queue['sent_count']    ?? 0);
$baseSuccess = (int)($queue['success_count'] ?? 0);
$baseOffset  = $offset;

foreach ($batchIds as $subId) {
    $sub = $subMap[$subId] ?? null;

    if (!$sub || $sub['status'] !== '1') {
        $sentCount++;
        continue;
    }

    $unsubUrl    = Token::unsubscribeUrl($sub['token']);
    $body        = Mailer::replacePlaceholders($queue['body'], $sub);
    $queueHtml   = $queue['html_body'] ?? '';
    $htmlBodyOut = $queueHtml !== '' ? Mailer::replacePlaceholders($queueHtml, $sub) : '';

    // 開封トラッキング: フラグONかつHTML本文ありのとき、受信者ごとの
    // 1×1 透明ピクセルを末尾に付加する（テキストのみ送信には付かない）。
    if (!empty($queue['open_tracking']) && $htmlBodyOut !== '') {
        $trackUrl = SITE_URL . 'open.php?q=' . rawurlencode($queue['id'])
                  . '&t=' . rawurlencode($sub['token']);
        $htmlBodyOut .= '<img src="' . htmlspecialchars($trackUrl, ENT_QUOTES, 'UTF-8')
                      . '" width="1" height="1" alt="" style="display:none;border:0;">';
    }

    $result      = $mailer->send($sub['email'], $queue['subject'], $body, $htmlBodyOut, $unsubUrl);

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

    // 逐次チェックポイント: QUEUE_CHECKPOINT_EVERY 通ごとに進捗を永続化。
    // これにより (a) 途中クラッシュ時も次回 cron が offset から再開でき、
    // (b) 再送される範囲が最大 QUEUE_CHECKPOINT_EVERY 通に限定される。
    if ($sentCount % QUEUE_CHECKPOINT_EVERY === 0) {
        $queue['status']        = 'sending';
        $queue['offset']        = $baseOffset + $sentCount;
        $queue['sent_count']    = $baseSent + $sentCount;
        $queue['success_count'] = $baseSuccess + $successCount;
        $queue['updated_at']    = date('Y-m-d H:i:s');
        FileDB::saveQueue($queue);
    }

    if ($sendInterval > 0) {
        usleep((int)($sendInterval * 1000000));
    }
}

// ---- キュー進捗の更新 -------------------------------------
// 注意: $queue['sent_count'] 等はチェックポイントで途中更新されているため、
// ループ開始前に控えた base 値を基準に最終値を算出する（二重計上の回避）。
$newOffset    = $baseOffset + $sentCount;
$totalSent    = $baseSent + $sentCount;
$totalSuccess = $baseSuccess + $successCount;
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
    $queue['updated_at']    = date('Y-m-d H:i:s');
    FileDB::saveQueue($queue);
    FileDB::updateHistory($queue['id'], [
        'success_count' => $totalSuccess,
    ]);
}

exit(0);
