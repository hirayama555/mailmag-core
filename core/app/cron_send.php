<?php
declare(strict_types=1);

// ============================================================
// core/app/cron_send.php - 予約送信チェック（毎分実行）
//
// 役割:
//   - QUEUE_DIR内の status='reserved' を確認
//   - scheduled_at <= 現在時刻 なら status を 'pending' に変更
//   - 履歴レコードも 'sending' に更新
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$now   = date('Y-m-d H:i:s');
$count = 0;

// ---- [1] 予約キュー: scheduled_at <= 現在時刻 なら pending に昇格 ----
$queues = FileDB::getQueueList();
foreach ($queues as $q) {
    if ($q['status'] !== 'reserved') continue;

    if (($q['scheduled_at'] ?? '') <= $now) {
        $q['status'] = 'pending';
        FileDB::saveQueue($q);
        FileDB::updateHistory($q['id'], ['status' => 'sending']);
        $count++;
        echo "[{$now}] Reserved → Pending: {$q['id']} (subject: {$q['subject']})" . PHP_EOL;
    }
}

if ($count === 0) {
    echo "[{$now}] No reserved queues to activate." . PHP_EOL;
}

// ---- [2] 期限切れ pending エントリの削除（48h 超過分）--------------
// register.php / register_mail.php が追加した未確認エントリを定期清掃する。
// 放置すると pending.csv が肥大化し O(N) 検索コストが増加する。
$expired  = 0;
$pending  = FileDB::getPending();
$cutoff   = time() - (48 * 3600);

foreach ($pending as $p) {
    if (strtotime($p['created_at']) < $cutoff) {
        FileDB::deletePending($p['token']);
        $expired++;
    }
}

if ($expired > 0) {
    echo "[{$now}] Expired pending entries removed: {$expired}" . PHP_EOL;
}

exit(0);
