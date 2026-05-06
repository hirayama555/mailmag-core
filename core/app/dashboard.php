<?php
declare(strict_types=1);

Auth::requireLogin();

$counts   = FileDB::countByStatus();
$history  = FileDB::getHistoryList();
$queues   = FileDB::getQueueList();

// 直近5件の履歴
$recentHistory = array_slice($history, 0, 5);

// 今月の送信数
$thisMonth  = date('Y-m');
$monthCount = 0;
foreach ($history as $h) {
    if (strpos($h['sent_at'], $thisMonth) === 0 && $h['status'] === 'done') {
        $monthCount += (int)($h['success_count'] ?? 0);
    }
}

$pageTitle = 'ダッシュボード';
$activeNav = 'dashboard';
require_once CORE_INCLUDES_DIR . '/header.php';
?>

<div class="stats-grid">
    <div class="stat-card primary">
        <div class="label">有効購読者数</div>
        <div class="value"><?= number_format($counts['active']) ?></div>
        <div class="sub">配信対象</div>
    </div>
    <div class="stat-card">
        <div class="label">総登録者数</div>
        <div class="value"><?= number_format($counts['total']) ?></div>
        <div class="sub">購読解除・停止含む</div>
    </div>
    <div class="stat-card danger">
        <div class="label">エラー停止</div>
        <div class="value"><?= number_format($counts['stopped']) ?></div>
        <div class="sub">バウンス等</div>
    </div>
    <div class="stat-card success">
        <div class="label">今月の送信通数</div>
        <div class="value"><?= number_format($monthCount) ?></div>
        <div class="sub"><?= date('Y年n月') ?></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

    <!-- 予約送信キュー -->
    <div class="card">
        <div class="card-header">
            <h2>&#9201; 予約送信キュー</h2>
            <a href="<?= SITE_URL ?>send.php" class="btn btn-primary btn-sm">新規作成</a>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($queues)): ?>
                <p class="text-muted text-center" style="padding:24px;">予約送信はありません</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr><th>送信予定日時</th><th>件名</th><th>対象</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($queues as $q): ?>
                        <tr>
                            <td style="white-space:nowrap;font-size:12px;">
                                <?= htmlspecialchars($q['scheduled_at'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="truncate" style="max-width:160px;">
                                <?= htmlspecialchars($q['subject'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td><?= number_format((int)($q['total_count'] ?? 0)) ?>件</td>
                            <td>
                                <span class="badge badge-primary">予約中</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- 最近の送信履歴 -->
    <div class="card">
        <div class="card-header">
            <h2>&#9776; 最近の送信</h2>
            <a href="<?= SITE_URL ?>history.php" class="btn btn-ghost btn-sm">すべて見る</a>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($recentHistory)): ?>
                <p class="text-muted text-center" style="padding:24px;">送信履歴はありません</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr><th>送信日時</th><th>件名</th><th>送信数</th><th>状態</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentHistory as $h): ?>
                        <tr>
                            <td style="white-space:nowrap;font-size:12px;">
                                <?= htmlspecialchars(substr($h['sent_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="truncate" style="max-width:160px;">
                                <a href="<?= SITE_URL ?>history_detail.php?id=<?= urlencode($h['id']) ?>">
                                    <?= htmlspecialchars($h['subject'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </td>
                            <td><?= number_format((int)($h['success_count'] ?? 0)) ?></td>
                            <td>
                                <?php if ($h['status'] === 'done'): ?>
                                    <span class="badge badge-success">完了</span>
                                <?php elseif ($h['status'] === 'sending'): ?>
                                    <span class="badge badge-warn">送信中</span>
                                <?php else: ?>
                                    <span class="badge badge-muted"><?= htmlspecialchars($h['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- クイックアクション -->
<div class="card mt-4">
    <div class="card-header"><h2>クイックアクション</h2></div>
    <div class="card-body flex gap-3">
        <a href="<?= SITE_URL ?>send.php" class="btn btn-primary">&#9993; メール送信</a>
        <a href="<?= SITE_URL ?>subscriber_add.php" class="btn btn-outline">&#43; 購読者追加</a>
        <a href="<?= SITE_URL ?>subscribers.php?csv=1" class="btn btn-ghost">&#11123; CSVダウンロード</a>
        <a href="<?= SITE_URL ?>settings.php" class="btn btn-ghost">&#9881; 設定</a>
    </div>
</div>

<?php require_once CORE_INCLUDES_DIR . '/footer.php'; ?>
