<?php
declare(strict_types=1);

// ============================================================
// core/app/cron_update.php
//
// クライアント側 cron_update.php 薄シェルから呼ばれるディスパッチ先。
// または cron_queue.php からも内部的に呼ばれる（1日1回相乗り）。
//
// 役割:
//   1. CLI 専用ガード
//   2. 排他ロック取得（同時実行による zip 展開競合の防止）
//   3. Updater::checkAndApply() を起動して結果を stdout/log へ出力
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

set_time_limit(120);

// ロック取得: 5分 cron と日次 cron が偶然同時刻に走った場合の重複を防ぐ
if (!Lock::acquire('cron_update')) {
    echo '[' . date('Y-m-d H:i:s') . "] Another cron_update is running. Skipping." . PHP_EOL;
    exit(0);
}

$result = Updater::checkAndApply();

$now = date('Y-m-d H:i:s');
echo "[{$now}] {$result['status']}: {$result['message']}";
if (isset($result['version'])) {
    echo " (new version: {$result['version']})";
}
echo PHP_EOL;

// 結果は Updater 内で update.log に追記済み。
exit($result['status'] === 'error' ? 1 : 0);
