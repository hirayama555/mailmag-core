<?php
declare(strict_types=1);

Auth::requireLogin();

$historyList = FileDB::getHistoryList();
$queues      = FileDB::getQueueList();

// キャンセル処理（POST + CSRF 必須。ヘッダー出力前に実施）
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'cancel'
    && !empty($_POST['id'])
) {
    if (!Token::verifyCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('不正なリクエストです。');
    }
    $cancelId = $_POST['id'];
    $q = FileDB::getQueue($cancelId);
    if ($q && $q['status'] === 'reserved') {
        FileDB::deleteQueue($cancelId);
        FileDB::updateHistory($cancelId, ['status' => 'cancelled', 'finished_at' => date('Y-m-d H:i:s')]);
    }
    header('Location: ' . SITE_URL . 'history.php?msg=cancel_ok');
    exit;
}

$msg = $_GET['msg'] ?? '';

$statusMap  = [
    'done'     => ['label' => '完了',     'badge' => 'badge-success'],
    'sending'  => ['label' => '送信中',   'badge' => 'badge-warn'],
    'reserved' => ['label' => '予約中',   'badge' => 'badge-primary'],
    'failed'   => ['label' => 'エラー',   'badge' => 'badge-danger'],
];

$pageTitle = '送信履歴';
$activeNav = 'history';
require_once CORE_INCLUDES_DIR . '/header.php';
?>

<?php if ($msg === 'queued'): ?>
    <div class="alert alert-success">送信キューに追加しました。CRONが順次送信します。</div>
<?php elseif ($msg === 'reserved'): ?>
    <div class="alert alert-success">予約送信を設定しました。指定日時にCRONが送信を開始します。</div>
<?php elseif ($msg === 'cancel_ok'): ?>
    <div class="alert alert-success">予約送信をキャンセルしました。</div>
<?php endif; ?>

<?php if ($queues): ?>
<div class="card mb-4">
    <div class="card-header"><h2>&#9201; 送信中・予約中</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>状態</th><th>件名</th><th>対象</th><th>送信済</th><th>予定日時</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($queues as $q):
                    $info = $statusMap[$q['status']] ?? ['label' => $q['status'], 'badge' => 'badge-muted'];
                    $progress = $q['total_count'] > 0
                        ? round($q['sent_count'] / $q['total_count'] * 100)
                        : 0;
                ?>
                <tr>
                    <td><span class="badge <?= $info['badge'] ?>"><?= $info['label'] ?></span></td>
                    <td><?= htmlspecialchars($q['subject'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= number_format($q['total_count']) ?>件</td>
                    <td>
                        <?= number_format($q['sent_count']) ?>件
                        <?php if ($q['status'] === 'sending'): ?>
                            <div class="progress-wrap mt-1">
                                <div class="progress-bar" style="width:<?= $progress ?>%"></div>
                            </div>
                            <span class="text-muted" style="font-size:11px;"><?= $progress ?>%</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;"><?= htmlspecialchars($q['scheduled_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ($q['status'] === 'reserved'): ?>
                            <!-- キャンセルは POST + CSRF（GETによるCSRF防止）-->
                            <form method="post" action="<?= SITE_URL ?>history.php" style="margin:0;display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= Token::getCsrf() ?>">
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($q['id'], ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-danger btn-sm"
                                        onclick="return confirm('予約をキャンセルしますか？')">キャンセル</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>送信履歴（<?= count($historyList) ?>件）</h2>
    </div>
    <div class="table-wrap">
        <?php if (empty($historyList)): ?>
            <p class="text-muted text-center" style="padding:32px;">送信履歴はありません</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>送信日時</th>
                        <th>件名</th>
                        <th>対象数</th>
                        <th>送信成功</th>
                        <th>状態</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historyList as $h):
                        $info = $statusMap[$h['status']] ?? ['label' => $h['status'], 'badge' => 'badge-muted'];
                    ?>
                    <tr>
                        <td style="font-size:12px;white-space:nowrap;">
                            <?= htmlspecialchars(substr($h['sent_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <a href="<?= SITE_URL ?>history_detail.php?id=<?= urlencode($h['id']) ?>">
                                <?= htmlspecialchars($h['subject'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </td>
                        <td><?= number_format((int)($h['total_count'] ?? 0)) ?></td>
                        <td><?= number_format((int)($h['success_count'] ?? 0)) ?></td>
                        <td><span class="badge <?= $info['badge'] ?>"><?= $info['label'] ?></span></td>
                        <td>
                            <a href="<?= SITE_URL ?>send.php?history_id=<?= urlencode($h['id']) ?>"
                               class="btn btn-ghost btn-sm">再送</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once CORE_INCLUDES_DIR . '/footer.php'; ?>
